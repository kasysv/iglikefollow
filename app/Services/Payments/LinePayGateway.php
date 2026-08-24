<?php

namespace App\Services\Payments;

use App\Actions\Orders\MarkPaymentPending;
use App\Actions\Orders\MarkPaymentUncertain;
use App\Actions\Payments\FailPaymentInitiation;
use App\Contracts\PaymentGateway;
use App\DTO\PaymentInitiation;
use App\Enums\IntegrationProvider;
use App\Enums\PaymentFailureReason;
use App\Models\PaymentAttempt;
use App\Services\Integrations\LiveIntegration;
use App\Services\Integrations\ProviderEndpoints;

/**
 * LINE Pay Online API v4.
 *
 * Unlike ECPay this starts with a server-side call: we ask LINE Pay to create
 * a payment, they answer with a transaction id and a URL to send the customer
 * to. Only the transaction id is worth keeping — it is what the later confirm
 * call is made against.
 *
 * ⛔ 導向網址的白名單放在 `ProviderEndpoints`,與端點白名單同一個檔案:兩者
 * 都在回答「這台伺服器可以把東西交給誰」,分散在兩處就會有一個被忘記更新。
 */
class LinePayGateway implements PaymentGateway
{
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
        /*
         * ⛔ adapter 自己也要擋：直接從 container 取出時不會經過 registry。
         *
         * 這裡問的是完整的通道可用性(環境＋Owner 開關＋credential 齊全),
         * 與 client 內同一個判斷同源;⛔ 不是一個只看環境的近似檢查。
         */
        if (LiveIntegration::setting(IntegrationProvider::LinePay) === null) {
            /*
             * ⛔ 必須把已 claim 的 attempt 收斂成 failed,不能只回一個 failed
             * initiation。
             *
             * 留在 pending 的 attempt 會被 resolver 正確地擋下,於是這張訂單
             * 再也付不了款——連換一家 provider 都不行。claim 一旦取得,就有
             * 責任放掉。
             *
             * ⛔ 安全的前提是「一個 request 都還沒送出」:通道不可用時我們連
             * 撥號都沒撥,對方那邊什麼都沒發生,所以判定為確定失敗是正確的。
             */
            $this->failInitiation->handle($attempt, PaymentFailureReason::ProviderUnavailable);

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

    /**
     * ⛔ 只接受 HTTPS 與白名單主機，不接受任意 provider 回傳的網址。
     *
     * 判斷本身在 `ProviderEndpoints`:那裡也擋掉 userinfo 與自訂 port,
     * 因為 `https://web-pay.line.me@evil.example/` 的真正主機是後者,
     * 而只看字串前綴的寫法會被它騙過去。
     */
    private function redirectIsAllowed(string $url): bool
    {
        return ProviderEndpoints::redirectIsAllowed($url);
    }
}
