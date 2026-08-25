<?php

namespace App\Actions\Integrations;

use App\Enums\IntegrationEnvironment;
use App\Enums\IntegrationProvider;
use App\Models\IntegrationSetting;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reveal exactly one allowlisted credential to an active Owner on explicit request.
 *
 * There is deliberately no method that returns the whole credential payload. The
 * audit row must commit before the value is returned; an audit failure therefore
 * fails closed and no value reaches the browser.
 */
class RevealIntegrationSecret
{
    public function __construct(private readonly RecordCredentialRevealAudit $audit) {}

    public function handle(IntegrationProvider $provider, string $field): string
    {
        abort_unless(Auth::user()?->isOwner() ?? false, 403);
        abort_unless(in_array($field, $provider->secretKeys(), true), 404);

        return DB::transaction(function () use ($provider, $field): string {
            $setting = IntegrationSetting::query()
                ->where('provider', $provider)
                ->where('environment', IntegrationEnvironment::Production)
                ->sharedLock()
                ->first();

            if ($setting === null || ! $setting->hasSecret($field)) {
                throw ValidationException::withMessages([
                    'credential' => $provider->secretLabel($field).'尚未設定。',
                ]);
            }

            // Decryption can throw. In that case execution stops before any value is returned.
            $secret = $setting->secret($field);

            if ($secret === null) {
                throw ValidationException::withMessages([
                    'credential' => $provider->secretLabel($field).'目前無法讀取。',
                ]);
            }

            // The transaction cannot return the secret unless this insert succeeds.
            $this->audit->handle($setting, $provider, $field);

            return $secret;
        });
    }
}
