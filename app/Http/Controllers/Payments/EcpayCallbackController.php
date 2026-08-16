<?php

namespace App\Http\Controllers\Payments;

use App\Actions\Orders\MarkPaymentUncertain;
use App\Actions\Orders\RecordPaymentResult;
use App\Enums\IntegrationEnvironment;
use App\Enums\IntegrationProvider;
use App\Enums\PaymentFailureReason;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\IntegrationSetting;
use App\Models\PaymentAttempt;
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
        $attempt = PaymentAttempt::query()
            ->where('provider', 'ecpay')
            ->where('reference', (string) ($payload['MerchantTradeNo'] ?? ''))
            ->first();

        if ($attempt === null) {
            return $this->reject();
        }

        // 3. 金額必須等於我們自己算出來的整數台幣。
        //    ⛔ 不信任回傳金額：這是防止被改價的最後一道。
        if ((string) ($payload['TradeAmt'] ?? '') !== (string) (int) $attempt->amount) {
            $this->markUncertain->handle($attempt, PaymentFailureReason::AmountMismatch);

            return $this->reject();
        }

        // 4. 簽章必須通過，且以 constant-time 比對。
        if (! EcpayCheckMac::matches($payload, $hashKey, $hashIv, $payload['CheckMacValue'] ?? null)) {
            return $this->reject();
        }

        // 5. SimulatePaid 是綠界的「只測回呼」旗標，沒有任何金流發生。
        //    ⛔ 絕不可據此標記已付款、發出 OrderPaid、開發票或履約；
        //    但仍要回 1|OK，否則綠界會持續重送。
        if ((string) ($payload['SimulatePaid'] ?? '0') === '1') {
            Log::info('ECPay sandbox SimulatePaid callback received.', [
                // ⛔ 只記自己的參考碼，不記 raw payload 或任何個資。
                'attempt_reference' => $attempt->reference,
            ]);

            return $this->acknowledge();
        }

        $this->applyResult($attempt, $payload);

        return $this->acknowledge();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function applyResult(PaymentAttempt $attempt, array $payload): void
    {
        // 已經有結果的嘗試不再改寫；重複通知在這裡就停下來。
        if (! $attempt->status->isOpen()) {
            return;
        }

        $code = (string) ($payload['RtnCode'] ?? '');
        $tradeNo = (string) ($payload['TradeNo'] ?? '');

        if ($code === '1') {
            $this->recordPayment->handle(
                $attempt,
                PaymentStatus::Succeeded,
                providerReference: $tradeNo !== '' ? $tradeNo : null,
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

    private function setting(): ?IntegrationSetting
    {
        $setting = IntegrationSetting::query()
            ->where('provider', IntegrationProvider::EcpayPayment)
            ->where('environment', IntegrationEnvironment::Sandbox)
            ->first();

        return $setting?->isUsable() ? $setting : null;
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
