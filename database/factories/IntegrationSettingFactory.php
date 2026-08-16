<?php

namespace Database\Factories;

use App\Enums\IntegrationEnvironment;
use App\Enums\IntegrationProvider;
use App\Models\IntegrationSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IntegrationSetting>
 *
 * ⛔ The values here are obviously fake strings invented for tests. No real or
 * officially published sandbox credential belongs in a factory: anything in
 * this repository is public to everyone who can read it.
 */
class IntegrationSettingFactory extends Factory
{
    protected $model = IntegrationSetting::class;

    public function definition(): array
    {
        return [
            'provider' => IntegrationProvider::EcpayInvoice,
            'environment' => IntegrationEnvironment::Sandbox,
            'identifier' => 'TEST-MERCHANT-ID',
            'is_enabled' => false,
        ];
    }

    /** 填入該 provider 需要的所有 secret，讓設定成為「已完整設定」。 */
    public function configured(): static
    {
        return $this->afterMaking(function (IntegrationSetting $setting) {
            $credentials = [];

            foreach ($setting->provider->secretKeys() as $key) {
                $credentials[$key] = 'test-value-for-'.$key;
            }

            $setting->credentials = $credentials;
        })->afterCreating(function (IntegrationSetting $setting) {
            $credentials = [];

            foreach ($setting->provider->secretKeys() as $key) {
                $credentials[$key] = 'test-value-for-'.$key;
            }

            $setting->credentials = $credentials;
            $setting->save();
        });
    }

    public function forProvider(IntegrationProvider $provider, ?IntegrationEnvironment $environment = null): static
    {
        return $this->state(fn () => [
            'provider' => $provider,
            'environment' => $environment ?? $provider->environments()[0],
            'identifier' => $provider->identifierLabel() === null ? null : 'TEST-MERCHANT-ID',
        ]);
    }
}
