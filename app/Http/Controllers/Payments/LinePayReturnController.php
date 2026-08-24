<?php

namespace App\Http\Controllers\Payments;

use App\Actions\Orders\MarkPaymentUncertain;
use App\Actions\Orders\RecordPaymentResult;
use App\DTO\LinePayResponse;
use App\Enums\IntegrationProvider;
use App\Enums\PaymentFailureReason;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Services\Integrations\LiveIntegration;
use App\Services\Payments\LinePayClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Where LINE Pay sends the customer back.
 *
 * ⛔ Arriving here is not proof of payment. The customer's browser reaches this
 * URL because LINE Pay told it to, but so would anyone who typed the address.
 * What settles it is the server-side confirm call below, made against the
 * transaction id we stored when the payment started, and checked against our
 * own amount and order reference.
 */
class LinePayReturnController extends Controller
{
    public function __construct(
        private readonly LinePayClient $client,
        private readonly RecordPaymentResult $recordPayment,
        private readonly MarkPaymentUncertain $markUncertain,
    ) {}

    public function confirm(Request $request, string $reference): RedirectResponse
    {
        $order = Order::where('reference', $reference)->firstOrFail();

        // ⛔ 公開路由，不經過 registry：關閉或 production 時 0 呼叫、0 寫入。
        if (LiveIntegration::setting(IntegrationProvider::LinePay) === null) {
            return $this->toStatus($order);
        }

        $attempt = $this->openAttemptFor($order);

        // 已經有結果就直接看訂單狀態；重複返回不重複確認。
        if ($attempt === null) {
            return $this->toStatus($order);
        }

        $transactionId = $attempt->provider_reference;

        if (blank($transactionId)) {
            $this->markUncertain->handle($attempt, PaymentFailureReason::UnreadableResponse);

            return $this->toStatus($order);
        }

        /*
         * ⛔ LINE Pay 導回時一定會帶 orderId 與 transactionId；沒有帶、或帶得
         * 不對，就不是它導回來的。
         *
         * 少了這道檢查，任何人只要知道訂單編號就能用一個 GET 觸發 confirm。
         * 那不只是多送一次請求：對方若回未就緒或未知代碼，這筆嘗試會被卡進
         * 待對帳，客人真正付款完成後就再也收斂不了。
         *
         * ⛔ query 的值只用來與本地已存值做精確比對，不拿來當金額、查詢條件
         * 或導向目標。
         */
        if (! $this->identityMatches($request, $attempt, (string) $transactionId)) {
            // ⛔ 不呼叫 provider、不寫入任何狀態。
            return $this->toStatus($order);
        }

        // ⛔ 用我們自己保存的金額與訂單編號去確認，不採信任何 query 參數。
        $response = $this->client->confirmPayment((string) $transactionId, [
            'amount' => (int) $attempt->amount,
            'currency' => $attempt->currency ?: 'TWD',
        ]);

        if ($response->isUncertain()) {
            // 逾時或無法解析：⛔ 錢可能已經扣了，不得記為失敗，也不重送。
            $this->markUncertain->handle($attempt, $response->reason());

            return $this->toStatus($order);
        }

        if (! $response->isSuccess()) {
            $reason = $response->reason();

            if ($reason->isUncertain()) {
                $this->markUncertain->handle($attempt, $reason);

                return $this->toStatus($order);
            }

            $this->recordPayment->handle(
                $attempt,
                PaymentStatus::Failed,
                failureCode: $reason->value,
                failureMessage: $reason->message(),
            );

            return $this->toStatus($order);
        }

        // 成功回應仍要逐項核對；⛔ returnCode 0000 本身不足以採信。
        if (! $this->responseMatchesAttempt($response, $attempt)) {
            $this->markUncertain->handle($attempt, PaymentFailureReason::AmountMismatch);

            return $this->toStatus($order);
        }

        $this->recordPayment->handle(
            $attempt,
            PaymentStatus::Succeeded,
            providerReference: (string) $transactionId,
        );

        return $this->toStatus($order);
    }

    /**
     * The customer backed out on LINE Pay's page.
     *
     * ⛔ Only an attempt that is still open may be cancelled: a payment that
     * already succeeded must not be downgraded by a stray cancel URL.
     */
    public function cancel(Request $request, string $reference): RedirectResponse
    {
        $order = Order::where('reference', $reference)->firstOrFail();

        if (LiveIntegration::setting(IntegrationProvider::LinePay) === null) {
            return $this->toStatus($order);
        }

        $attempt = $this->openAttemptFor($order);

        // ⛔ 同樣的身分檢查：一個可偽造的 GET 不足以終止一筆付款嘗試。
        // 客人可能其實付款成功了，只是有人先送了這個網址。
        if ($attempt === null
            || ! $this->identityMatches($request, $attempt, (string) $attempt->provider_reference)) {
            return $this->toStatus($order);
        }

        $this->recordPayment->handle(
            $attempt,
            PaymentStatus::Canceled,
            failureCode: PaymentFailureReason::CanceledByUser->value,
            failureMessage: PaymentFailureReason::CanceledByUser->message(),
        );

        return $this->toStatus($order);
    }

    /**
     * Do the return-URL query parameters match what we already stored?
     *
     * ⛔ Exact comparison against local values only. The query is evidence that
     * LINE Pay sent the customer here; it is never a source of data.
     */
    private function identityMatches(Request $request, PaymentAttempt $attempt, string $transactionId): bool
    {
        $orderId = $request->query('orderId');
        $providerTransactionId = $request->query('transactionId');

        if (! is_string($orderId) || ! is_string($providerTransactionId)) {
            return false;
        }

        return hash_equals($attempt->reference, $orderId)
            && $transactionId !== ''
            && hash_equals($transactionId, $providerTransactionId);
    }

    /**
     * Does this confirm response actually describe our payment?
     *
     * ⛔ Every field is *required*, not "checked if present". Treating a missing
     * field as a pass means a response that omits it — or a shape we guessed
     * wrong — silently satisfies the check, which is how an amount comparison
     * ends up never running at all.
     *
     * ⛔ The currency is not compared: LINE Pay's confirm response does not
     * carry one. It is guaranteed instead by the signed request we sent and by
     * the database constraint that every attempt is TWD; inventing a field to
     * compare would be checking our own fixture, not their answer.
     */
    private function responseMatchesAttempt(LinePayResponse $response, PaymentAttempt $attempt): bool
    {
        // payInfo 必須存在且結構完整；⛔ 空陣列、缺欄、字串或負數都不算數。
        if (! $response->payInfoIsValid || $response->payInfoTotal === null) {
            return false;
        }

        // 各付款方式（LINE Pay＋POINT 可能拆成多筆）的總和必須精確相等。
        if ($response->payInfoTotal !== (int) $attempt->amount) {
            return false;
        }

        if ($response->orderId === null || $response->orderId !== $attempt->reference) {
            return false;
        }

        if ($response->transactionId === null
            || $response->transactionId !== (string) $attempt->provider_reference) {
            return false;
        }

        return true;
    }

    private function openAttemptFor(Order $order): ?PaymentAttempt
    {
        return $order->paymentAttempts()
            ->where('provider', 'line-pay')
            ->whereIn('status', [PaymentStatus::Initiated, PaymentStatus::Pending])
            ->latest('id')
            ->first();
    }

    private function toStatus(Order $order): RedirectResponse
    {
        // ⛔ 目的地由伺服器端的訂單決定，不接受任何 return URL 參數：
        // 那會讓付款流程變成 open redirect。
        return redirect()->route('payments.status', ['reference' => $order->reference]);
    }
}
