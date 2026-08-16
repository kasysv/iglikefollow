<?php

namespace App\Services\Payments;

use App\Actions\Orders\MarkPaymentPending;
use App\Actions\Payments\FailPaymentInitiation;
use App\Contracts\PaymentGateway;
use App\DTO\PaymentInitiation;
use App\Enums\IntegrationEnvironment;
use App\Enums\IntegrationProvider;
use App\Enums\PaymentFailureReason;
use App\Models\IntegrationSetting;
use App\Models\PaymentAttempt;

/**
 * ECPay AioCheckOut V5, stage environment.
 *
 * ECPay is a browser hand-off: the server builds a fixed set of fields, signs
 * them, and the customer's browser POSTs them to ECPay's cashier. The result
 * comes back later, server to server, at the ReturnURL — ⛔ the browser's own
 * return proves nothing and is treated as decoration.
 *
 * The endpoint is read from config, never from the database. A URL an admin
 * can type is a URL this server would post a signed payload to.
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
        // ⛔ 同樣的檢查放在 adapter 自己身上：有人直接從 container 取出這個
        // 類別時，registry 那道防線根本不會被執行到。
        if (! SandboxGuard::enabled()) {
            return PaymentInitiation::failed(PaymentFailureReason::ProviderUnavailable);
        }

        /*
         * ⛔ 以下每一種情況都代表「確定沒有付款 session」，所以必須把已經
         * claim 的 attempt 收斂成 failed。
         *
         * 只回一個 failed initiation 是不夠的：attempt 會留在 pending，而
         * resolver 正確地擋下任何 pending，於是這張訂單再也付不了款——連換
         * 一家 provider 都不行。claim 一旦取得，就有責任放掉。
         */
        $setting = $this->setting();

        if ($setting === null) {
            return $this->giveUp($attempt);
        }

        $endpoint = (string) config('integrations.endpoints.ecpay_payment.sandbox');

        if ($endpoint === '') {
            return $this->giveUp($attempt);
        }

        $hashKey = $setting->secret('HashKey');
        $hashIv = $setting->secret('HashIV');

        if ($hashKey === null || $hashIv === null || blank($setting->identifier)) {
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

    /** 目前僅 sandbox；⛔ production 需另一次明確批准。 */
    private function setting(): ?IntegrationSetting
    {
        $setting = IntegrationSetting::query()
            ->where('provider', IntegrationProvider::EcpayPayment)
            ->where('environment', IntegrationEnvironment::Sandbox)
            ->first();

        return $setting?->isUsable() ? $setting : null;
    }
}
