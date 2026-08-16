<?php

namespace App\Enums;

/**
 * Where an invoice is in its life.
 *
 * The distinction that matters most here is between *failed* and
 * *reconciliation_required*. A deterministic rejection — a malformed tax id,
 * a closed merchant account — is a failure: nothing was issued and retrying
 * would fail the same way. An ambiguous outcome, such as a timeout, means the
 * provider may or may not have issued a real invoice, and ⛔ resending would
 * risk issuing a second one for the same order. Those need a human to look,
 * so they get their own state instead of being retried blindly.
 */
enum InvoiceStatus: string
{
    /** 尚未設定 credential，無法開立；⛔ 不是錯誤，也不該重試。 */
    case PendingConfiguration = 'pending_configuration';
    case Pending = 'pending';
    case Processing = 'processing';
    case Issued = 'issued';
    case Failed = 'failed';
    /** 結果不明，可能已在對方系統開出；⛔ 禁止自動重送。 */
    case ReconciliationRequired = 'reconciliation_required';
    case Voided = 'voided';

    public function label(): string
    {
        return match ($this) {
            self::PendingConfiguration => '尚未設定串接',
            self::Pending => '待開立',
            self::Processing => '開立中',
            self::Issued => '已開立',
            self::Failed => '開立失敗',
            self::ReconciliationRequired => '需人工對帳',
            self::Voided => '已作廢',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Issued => 'success',
            self::Pending, self::Processing, self::PendingConfiguration => 'warning',
            self::Failed, self::ReconciliationRequired => 'danger',
            self::Voided => 'gray',
        };
    }

    /** 已經有最終結果，⛔ 不得再被自動流程改寫。 */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Issued, self::Voided], true);
    }

    /** 需要人看過才能決定下一步。 */
    public function needsHuman(): bool
    {
        return $this === self::ReconciliationRequired;
    }
}
