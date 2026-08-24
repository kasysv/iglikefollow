<?php

namespace App\Http\Controllers\Payments;

use App\Actions\Orders\MarkPaymentUncertain;
use App\Actions\Orders\RecordPaymentResult;
use App\Enums\IntegrationProvider;
use App\Enums\PaymentFailureReason;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\IntegrationSetting;
use App\Models\PaymentAttempt;
use App\Services\Integrations\LiveIntegration;
use App\Services\Payments\EcpayCheckMac;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * ECPay's server-to-server payment result.
 *
 * This is the only thing that may mark an ECPay order paid — not the customer's
 * browser coming back, which anyone can forge by typing a URL.
 *
 * Every check below has to pass before a single row is written:
 *
 *   the merchant is ours → the trade number matches a real attempt →
 *   the amount matches what we asked for → the MAC verifies →
 *   the return code says paid
 *
 * ⛔ The response body is `1|OK` on success. ECPay retries a callback it
 * considers unacknowledged, so a verification failure deliberately answers
 * something else, and a duplicate valid callback answers `1|OK` again without
 * writing anything twice.
 */
class EcpayCallbackController extends Controller
{
    public function __construct(
        private readonly RecordPaymentResult $recordPayment,
        private readonly MarkPaymentUncertain $markUncertain,
    ) {}

    public function __invoke(Request $request): Response
    {
        /*
         * ⛔ 公開路由，不經過 registry：這裡必須自己判斷通道是否可用，
         * 否則整條 callback 就是繞過 Owner 開關的後門。
         *
         * `setting()` 一次涵蓋:環境可外呼、Owner 已開啟綠界付款、
         * MerchantID 與兩個 secret 齊全。⛔ 沒有第二個「總開關」檢查——
         * 兩份規則就是兩份會各自漂移的規則。
         */
        $payload = $request->all();

        $setting = $this->setting();

        if ($setting === null) {
            return $this->reject();
        }

        $hashKey = $setting->secret('HashKey');
        $hashIv = $setting->secret('HashIV');

        if ($hashKey === null || $hashIv === null) {
            return $this->reject();
        }

        // 1. 商店代號必須是我們自己的。
        if ((string) ($payload['MerchantID'] ?? '') !== (string) $setting->identifier) {
            return $this->reject();
        }

        // 2. 交易編號必須對應一筆真實存在的付款嘗試。
        //    ⛔ 唯讀查詢，這一步不寫入任何東西。
        $attempt = PaymentAttempt::query()
            ->where('provider', 'ecpay')
            ->where('reference', (string) ($payload['MerchantTradeNo'] ?? ''))
            ->first();

        if ($attempt === null) {
            return $this->reject();
        }

        /*
         * 3. 簽章必須先通過，而且要在「任何寫入之前」。
         *
         * ⛔ 順序是安全性的一部分，不是風格問題。先比金額再驗簽的話，知道
         * attempt reference 的人只要送出錯誤金額＋垃圾簽章，就能把這筆嘗試
         * 推進 reconciliation_required；客人真正的付款結果隨後抵達時，該
         * 嘗試已經不是 open，於是永遠完成不了。那是一種用「寫入我們根本
         * 不該相信的請求」達成的阻斷服務。
         *
         * constant-time 比對：字串比較會在第一個不同的位元組短路，洩漏
         * 「猜對了幾個字元」。
         */
        if (! EcpayCheckMac::matches($payload, $hashKey, $hashIv, $payload['CheckMacValue'] ?? null)) {
            return $this->reject();
        }

        // ── 以下都是「已確認來自綠界」的資料，才可以開始寫入 ──

        /*
         * 4. SimulatePaid 先處理，⛔ 在任何金額比對或寫入之前。
         *
         * 這個旗標代表綠界只是在測回呼，沒有任何金流發生，所以它的 payload
         * 本來就不必和真實訂單對得上。若先比金額，一個測試用的回呼就會把
         * 正常的付款嘗試推進待對帳——用測試功能弄壞真的訂單。
         *
         * 仍然要回 1|OK，否則綠界會持續重送。
         */
        if ((string) ($payload['SimulatePaid'] ?? '0') === '1') {
            Log::info('ECPay sandbox SimulatePaid callback received.', [
                // ⛔ 只記自己的參考碼，不記 raw payload 或任何個資。
                'attempt_reference' => $attempt->reference,
            ]);

            return $this->acknowledge();
        }

        // 5. 金額必須等於我們自己算出來的整數台幣。
        //    ⛔ 不信任回傳金額：這是防止被改價的最後一道。
        if ((string) ($payload['TradeAmt'] ?? '') !== (string) (int) $attempt->amount) {
            $this->markUncertain->handle($attempt, PaymentFailureReason::AmountMismatch);

            return $this->reject();
        }

        $this->applyResult($attempt, $payload);

        return $this->acknowledge();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function applyResult(PaymentAttempt $attempt, array $payload): void
    {
        /*
         * 已經有結果的嘗試不再改寫；重複通知在這裡就停下來。
         *
         * 待對帳是例外：它表示「結果不明」，而這裡的資料已經通過驗簽，
         * 正是它在等的答案。⛔ 已成功的嘗試永遠不會走到這裡被降級。
         */
        if (! $attempt->status->isOpen() && ! $attempt->status->needsReconciliation()) {
            return;
        }

        $code = (string) ($payload['RtnCode'] ?? '');
        $tradeNo = (string) ($payload['TradeNo'] ?? '');

        if ($code === '1') {
            /*
             * ⛔ 成功必須帶著對方的交易編號。
             *
             * 沒有它，日後要跟綠界對這筆帳就只剩我們自己的參考碼——退款、
             * 爭議與對帳都少了唯一能連起兩邊的鍵。「成功但不知道是哪一筆」
             * 不是成功，是需要人看的狀況。
             */
            if ($tradeNo === '') {
                $this->markUncertain->handle($attempt, PaymentFailureReason::UnreadableResponse);

                return;
            }

            $this->recordPayment->handle(
                $attempt,
                PaymentStatus::Succeeded,
                providerReference: $tradeNo,
            );

            return;
        }

        // ⛔ 不把 RtnMsg 存下來：那是對方的自由文字，常回音請求內容。
        // 無法歸類的代碼一律進人工對帳，不當成確定失敗。
        $reason = match ($code) {
            '10100058', '10100059' => PaymentFailureReason::Declined,
            '' => PaymentFailureReason::UnreadableResponse,
            default => PaymentFailureReason::Unknown,
        };

        if ($reason->isUncertain()) {
            $this->markUncertain->handle($attempt, $reason);

            return;
        }

        $this->recordPayment->handle(
            $attempt,
            PaymentStatus::Failed,
            providerReference: $tradeNo !== '' ? $tradeNo : null,
            failureCode: $reason->value,
            failureMessage: $reason->message(),
        );
    }

    /**
     * Owner 維護的唯一一套正式設定;⛔ 環境或開關任一不成立即 null。
     *
     * ⛔ 這與 `EcpayPaymentGateway` 讀的是同一個來源。callback 若讀另一組
     * credential,驗簽就會用錯的 HashKey——正確的付款結果反而被拒。
     */
    private function setting(): ?IntegrationSetting
    {
        return LiveIntegration::setting(IntegrationProvider::EcpayPayment);
    }

    /** 綠界要求成功時回應純文字 `1|OK`。 */
    private function acknowledge(): Response
    {
        return response('1|OK', 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    /** ⛔ 驗證失敗：不寫入任何資料，也不回 1|OK。 */
    private function reject(): Response
    {
        return response('0|ERROR', 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
