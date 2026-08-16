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
     *
     * Manual re-issue and voiding are not in this milestone; when they arrive
     * they need their own explicit, human-triggered key, not a counter.
     */
    public function initialIdempotencyKey(): string
    {
        return sprintf('inv-%d-%d-initial', $this->id, $this->amount);
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
