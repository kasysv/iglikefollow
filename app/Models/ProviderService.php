<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One service the supplier's catalog declared, as last observed.
 *
 * ⛔ An observation, not a decision. Which service a product actually uses
 * lives in `fulfillment_mappings`, chosen by the Owner; nothing here creates,
 * enables or rewrites a mapping, and no relation connects the two — a service
 * disappearing from the catalog must never take a mapping or an order's
 * history with it.
 *
 * ⛔ `rate_raw`, `minimum_quantity_raw` and `maximum_quantity_raw` are the
 * provider's verbatim strings. The rate's currency and billing unit are
 * unverified, so it is not a retail price, never feeds pricing, and is never
 * parsed into a float.
 */
class ProviderService extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'supports_refill' => 'boolean',
        'supports_cancel' => 'boolean',
        'is_available' => 'boolean',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];
}
