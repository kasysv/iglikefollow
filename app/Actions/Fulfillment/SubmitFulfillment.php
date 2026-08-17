<?php

namespace App\Actions\Fulfillment;

use App\Contracts\FulfillmentGateway;
use App\Data\Fulfillment\FulfillmentSubmission;
use App\Data\Fulfillment\FulfillmentSubmissionResult;
use App\Enums\FulfillmentAttentionReason;
use App\Enums\FulfillmentEventCode;
use App\Enums\FulfillmentStatus;
use App\Models\FulfillmentOrder;
use App\Services\Fulfillment\FulfillmentDispatchGate;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Send one fulfilment row to the provider, once.
 *
 * The provider call is the irreversible part, so ownership of it is settled
 * before it happens: one transaction locks the row, checks it is still `ready`,
 * moves it to `submitting` and increments the attempt count. A second worker
 * arriving at the same moment finds the row locked, then finds it no longer
 * `ready`, and returns without calling anyone.
 *
 * ⛔ A compare-and-set, not a read-then-write. Checking the status and then
 * updating in a separate statement leaves a window where both workers pass the
 * check — and two workers passing means two orders placed and paid for.
 *
 * ⛔ Unknown outcomes and unexpected exceptions both stop permanently. The
 * provider may already have accepted the order; asking again could place a
 * second one.
 */
class SubmitFulfillment
{
    public function __construct(private readonly FulfillmentGateway $gateway) {}

    public function handle(FulfillmentOrder $fulfillment): FulfillmentOrder
    {
        $claimed = $this->claim($fulfillment);

        if ($claimed === null) {
            // 沒搶到，或狀態已不是 ready：⛔ 絕不呼叫 gateway。
            return $fulfillment->fresh();
        }

        /*
         * ⛔ 再問一次總開關。
         *
         * 準備階段到現在之間，開關可能已經被關掉；搶到 claim 不等於仍然獲准
         * 送出。這裡把它收斂回 configuration_pending，而不是留在 submitting。
         */
        if (! FulfillmentDispatchGate::enabled()) {
            return $this->recordBlocked($claimed);
        }

        $submission = $this->buildSubmission($claimed);

        if ($submission === null) {
            return $this->recordBlocked($claimed, FulfillmentAttentionReason::UnsupportedPayload);
        }

        try {
            $result = $this->gateway->submit($submission);
        } catch (Throwable) {
            /*
             * ⛔ 任何例外都不是「失敗」：對方可能已經收下了。
             *
             * ⛔ 不帶出 exception 訊息：它常含連線字串、service ID，甚至被回音
             * 的請求內容。
             */
            return $this->recordUnknown($claimed, FulfillmentAttentionReason::Unknown);
        }

        return match (true) {
            $result->isAccepted() => $this->recordSubmitted($claimed, $result, $submission),
            $result->isRejected() => $this->recordRejected($claimed, $result),
            default => $this->recordUnknown(
                $claimed,
                $result->reason ?? FulfillmentAttentionReason::Unknown
            ),
        };
    }

    /**
     * Take exclusive ownership of this submission, atomically.
     *
     * Returns null when this worker did not win — which includes the case where
     * the row was never `ready`, or already carries a provider order id.
     */
    private function claim(FulfillmentOrder $fulfillment): ?FulfillmentOrder
    {
        return DB::transaction(function () use ($fulfillment) {
            $locked = FulfillmentOrder::query()
                ->whereKey($fulfillment->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null || ! $locked->canBeSubmitted()) {
                return null;
            }

            $locked->forceFill([
                'status' => FulfillmentStatus::Submitting,
                'attempt_count' => $locked->attempt_count + 1,
            ])->save();

            $locked->recordEvent(
                FulfillmentEventCode::SubmissionClaimed,
                from: FulfillmentStatus::Ready,
                to: FulfillmentStatus::Submitting,
            );

            return $locked;
        });
    }

