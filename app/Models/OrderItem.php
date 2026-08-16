<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What was bought, frozen at the moment of purchase.
 *
 * Every display field is a copy. Reading the name or price back through
 * serviceVariant() would show today's catalogue, not what the customer agreed
 * to, so the relation exists only to point fulfilment at the right product.
 */
class OrderItem extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function serviceVariant(): BelongsTo
    {
        return $this->belongsTo(ServiceVariant::class);
    }

    /** 顯示用單價，由下單當下的「分」還原。 */
    public function unitPrice(): float
    {
        return $this->unit_price_cents / 100;
    }
}
