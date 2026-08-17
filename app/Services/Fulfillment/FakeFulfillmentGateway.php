<?php

namespace App\Services\Fulfillment;

use App\Contracts\FulfillmentGateway;
use App\Data\Fulfillment\FulfillmentSubmission;
use App\Data\Fulfillment\FulfillmentSubmissionResult;
use App\Data\Fulfillment\FulfillmentSyncResult;
use App\Enums\FulfillmentAttentionReason;
use App\Enums\FulfillmentStatus;
use RuntimeException;

/**
 * An in-memory provider for tests.
 *
 * ⛔ This is not TheMostPanel and proves nothing about it. It exercises our own
 * state machine — what we do with an acceptance, a rejection, a timeout — which
 * is the part we control. The real contract is unverified and stays that way
 * until M4B has evidence.
 *
 * ⛔ Never reachable in production: the container refuses to build it there, and
 * the constructor refuses again. Two checks because this class returning a
 * plausible provider order id in production would mark orders as dispatched
 * that no supplier ever received.
 */
class FakeFulfillmentGateway implements FulfillmentGateway
{
    private int $counter = 0;

    /** @var list<FulfillmentSubmission> */
    public array $submissions = [];

    public function __construct(
        private string $nextOutcome = 'accepted',
        private ?FulfillmentStatus $nextSyncStatus = FulfillmentStatus::Processing,
        private bool $syncRecognised = true,
    ) {
        if (app()->environment('production')) {
            throw new RuntimeException('⛔ production 不得使用 Fake 履約 gateway。');
        }
    }

    /** 下一次 submit 要回什麼；⛔ 只有測試會呼叫。 */
    public function willAccept(): self
    {
        $this->nextOutcome = 'accepted';

        return $this;
    }

    public function willReject(): self
    {
        $this->nextOutcome = 'rejected';

        return $this;
    }

    /** 逾時／讀不懂：⛔ 可能已經成立。 */
    public function willBeUnknown(): self
    {
        $this->nextOutcome = 'unknown';

        return $this;
    }

    /** 丟出例外，模擬連線中斷。 */
    public function willThrow(): self
    {
        $this->nextOutcome = 'throw';

        return $this;
    }

    public function willSync(FulfillmentStatus $status): self
    {
        $this->nextSyncStatus = $status;
        $this->syncRecognised = true;

        return $this;
    }

    public function willSyncUnrecognised(): self
    {
        $this->syncRecognised = false;

        return $this;
    }

    public function submit(FulfillmentSubmission $submission): FulfillmentSubmissionResult
    {
        // ⛔ 記錄下來讓測試可以反證 target 沒有被存到別的地方。
        $this->submissions[] = $submission;

        return match ($this->nextOutcome) {
            'rejected' => FulfillmentSubmissionResult::rejected(),
            'unknown' => FulfillmentSubmissionResult::unknown(FulfillmentAttentionReason::Timeout),
            'throw' => throw new RuntimeException('fake gateway connection failure'),
            default => FulfillmentSubmissionResult::accepted(
                config('fulfillment.fake.order_id_prefix', 'FAKE-').(++$this->counter)
            ),
        };
    }

    public function sync(string $providerOrderId): FulfillmentSyncResult
    {
        if (! $this->syncRecognised || $this->nextSyncStatus === null) {
            return FulfillmentSyncResult::unrecognised();
        }

        return FulfillmentSyncResult::status($this->nextSyncStatus);
    }
}
