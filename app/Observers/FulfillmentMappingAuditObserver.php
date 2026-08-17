<?php

namespace App\Observers;

use App\Models\AdminAuditLog;
use App\Models\FulfillmentMapping;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * Record who changed a supplier mapping, without recording what to.
 *
 * ⛔ A dedicated observer rather than adding the model to the shared `AUDITED`
 * list. The generic observer writes every changed attribute into the audit
 * JSON, and `provider_service_id` is exactly the value that must not spread: it
 * is commercially sensitive, and the audit log is readable in the admin by
 * anyone who can reach it.
 *
 * Repointing a mapping is the single highest-value change an attacker could
 * make here — every future order for that product would go to a service of
 * their choosing. So the fact that the id changed is recorded, and the value is
 * not. Someone reviewing the log can see that it happened and go ask; nobody
 * reading the log learns a supplier code they did not already have.
 */
class FulfillmentMappingAuditObserver
{
    /**
     * ⛔ 只有這些欄位可以留下原值。
     *
     * allowlist 而非 blocklist：日後有人加了新欄位，預設是不被記錄，而不是
     * 預設被記錄。
     */
    private const RECORDED = [
        'service_variant_id',
        'provider',
        'payload_type',
        'is_enabled',
    ];

    public function created(FulfillmentMapping $mapping): void
    {
        $this->write($mapping, 'created', null, $this->safe($mapping->getAttributes()));
    }

    public function updated(FulfillmentMapping $mapping): void
    {
        $this->write(
            $mapping,
            'updated',
            $this->safe($mapping->getOriginal()),
            $this->safe($mapping->getChanges()),
        );
    }

    /**
     * ⛔ 服務代碼只留「有沒有變」的訊號，不留值。
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function safe(array $attributes): array
    {
        $safe = [];

        foreach (self::RECORDED as $key) {
            if (array_key_exists($key, $attributes)) {
                $safe[$key] = $attributes[$key];
            }
        }

        if (array_key_exists('provider_service_id', $attributes)) {
            $safe['provider_service_id'] = '[redacted]';
        }

        return $safe;
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function write(FulfillmentMapping $mapping, string $action, ?array $before, ?array $after): void
    {
        AdminAuditLog::create([
            'user_id' => Auth::id(),
            'auditable_type' => $mapping::class,
            'auditable_id' => $mapping->getKey(),
            'action' => $action,
            'before' => $before,
            'after' => $after,
            'ip_address' => Request::ip(),
        ]);
    }
}
