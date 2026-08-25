<?php

namespace App\Models;

use App\Enums\FulfillmentAttentionReason;
use App\Enums\FulfillmentEventCode;
use App\Enums\FulfillmentPayloadType;
use App\Enums\FulfillmentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One order item's journey through a provider.
 *
 * ⛔ Created only after a committed `OrderPaid`, one per item, enforced by a
 * unique index. Everything about this model assumes the expensive mistake is
 * sending twice, not sending late.
 */
class FulfillmentOrder extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'status' => FulfillmentStatus::class,
        'attention_code' => FulfillmentAttentionReason::class,
        'payload_type_snapshot' => FulfillmentPayloadType::class,
        'attempt_count' => 'integer',
        'submitted_at' => 'datetime',
        'last_synced_at' => 'datetime',
    ];

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function mapping(): BelongsTo
    {
        return $this->belongsTo(FulfillmentMapping::class, 'fulfillment_mapping_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(FulfillmentEvent::class)->orderBy('id');
    }

    /**
     * Append one entry to the timeline.
     *
     * ⛔ Takes a code from a closed enum, never a message. There is nowhere in
     * this table for provider text to land, which is the only reliable way to
     * keep it out.
     */
    public function recordEvent(
        FulfillmentEventCode $code,
        ?FulfillmentStatus $from = null,
        ?FulfillmentStatus $to = null,
    ): FulfillmentEvent {
        return $this->events()->create([
            'event_code' => $code->value,
            'from_status' => $from?->value,
            'to_status' => $to?->value,
        ]);
    }

    /**
     * A one-way fingerprint of what would be sent.
     *
     * ⛔ A keyed hash, not an encryption: nothing here should ever be turned
     * back into a request. It answers only "is this the same call as last
     * time".
     *
     * ⛔ Keyed with the app key rather than a plain hash. The inputs are small
     * and guessable — a service id and a round quantity — so an unkeyed digest
     * could be reversed by trying candidates until one matched.
     */
    public static function fingerprint(array $payload): string
    {
        ksort($payload);

        return hash_hmac(
            'sha256',
            json_encode($payload, JSON_UNESCAPED_UNICODE),
            (string) config('app.key'),
        );
    }

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    /**
     * The service name to show a human, best available source.
     *
     * ⛔ Three tiers, in order: the frozen snapshot taken when this row became
     * Ready; failing that, a live catalog lookup keyed on the exact
     * `(provider, provider_service_id_snapshot)` pair (never id alone — a
     * second provider could reuse the same numeric id); failing that, this
     * site's own service name with an explicit "not found" marker, never a
     * blank string or a guess.
     *
     * ⛔ A row with no id snapshot at all (blocked before Ready) has nothing
     * to look up — it falls straight to the order item's name, unmarked,
     * since there was never a provider service to find.
     */
    public function displayServiceName(): string
    {
        if (filled($this->provider_service_name_snapshot)) {
            return $this->provider_service_name_snapshot;
        }

        if (filled($this->provider_service_id_snapshot)) {
            $liveName = ProviderService::query()
                ->where('provider', $this->provider)
                ->where('provider_service_id', $this->provider_service_id_snapshot)
                ->value('name');

            if (filled($liveName)) {
                return $liveName;
            }

            return ($this->orderItem?->service_name ?? '未知服務').'（SMM 目錄未找到）';
        }

        return $this->orderItem?->service_name ?? '未知服務';
    }

    /**
     * ⛔ Never submit twice.
     *
     * A row that already carries a provider order id has been accepted, even if
     * our own status somehow disagrees — that id is proof something exists on
     * their side.
     */
    public function canBeSubmitted(): bool
    {
        return $this->status === FulfillmentStatus::Ready
            && $this->provider_order_id === null;
    }
}
