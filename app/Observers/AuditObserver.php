<?php

namespace App\Observers;

use App\Models\AdminAuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * Records admin content changes to admin_audit_logs.
 *
 * Secrets must never reach the audit JSON, so every payload is filtered
 * through a redaction list before it is written.
 */
class AuditObserver
{
    /** ⛔ 這些欄位永遠不得寫入 audit JSON。 */
    private const REDACTED = [
        'password',
        'password_confirmation',
        'remember_token',
        'api_key',
        'api_token',
        'secret',
        'token',
        'session',
        'payment_key',
    ];

    public function created(Model $model): void
    {
        $this->record($model, 'created', null, $model->getAttributes());
    }

    public function updated(Model $model): void
    {
        $this->record($model, 'updated', $model->getOriginal(), $model->getChanges());
    }

    public function deleted(Model $model): void
    {
        $this->record($model, 'deleted', $model->getOriginal(), null);
    }

    public function restored(Model $model): void
    {
        $this->record($model, 'restored', null, $model->getAttributes());
    }

    private function record(Model $model, string $action, ?array $before, ?array $after): void
    {
        AdminAuditLog::create([
            'user_id' => Auth::id(),
            'auditable_type' => $model::class,
            'auditable_id' => $model->getKey(),
            'action' => $action,
            'before' => $before ? $this->redact($before) : null,
            'after' => $after ? $this->redact($after) : null,
            'ip_address' => Request::ip(),
        ]);
    }

    /** @return array<string, mixed> */
    private function redact(array $attributes): array
    {
        foreach (array_keys($attributes) as $key) {
            foreach (self::REDACTED as $needle) {
                if (str_contains(strtolower((string) $key), $needle)) {
                    $attributes[$key] = '[redacted]';

                    continue 2;
                }
            }
        }

        return $attributes;
    }
}
