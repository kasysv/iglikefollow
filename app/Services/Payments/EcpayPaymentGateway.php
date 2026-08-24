<?php

namespace App\Services\Payments;

use App\Actions\Orders\MarkPaymentPending;
use App\Actions\Payments\FailPaymentInitiation;
use App\Contracts\PaymentGateway;
use App\DTO\PaymentInitiation;
use App\Enums\IntegrationProvider;
use App\Enums\PaymentFailureReason;
use App\Models\IntegrationSetting;
use App\Models\PaymentAttempt;
use App\Services\Integrations\LiveIntegration;
use App\Services\Integrations\ProviderEndpoints;

/**
 * ECPay AioCheckOut V5.
 *
 * ECPay is a browser hand-off: the server builds a fixed set of fields, signs
 * them, and the customer's browser POSTs them to ECPay's cashier. The result
 * comes back later, server to server, at the ReturnURL — ⛔ the browser's own
 * return proves nothing and is treated as decoration.
 *
 * The endpoint comes from a version-controlled allowlist, never from the
 * database. A URL an admin can type is a URL this server would post a signed
 * payload to.
 *
 * ⛔ M4C:讀 Owner 在後台維護的唯一一套正式 credential。sandbox row 就算存在
 * 也不會被讀到——「跟著環境選 row」只會在某天變成用測試金鑰收真錢,或反過來。
 */
class EcpayPaymentGateway implements PaymentGateway
{
    public function __construct(
        private readonly FailPaymentInitiation $failInitiation,
        private readonly MarkPaymentPending $markPending,
    ) {}

    public function provider(): string
    {
        return 'ecpay';
    }

    public function initiate(PaymentAttempt $attempt): PaymentInitiation
    {
        /*
         * ⛔ 以下每一種情況都代表「確定沒有付款 session」，所以必須把已經
         * claim 的 attempt 收斂成 failed。
         *
         * 只回一個 failed initiation 是不夠的：attempt 會留在 pending，而
         * resolver 正確地擋下任何 pending，於是這張訂單再也付不了款——連換
         * 一家 provider 都不行。claim 一旦取得，就有責任放掉。
         *
         * ⛔ 這裡沒有一個獨立的「總開關」檢查:通道是否可用已經包含在
         * `setting()` 裡(環境＋Owner 開關＋credential 齊全)。分成兩個檢查
         * 就是兩份會各自漂移的規則。
         */
        $setting = $this->setting();

        if ($setting === null) {
            return $this->giveUp($attempt);
        }

        // ⛔ 端點必須與版本控制中的白名單完全一致,否則一個位元組都不送出。
        $endpoint = ProviderEndpoints::ecpayPayment();

        if ($endpoint === null) {
            return $this->giveUp($attempt);
        }

        $hashKey = $setting->secret('HashKey');
        $hashIv = $setting->secret('HashIV');

        if ($hashKey === null || $hashIv === null || blank($setting->identifier)) {
            return $this->giveUp($attempt);
        }

        /*
         * ⛔ MerchantTradeNo 必須是 1–20 字的純英數字,簽章與輸出 form 之前
         * 再驗一次。
         *
         * 這個值就是 `$attempt->reference`。新的 reference 由
         * `PaymentAttempt::newReference()` 產生,一定合法;這裡擋的是 legacy
         * (`PAY-…` 帶連字號,staging 已被綠界 `10200031` 實際拒絕)或任何
         * 人工/異常寫入的資料。把不合法的值簽了章送出去,結果是客人到了
         * 綠界頁面才看到一個看不懂的錯誤——fail closed 在這裡,走同一個
         * `giveUp()`:確定沒有付款 session、收斂 failed、釋放 claim,客人
         * 可以立即重試(新 checkout 會拿到合法的新 reference)。
         */
        if (! self::isValidMerchantTradeNo($attempt->reference)) {
            return $this->giveUp($attempt);
        }

        $fields = $this->fieldsFor($attempt, (string) $setting->identifier);

        // 簽章在最後一步加上，⛔ 涵蓋所有其他欄位。
        $fields['CheckMacValue'] = EcpayCheckMac::generate($fields, $hashKey, $hashIv);

        /*
         * 訂單的付款狀態要跟著這筆嘗試走。
         *
         * ⛔ 否則會出現 attempt=pending 但 order=initiated 的不一致：後台看到
         * 的訂單狀態，和實際上正在進行的付款對不上。
         */
        $this->markPending->handle($attempt);

        return PaymentInitiation::formPost($endpoint, $fields);
    }

    /**
     * 綠界 AioCheckOut V5 的 `MerchantTradeNo` 規格:1–20 字、純英數字。
     *
     * ⛔ 整條 regex 錨定 `\A…\z`,不用 `^…$`(後者容忍結尾換行)。官方規格
     * 是 String(20)、僅允許數字與英文字母;staging 實測連字號會被
     * `10200031` 拒絕——被拒代表該次根本沒有建立綠界交易。
     */
    public static function isValidMerchantTradeNo(string $reference): bool
    {
        return preg_match('/\A[A-Za-z0-9]{1,20}\z/', $reference) === 1;
    }

    /**
     * Release the claim: this initiation is definitely not happening.
     *
     * ⛔ Safe only because nothing was sent — no ECPay request is made until the
     * customer's browser posts the form, so there is no session to reconcile.
     */
    private function giveUp(PaymentAttempt $attempt): PaymentInitiation
    {
        $this->failInitiation->handle($attempt, PaymentFailureReason::ProviderUnavailable);

        return PaymentInitiation::failed(PaymentFailureReason::ProviderUnavailable);
    }

    /**
     * The exact fields ECPay expects, and nothing else.
     *
     * ⛔ An allowlist, not a merge of whatever is lying around: every field
     * here is signed, so an extra one carrying customer data would be sent to
     * a third party and stamped as ours.
     *
     * @return array<string, string>
     */
    private function fieldsFor(PaymentAttempt $attempt, string $merchantId): array
    {
        $order = $attempt->order;

        return [
            'MerchantID' => $merchantId,
            // 我們自己的付款嘗試編號；ECPay 要求全站唯一。
            'MerchantTradeNo' => $attempt->reference,
            'MerchantTradeDate' => $attempt->created_at->format('Y/m/d H:i:s'),
            'PaymentType' => 'aio',
            // ⛔ 整數台幣，取自伺服器端重算的金額，不接受任何前端數值。
            'TotalAmount' => (string) (int) $attempt->amount,
            'TradeDesc' => 'IGLIKEFOLLOW social media service',
            // ⛔ 商品名稱用固定安全字串，不帶客人輸入的帳號或網址。
            'ItemName' => 'Social media service',
            'ReturnURL' => route('payments.ecpay.callback'),
            'ClientBackURL' => route('payments.status', ['reference' => $order->reference]),
            'ChoosePayment' => 'Credit',
            'EncryptType' => '1',
        ];
    }

    /**
     * Owner 維護的唯一一套正式設定;⛔ 環境或開關任一不成立即 null。
     *
     * 這一個方法同時涵蓋:這台機器可以外呼、Owner 已開啟綠界付款、
     * MerchantID 與兩個 secret 都齊全。任一不成立就沒有付款 session。
     */
    private function setting(): ?IntegrationSetting
    {
        return LiveIntegration::setting(IntegrationProvider::EcpayPayment);
    }
}