    /**
     * ⛔ Reads the target from the encrypted order snapshot, at the last moment.
     *
     * It is passed straight to the gateway and never stored anywhere else.
     */
    private function buildSubmission(FulfillmentOrder $fulfillment): ?FulfillmentSubmission
    {
        $item = $fulfillment->orderItem;
        $serviceId = $fulfillment->provider_service_id_snapshot;
        $target = (string) ($item?->target_value ?? '');
        $quantity = (int) ($item?->quantity ?? 0);

        if ($item === null || $serviceId === null || $target === '' || $quantity <= 0) {
            return null;
        }

        return new FulfillmentSubmission($serviceId, $target, $quantity);
    }

    private function recordSubmitted(
        FulfillmentOrder $fulfillment,
        FulfillmentSubmissionResult $result,
        FulfillmentSubmission $submission,
    ): FulfillmentOrder {
        return DB::transaction(function () use ($fulfillment, $result, $submission) {
            $fulfillment->forceFill([
                'status' => FulfillmentStatus::Submitted,
                'provider_order_id' => $result->providerOrderId,
                'attention_code' => null,
                'submitted_at' => now(),
                // ⛔ 用實際送出的那一份，不重新組一次：重組會讀到已變動的列。
                'request_fingerprint' => FulfillmentOrder::fingerprint($submission->fingerprintInputs()),
            ])->save();

            $fulfillment->recordEvent(
                FulfillmentEventCode::Submitted,
                from: FulfillmentStatus::Submitting,
                to: FulfillmentStatus::Submitted,
            );

            return $fulfillment->fresh();
        });
    }

    private function recordRejected(
        FulfillmentOrder $fulfillment,
        FulfillmentSubmissionResult $result,
    ): FulfillmentOrder {
        // ⛔ 來自本地 allowlist，不是 provider 傳來的字串。
        $reason = $result->reason ?? FulfillmentAttentionReason::ProviderRejected;

        return DB::transaction(function () use ($fulfillment, $reason) {
            $fulfillment->forceFill([
                'status' => FulfillmentStatus::Failed,
                'attention_code' => $reason,
            ])->save();

            $fulfillment->recordEvent(
                FulfillmentEventCode::SubmissionRejected,
                from: FulfillmentStatus::Submitting,
                to: FulfillmentStatus::Failed,
            );

            return $fulfillment->fresh();
        });
    }

    /**
     * Park an unknown outcome for a person to resolve.
     *
     * ⛔ Terminal on purpose. Nothing retries this automatically, because the
     * order may already exist on the provider's side and a retry would place a
     * second one.
     */
    private function recordUnknown(
        FulfillmentOrder $fulfillment,
        FulfillmentAttentionReason $reason,
    ): FulfillmentOrder {
        return DB::transaction(function () use ($fulfillment, $reason) {
            $fulfillment->forceFill([
                'status' => FulfillmentStatus::SubmissionUnknown,
                'attention_code' => $reason,
            ])->save();

            $fulfillment->recordEvent(
                FulfillmentEventCode::SubmissionUnknown,
                from: FulfillmentStatus::Submitting,
                to: FulfillmentStatus::SubmissionUnknown,
            );

            return $fulfillment->fresh();
        });
    }

    /**
     * Nothing was sent, so the row goes back to waiting for configuration.
     *
     * ⛔ Not `failed` and not `submission_unknown`: no call was made, so there
     * is provably nothing on the provider's side, and this row is safe to pick
     * up again once someone fixes the setting.
     */
    private function recordBlocked(
        FulfillmentOrder $fulfillment,
        FulfillmentAttentionReason $reason = FulfillmentAttentionReason::DispatchDisabled,
    ): FulfillmentOrder {
        return DB::transaction(function () use ($fulfillment, $reason) {
            $fulfillment->forceFill([
                'status' => FulfillmentStatus::ConfigurationPending,
                'attention_code' => $reason,
            ])->save();

            $fulfillment->recordEvent(
                FulfillmentEventCode::ConfigurationBlocked,
                from: FulfillmentStatus::Submitting,
                to: FulfillmentStatus::ConfigurationPending,
            );

            return $fulfillment->fresh();
        });
    }
}
