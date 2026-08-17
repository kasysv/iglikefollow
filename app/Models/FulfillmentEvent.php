<?php

namespace App\Models;

use App\Enums\FulfillmentEventCode;
use App\Enums\FulfillmentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry in a fulfilment row's timeline.
 *
 * ⛔ Append-only, and the database enforces it with a trigger that aborts any
 * UPDATE. A timeline that can be rewritten afterwards is not evidence of
 * anything.
 */
class FulfillmentEvent extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'event_code' => FulfillmentEventCode::class,
        'from_status' => FulfillmentStatus::class,
        'to_status' => FulfillmentStatus::class,
    ];

    public function fulfillmentOrder(): BelongsTo
    {
        return $this->belongsTo(FulfillmentOrder::class);
    }
}
