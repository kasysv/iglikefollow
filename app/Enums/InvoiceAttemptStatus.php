<?php

namespace App\Enums;

/**
 * The outcome of one attempt to issue an invoice with a provider.
 *
 * An invoice keeps its own status; this records what happened on each
 * individual call, so "we asked twice and the second one timed out" stays
 * answerable without keeping the raw traffic.
 */
enum InvoiceAttemptStatus: string
{
    case Started = 'started';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    /** 沒有明確結果；⛔ 不得當作失敗後盲目重送。 */
    case Ambiguous = 'ambiguous';

    public function label(): string
    {
        return match ($this) {
            self::Started => '進行中',
            self::Succeeded => '成功',
            self::Failed => '失敗',
            self::Ambiguous => '結果不明',
        };
    }

    public function isFinished(): bool
    {
        return $this !== self::Started;
    }
}
