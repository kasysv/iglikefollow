<?php

namespace App\Services\Payments;

use App\Actions\Orders\MarkPaymentPending;
use App\Contracts\PaymentGateway;
use App\DTO\PaymentInitiation;
use App\Enums\PaymentFailureReason;
use App\Models\PaymentAttempt;

/**
 * LINE Pay Online API v4, sandbox.
 *
 * Unlike ECPay this starts with a server-side call: we ask LINE Pay to create
 * a payment, they answer with a transaction id and a URL to send the customer
 * to. Only the transaction id is worth keeping — it is what the later confirm
 * call is made against.
 */
class LinePayGateway implements PaymentGateway
{
    /**
     * Hosts a payment redirect may point at.
     *
     * ⛔ An allowlist, because this URL comes from an HTTP response and we send
     * a paying customer to it. Without this check a compromised or spoofed
     * response turns our checkout into an open redirect — one that arrives
     * exactly when the customer is expecting to type card details.
     */
    private const ALLOWED_REDIRECT_HOSTS = [
        'sandbox-web-pay.line.me',
        'web-pay.line.me',
        'pay.line.me',
        'sandbox-api-pay.line.me',
    ];

    public function __construct(
        private readonly LinePayClient $client,
        private readonly MarkPaymentPending $markPending,
    ) {}

    public function provider(): string
    {
        return 'line-pay';
    }

    public function initiate(PaymentAttempt $attempt): PaymentInitiation
    {
        $order = $attempt->order;

        // ⛔ 全部取自伺服器端快照：金額、幣別、訂單編號都不來自請求。
        $response = $this->client->requestPayment([
            'amount' => (int) $attempt->amount,
            'currency' => $attempt->currency ?: 'TWD',
            'orderId' => $attempt->reference,
            'packages' => [[
                'id' => $attempt->reference,
                'amount' => (int) $attempt->amount,
                // ⛔ 固定安全名稱，不帶客人的帳號或網址。
                'name' => 'IGLIKEFOLLOW',
                'products' => [[
                    'name' => 'Social media service',
                    'quantity' => 1,
                    'price' => (int) $attempt->amount,
                ]],
            ]],
            'redirectUrls' => [
                'confirmUrl' => route('payments.linepay.confirm', ['reference' => $order->reference]),
                'cancelUrl' => route('payments.linepay.cancel', ['reference' => $order->reference]),
            ],
        ]);

        if (! $response->isSuccess()) {
            return PaymentInitiation::failed($response->reason());
        }

        $url = $response->paymentUrl;

        if ($url === null || ! $this->redirectIsAllowed($url)) {
            // ⛔ 拿到不該去的網址就當作驗證失敗，不把客人送過去。
            return PaymentInitiation::failed(PaymentFailureReason::VerificationFailed);
        }

        if ($response->transactionId === null) {
            return PaymentInitiation::failed(PaymentFailureReason::UnreadableResponse);
        }

        // 記下 transaction id：稍後 confirm 就是對著它做的。
        $this->markPending->handle($attempt, $response->transactionId);

        return PaymentInitiation::redirect($url);
    }

    /** ⛔ 只接受 HTTPS 與白名單主機，不接受任意 provider 回傳的網址。 */
    private function redirectIsAllowed(string $url): bool
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https') {
            return false;
        }

        return in_array(strtolower($parts['host'] ?? ''), self::ALLOWED_REDIRECT_HOSTS, true);
    }
}
