<?php

namespace App\Data\Fulfillment;

use App\Enums\FulfillmentStatus;

/**
 * What the provider says an existing order is doing now.
 *
 * ⛔ `unrecognised()` exists so that "we do not understand this status" can
 * never be rounded to `completed`. Providers add status values over time, and
 * treating an unknown one as finished would close an order that is still
 * running — or one that failed.
 */
final class FulfillmentSyncResult
{
    private function __construct(
        public readonly ?FulfillmentStatus $status,
    ) {}

    public static function status(FulfillmentStatus $status): self
    {
        return new self($status);
    }

    /** ⛔ 讀不懂：維持原狀，只留下一筆本地事件。 */
    public static function unrecognised(): self
    {
        return new self(null);
    }

    public function isRecognised(): bool
    {
        return $this->status !== null;
    }
}
