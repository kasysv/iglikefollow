<?php

namespace App\Http\Controllers\Payments;

use App\Actions\Orders\MarkPaymentUncertain;
use App\Actions\Orders\RecordPaymentResult;
use App\Enums\PaymentFailureReason;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Services\Payments\LinePayClient;
use Illuminate\Http\RedirectResponse;

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

    public function confirm(string $reference): RedirectResponse
    {
        $order = Order::where('reference', $reference)->firstOrFail();
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
    public function cancel(string $reference): RedirectResponse
    {
        $order = Order::where('reference', $reference)->firstOrFail();
        $attempt = $this->openAttemptFor($order);

        if ($attempt !== null) {
            $this->recordPayment->handle(
                $attempt,
                PaymentStatus::Canceled,
                failureCode: PaymentFailureReason::CanceledByUser->value,
                failureMessage: PaymentFailureReason::CanceledByUser->message(),
            );
        }

        return $this->toStatus($order);
    }

    /** 金額、幣別、訂單編號與交易編號全部一致才算數。 */
    private function responseMatchesAttempt($response, PaymentAttempt $attempt): bool
    {
        if ($response->amount !== null && $response->amount !== (int) $attempt->amount) {
            return false;
        }

        if ($response->currency !== null && $response->currency !== ($attempt->currency ?: 'TWD')) {
            return false;
        }

        if ($response->orderId !== null && $response->orderId !== $attempt->reference) {
            return false;
        }

        if ($response->transactionId !== null
            && $response->transactionId !== (string) $attempt->provider_reference) {
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
