<?php

namespace App\Actions\Integrations;

use App\Enums\IntegrationEnvironment;
use App\Enums\IntegrationProvider;
use App\Models\AdminAuditLog;
use App\Models\IntegrationSetting;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/** Record an Owner credential reveal without recording any part of the value. */
class RecordCredentialRevealAudit
{
    public function handle(IntegrationSetting $setting, IntegrationProvider $provider, string $field): void
    {
        abort_unless(Auth::user()?->isOwner() ?? false, 403);

        AdminAuditLog::create([
            'user_id' => Auth::id(),
            'auditable_type' => IntegrationSetting::class,
            'auditable_id' => $setting->getKey(),
            'action' => 'credential_revealed',
            'before' => null,
            'after' => [
                'provider' => $provider->value,
                'environment' => IntegrationEnvironment::Production->value,
                // Field name only: never the value, ciphertext, length or partial value.
                'field' => $field,
            ],
            'ip_address' => Request::ip(),
        ]);
    }
}
