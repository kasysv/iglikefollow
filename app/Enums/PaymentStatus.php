<?php

namespace App\Enums;

/**
 * The state of a single payment attempt.
 *
 * An order may have several attempts: a customer can fail, cancel, let one
 * expire and then pay successfully. Each attempt keeps its own outcome, so
 * "why did this order sit unpaid for two days" stays answerable.
 */
enum PaymentStatus: string
{
    case Initiated = 'initiated';
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Canceled = 'canceled';
    case Expired = 'expired';
    /** 結果不明，需人工對帳；⛔ 既不是成功也不是失敗。 */
    case ReconciliationRequired = 'reconciliation_required';

    public function label(): string
    {
        return match ($this) {
            self::Initiated => '已建立',
            self::Pending => '付款中',
            self::Succeeded => '付款成功',
            self::Failed => '付款失敗',
            self::Canceled => '已取消',
            self::Expired => '已逾期',
            self::ReconciliationRequired => '需人工對帳',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Succeeded => 'success',
            self::Initiated, self::Pending => 'warning',
            self::Failed, self::ReconciliationRequired => 'danger',
            self::Canceled, self::Expired => 'gray',
        };
    }

    /** Attempts that can still change outcome. */
    public function isOpen(): bool
    {
        return in_array($this, [self::Initiated, self::Pending], true);
    }

    /**
     * A final outcome: the provider has told us how this attempt ended.
     *
     * Only these may be recorded as a result. "Initiated" and "Pending" mean
     * the attempt is still running, so writing a completion time or a failure
     * event for them would misreport an in-flight payment as finished.
     *
     * ⛔ ReconciliationRequired is deliberately excluded. It is not an outcome
     * — it is the absence of one — so it must not be reachable through
     * RecordPaymentResult, which exists to apply results.
     */
    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Succeeded,
            self::Failed,
            self::Canceled,
            self::Expired,
        ], true);
    }

    /**
     * The provider's answer never arrived, or could not be trusted.
     *
     * ⛔ Not a failure. The money may well have been taken: a timeout says
     * nothing about what happened on their side. Recording it as failed would
     * tell a charged customer their payment did not go through, and retrying
     * could charge them twice. A person has to look.
     */
    public function needsReconciliation(): bool
    {
        return $this === self::ReconciliationRequired;
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $c) => [$c->value => $c->label()])
            ->all();
    }
}
