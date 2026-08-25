<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The e-invoice for one paid order.
 *
 * ⛔ Buyer details are not copied here. The order holds them, encrypted, and
 * duplicating them would create a second place for personal data to leak from
 * while adding nothing this table needs: what belongs here is the provider's
 * answer, not the customer's identity.
 *
 * The amount is a whole number of NT dollars and must equal what was actually
 * paid. An invoice for a different amount than the payment is a tax problem,
 * so it is checked rather than assumed.
 */
class Invoice extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'status' => InvoiceStatus::class,
        'amount' => 'integer',
        'issued_at' => 'datetime',
        'voided_at' => 'datetime',
        'allowance_at' => 'datetime',
        'reconciliation_required_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(InvoiceAttempt::class);
    }

    /**
     * The idempotency key for the one automatic issuing attempt.
     *
     * ⛔ Derived only from facts that never change: the invoice id and the
     * amount. A key that counted existing attempts would give each redelivery
     * a *different* key, so the unique index would happily accept both and two
     * workers could each call the provider — the exact thing the key exists to
     * prevent. A random or time-based key fails the same way.
     */
    public function initialIdempotencyKey(): string
    {
        return sprintf('inv-%d-%d-initial', $this->id, $this->amount);
    }

    /**
     * The key for the attempt about to be created.
     *
     * ⭐ D-179 手動重開：第一筆仍是 initial key，之後每一次 Owner 手動重送各自
     * 取得 `manual-2`、`manual-3`……序號來自**這張發票已落盤的 attempt 筆數**。
     *
     * ⛔ 只能在持有 invoice row lock 時呼叫（`IssueInvoice::claim()` 內）。
     * 沒有 lock 時兩個 worker 會讀到相同筆數、算出相同的鍵，其中一個會撞上
     * unique index——那是安全的失敗方向（不會重複呼叫綠界），但正確的作法是
     * 在 lock 內讀，讓序號本身就不會相同。
     *
     * ⛔ 不用時間或隨機值：那會讓同一次重送的兩個 worker 各拿到一個不同的鍵，
     * unique index 兩個都收，於是兩者都真的呼叫綠界——正是這個鍵要防的事。
     *
     * ⛔ 這個鍵只決定「本地允不允許再建立一筆嘗試」。送往綠界的 RelateNumber
     * 永遠由 order reference 推導、每次都相同，那才是防止同一張訂單開出第二張
     * 發票的最終防線。
     */
    public function idempotencyKeyForNextAttempt(): string
    {
        $existing = $this->attempts()->count();

        if ($existing === 0) {
            return $this->initialIdempotencyKey();
        }

        return sprintf('inv-%d-%d-manual-%d', $this->id, $this->amount, $existing + 1);
    }

    /** 發票號碼遮罩：後台對帳看得出是哪一張，⛔ 但不完整回顯。 */
    public function maskedInvoiceNumber(): ?string
    {
        if (blank($this->invoice_number)) {
            return null;
        }

        $number = (string) $this->invoice_number;

        return strlen($number) <= 4
            ? str_repeat('*', strlen($number))
            : substr($number, 0, 2).str_repeat('*', max(0, strlen($number) - 4)).substr($number, -2);
    }

    public function maskedProviderReference(): ?string
    {
        if (blank($this->provider_reference)) {
            return null;
        }

        $reference = (string) $this->provider_reference;

        return strlen($reference) <= 4
            ? str_repeat('*', strlen($reference))
            : substr($reference, 0, 2).str_repeat('*', max(0, strlen($reference) - 4)).substr($reference, -2);
    }
}
