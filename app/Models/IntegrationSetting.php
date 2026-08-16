<?php

namespace App\Models;

use App\Enums\IntegrationEnvironment;
use App\Enums\IntegrationProvider;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Credentials for one provider in one environment.
 *
 * The secrets are held in a single encrypted payload. Reading them back is
 * deliberately awkward: `secret()` exists for the server-side code that must
 * sign a request, and nothing else should call it. Everything user-facing asks
 * `hasSecret()` instead, which answers "is this configured" without ever
 * producing the value.
 *
 * ⛔ There is no accessor that returns the whole decrypted set, and no
 * `toArray()` exposure: a model serialised into a Livewire payload, an
 * exception page or a queue job must not carry credentials with it.
 */
class IntegrationSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'provider',
        'environment',
        'identifier',
        'is_enabled',
        'last_test_status',
        'last_test_message',
        'last_tested_at',
    ];

    /** ⛔ credentials 不在 $fillable：只能經 UpdateIntegrationCredentials 寫入。 */
    protected $casts = [
        'provider' => IntegrationProvider::class,
        'environment' => IntegrationEnvironment::class,
        'credentials' => 'encrypted:array',
        'is_enabled' => 'boolean',
        'last_tested_at' => 'datetime',
    ];

    /**
     * ⛔ 密文永遠不進序列化結果。
     *
     * Livewire state、queue payload、exception 頁與 API 回應都會經過這裡；
     * 少一個地方遺漏就是一次外洩。
     */
    protected $hidden = ['credentials'];

    public function scopeFor($query, IntegrationProvider $provider, IntegrationEnvironment $environment)
    {
        return $query->where('provider', $provider)->where('environment', $environment);
    }

    /** 這個 secret 有沒有設定；⛔ 不回傳值本身。 */
    public function hasSecret(string $key): bool
    {
        return filled(($this->credentials ?? [])[$key] ?? null);
    }

    /** 已設定的 secret 欄位名稱，供後台顯示「已設定／未設定」。 */
    public function configuredSecretKeys(): array
    {
        return array_values(array_filter(
            $this->provider->secretKeys(),
            fn (string $key) => $this->hasSecret($key)
        ));
    }

    /**
     * The decrypted value of one secret.
     *
     * ⛔ Server-side signing only. Never render this, log it, put it in an
     * exception message, or pass it into a queued job's constructor.
     */
    public function secret(string $key): ?string
    {
        if (! in_array($key, $this->provider->secretKeys(), true)) {
            return null;
        }

        $value = ($this->credentials ?? [])[$key] ?? null;

        return filled($value) ? (string) $value : null;
    }

    /** 全部必要 secret 都齊了才算設定完成。 */
    public function isFullyConfigured(): bool
    {
        foreach ($this->provider->secretKeys() as $key) {
            if (! $this->hasSecret($key)) {
                return false;
            }
        }

        return $this->provider->identifierLabel() === null || filled($this->identifier);
    }

    /**
     * Is this setting usable for a real call right now?
     *
     * ⛔ Being enabled is not enough — a half-filled credential set would fail
     * at the provider with a confusing error instead of here with a clear one.
     */
    public function isUsable(): bool
    {
        return $this->is_enabled && $this->isFullyConfigured();
    }
}
