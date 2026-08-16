<?php

namespace App\Services\Payments;

use App\Actions\Orders\MarkPaymentPending;
use App\Actions\Orders\MarkPaymentUncertain;
use App\Actions\Payments\FailPaymentInitiation;
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
     * The only host this sandbox adapter may send a customer to.
     *
     * ⛔ An allowlist, because this URL comes from an HTTP response and we send
     * a paying customer to it. Without the check a spoofed response turns
     * checkout into an open redirect — arriving exactly when the customer
     * expects to type card details.
     *
     * ⛔ Exactly one host, and it is the sandbox *payment page*. The production
     * hosts do not belong to this environment, and `sandbox-api-pay.line.me` is
     * the API endpoint, not somewhere a person should ever be sent. Listing
     * production here "for later" would mean a production redirect silently
     * passing a sandbox-only check the day someone flips an environment.
     */
    private const ALLOWED_REDIRECT_HOSTS = [
        'sandbox-web-pay.line.me',
    ];

    public function __construct(
        private readonly LinePayClient $client,
        private readonly MarkPaymentPending $markPending,
        private readonly MarkPaymentUncertain $markUncertain,
        private readonly FailPaymentInitiation $failInitiation,
    ) {}

    public function provider(): string
    {
        return 'line-pay';
    }

    public function initiate(PaymentAttempt $attempt): PaymentInitiation
    {
        // ⛔ adapter 自己也要擋：直接從 container 取出時不會經過 registry。
        if (! SandboxGuard::enabled()) {
            return PaymentInitiation::failed(PaymentFailureReason::ProviderUnavailable);
        }

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

        /*
         * ⛔ 「請求根本沒送出」與「送出了但不知道結果」必須分開處理。
         *
         * 沒送出（缺設定、缺憑證、缺 endpoint）代表對方那邊什麼都沒發生，
         * 記為本地失敗、允許客人換個方式再試是安全的。
         *
         * 一旦送出去了就不同：逾時、看不懂的回應、不認識的代碼——對方可能
         * 已經建立了一筆付款交易。此時把 attempt 留在可重送的狀態，等於允許
         * 對同一張訂單開出第二個付款 session。
         */
        if ($response->neverSent()) {
            // ⛔ 收斂成 failed 而不是留著 pending：claim 一旦取得就有責任放掉，
            // 否則 resolver 會永遠擋住這張訂單的下一次付款。
            $this->failInitiation->handle($attempt, $response->reason());

            return PaymentInitiation::failed($response->reason());
        }

        if (! $response->isSuccess()) {
            $reason = $response->reason();

            if ($reason->isUncertain()) {
                // 送出去了卻不知道結果：對方可能已建立交易。
                $this->markUncertain->handle($attempt, $reason);
            } else {
                // 對方明確拒絕，確定沒有付款 session，可以安全重試。
                $this->failInitiation->handle($attempt, $reason);
            }

            return PaymentInitiation::failed($reason);
        }

        $url = $response->paymentUrl;

        // 成功回應卻缺少 transactionId 或給了不該去的網址：對方狀態不明。
        // ⛔ 不能當成單純失敗，因為那筆交易可能真的已經建立了。
        if ($response->transactionId === null || $url === null || ! $this->redirectIsAllowed($url)) {
            $this->markUncertain->handle($attempt, PaymentFailureReason::UnreadableResponse);

            return PaymentInitiation::failed(PaymentFailureReason::VerificationFailed);
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
