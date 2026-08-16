<?php

namespace App\Actions\Integrations;

use App\Enums\IntegrationEnvironment;
use App\Enums\IntegrationProvider;
use App\Models\AdminAuditLog;
use App\Models\IntegrationSetting;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * Record that credentials changed — and nothing about what they are.
 *
 * The audit answers "who changed which provider's keys, and when". That is
 * everything an owner needs after the fact, and ⛔ deliberately less than the
 * generic AuditObserver would write: no identifier value, no old value, no new
 * value, and no ciphertext. Ciphertext in an audit row is not protection, it
 * is the secret plus a delay — backups travel, and the key lives on the same
 * machine.
 */
class RecordCredentialAudit
{
    /**
     * @param  list<string>  $changedFields  field names only
     */
    public function handle(
        IntegrationProvider $provider,
        IntegrationEnvironment $environment,
        array $changedFields,
    ): void {
        if ($changedFields === []) {
            return; // 沒有實際變更就不留紀錄。
        }

        $settingId = IntegrationSetting::query()
            ->where('provider', $provider)
            ->where('environment', $environment)
            ->value('id');

        AdminAuditLog::create([
            'user_id' => Auth::id(),
            'auditable_type' => IntegrationSetting::class,
            // 設定列的 id 本身不是機密；⛔ 值與密文都不在這裡。
            'auditable_id' => $settingId ?? 0,
            'action' => 'credentials_updated',
            'before' => null,
            // ⛔ 只有欄位「名稱」，沒有任何值。
            'after' => [
                'provider' => $provider->value,
                'environment' => $environment->value,
                'changed_fields' => array_values($changedFields),
            ],
            'ip_address' => Request::ip(),
        ]);
    }
}
