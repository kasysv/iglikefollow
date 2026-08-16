<?php

namespace App\Observers;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use Illuminate\Validation\ValidationException;

/**
 * Domain rules for an invoice, enforced on every save.
 *
 * The database constraints below this catch bad values; this catches bad
 * *movements*. An invoice that has been issued is a document the tax authority
 * also holds, so quietly moving it back to `pending` and issuing again would
 * produce a second real invoice for one order — the customer inherits that
 * problem, so the transition is refused rather than trusted to callers.
 */
class InvoiceIntegrityObserver
{
    /**
     * Which statuses may follow which.
     *
     * ⛔ Issued and voided are absent as *sources*: they are終點. Reaching them
     * again from anywhere would mean a second issue or an undo that this
     * milestone does not implement.
     *
     * @var array<string, list<string>>
     */
    private const ALLOWED = [
        'pending_configuration' => ['pending', 'failed'],
        'pending' => ['processing', 'failed', 'reconciliation_required'],
        'processing' => ['issued', 'failed', 'reconciliation_required'],
        'failed' => ['pending', 'reconciliation_required'],
        // 人工對帳後可能確認已開出或確認未開出；⛔ 本輪沒有自動路徑會這樣做。
        'reconciliation_required' => ['issued', 'failed', 'voided'],
        'issued' => ['voided'],
        'voided' => [],
    ];

    public function saving(Invoice $invoice): void
    {
        $this->assertAmountIsSellable($invoice);
        $this->assertCurrencyIsSupported($invoice);
        $this->assertTransitionIsAllowed($invoice);
    }

    /** ⛔ 金額必須是正整數台幣：0 或負數的稅務憑證沒有意義。 */
    private function assertAmountIsSellable(Invoice $invoice): void
    {
        $amount = $invoice->amount;

        if (! is_int($amount) && ! ctype_digit((string) $amount)) {
            throw ValidationException::withMessages([
                'amount' => '發票金額必須是整數。',
            ]);
        }

        if ((int) $amount <= 0) {
            throw ValidationException::withMessages([
                'amount' => '發票金額必須大於 0。',
            ]);
        }
    }

    /** ⛔ 只開立台幣發票；其他幣別的稅務規則完全不同，不得猜測。 */
    private function assertCurrencyIsSupported(Invoice $invoice): void
    {
        if (($invoice->currency ?: 'TWD') !== 'TWD') {
            throw ValidationException::withMessages([
                'currency' => "目前只支援 TWD 電子發票，收到「{$invoice->currency}」。",
            ]);
        }
    }

    private function assertTransitionIsAllowed(Invoice $invoice): void
    {
        if (! $invoice->exists || ! $invoice->isDirty('status')) {
            return;
        }

        $from = $invoice->getOriginal('status');
        $to = $invoice->status;

        $from = $from instanceof InvoiceStatus ? $from->value : (string) $from;
        $to = $to instanceof InvoiceStatus ? $to->value : (string) $to;

        if ($from === $to) {
            return;
        }

        if (! in_array($to, self::ALLOWED[$from] ?? [], true)) {
            throw ValidationException::withMessages([
                'status' => "發票狀態不得由「{$from}」變更為「{$to}」。",
            ]);
        }
    }
}
