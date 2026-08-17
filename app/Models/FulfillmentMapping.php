<?php

namespace App\Models;

use App\Enums\FulfillmentPayloadType;
use App\Enums\IntegrationProvider;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Which provider service one product variant is fulfilled by.
 *
 * ⛔ `is_enabled` being true does not authorise anything to be sent. M4A has no
 * HTTP client, and the dispatch switch is separate and off by default. Enabling
 * a mapping says "this pairing is correct", not "start ordering".
 */
class FulfillmentMapping extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'payload_type' => FulfillmentPayloadType::class,
        'is_enabled' => 'boolean',
    ];

    public function serviceVariant(): BelongsTo
    {
        return $this->belongsTo(ServiceVariant::class);
    }

    public function fulfillmentOrders(): HasMany
    {
        return $this->hasMany(FulfillmentOrder::class);
    }

    /**
     * Is this mapping usable as it stands?
     *
     * ⛔ Checks the row only. Whether we may actually dispatch is a separate
     * question with its own gate — keeping them apart is what stops "the
     * mapping looks fine" from being read as "go ahead and send".
     */
    public function isUsable(): bool
    {
        return $this->is_enabled
            && $this->provider === IntegrationProvider::TheMostPanel->value
            && trim((string) $this->provider_service_id) !== ''
            && $this->payload_type === FulfillmentPayloadType::LinkQuantity;
    }
}
