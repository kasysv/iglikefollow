<?php

namespace Tests\Feature;

use App\Enums\IntegrationEnvironment;
use App\Enums\IntegrationProvider;
use App\Filament\Pages\ManageIntegrationSettings;
use App\Models\IntegrationSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * D1: the four channel toggles are real, keyboard-operable switches.
 *
 * ⛔ This only checks markup and semantics. The actual on/off decision is
 * `ToggleIntegrationChannel`'s job, unchanged and covered elsewhere — this
 * file exists so a future edit to the Blade cannot silently turn the switch
 * back into a CSS-only visual with no `role`/`aria-checked`.
 */
class IntegrationChannelSwitchUiTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        return User::factory()->create(['role' => 'owner', 'is_active' => true]);
    }

    private function configuredSetting(bool $enabled): IntegrationSetting
    {
        $setting = IntegrationSetting::factory()
            ->forProvider(IntegrationProvider::EcpayPayment, IntegrationEnvironment::Production)
            ->configured()
            ->create();

        $setting->forceFill(['is_enabled' => $enabled])->saveQuietly();

        return $setting;
    }

    public function test_an_off_switch_renders_role_switch_and_aria_checked_false(): void
    {
        $this->actingAs($this->owner());
        $this->configuredSetting(false);

        $html = Livewire::test(ManageIntegrationSettings::class)->assertOk()->html();

        $this->assertStringContainsString('role="switch"', $html);
        $this->assertStringContainsString('aria-checked="false"', $html);
        $this->assertStringContainsString('已關閉', $html);
    }

    public function test_an_on_switch_renders_aria_checked_true_and_is_never_disabled(): void
    {
        $this->actingAs($this->owner());
        $this->configuredSetting(true);

        $html = Livewire::test(ManageIntegrationSettings::class)->assertOk()->html();

        $this->assertStringContainsString('aria-checked="true"', $html);
        $this->assertStringContainsString('已開啟', $html);

        /*
         * ⛔ ON 永遠能切回 OFF：這個 provider 的 switch 不得帶「真的」disabled
         * 屬性。`wire:loading.attr="disabled"` 本身的屬性名稱一定含有這個字，
         * 所以只斷言「沒有 disabled」會連這個合法指令都算進去——要找的是實際
         * 渲染出的 boolean disabled 屬性（Blade `@disabled()` 產生的那個）。
         */
        $ecpaySwitch = $this->extractSwitchMarkup($html, IntegrationProvider::EcpayPayment->value);
        $this->assertDoesNotMatchRegularExpression('/[^:]\bdisabled(="disabled")?[\s>]/', $ecpaySwitch);
        $this->assertStringContainsString('height: 1.5rem; width: 2.75rem', $ecpaySwitch);
        $this->assertStringContainsString("toggleChannel('ecpay_payment', false)", $ecpaySwitch);
    }

    public function test_an_unconfigured_channel_renders_a_disabled_switch(): void
    {
        $this->actingAs($this->owner());
        // 完全沒有設定：LINE Pay 這一列必然是 OFF 且不可開啟。

        $html = Livewire::test(ManageIntegrationSettings::class)->assertOk()->html();

        $lineSwitch = $this->extractSwitchMarkup($html, IntegrationProvider::LinePay->value);
        $this->assertMatchesRegularExpression('/[^:]\bdisabled(="disabled")?[\s>]/', $lineSwitch);
    }

    public function test_toggling_still_goes_through_the_backend_action_not_just_the_switch(): void
    {
        $this->actingAs($this->owner());
        $this->configuredSetting(false);

        Livewire::test(ManageIntegrationSettings::class)
            ->call('toggleChannel', IntegrationProvider::EcpayPayment->value, true)
            ->assertOk();

        $this->assertTrue(
            IntegrationSetting::query()
                ->where('provider', IntegrationProvider::EcpayPayment)
                ->where('environment', IntegrationEnvironment::Production)
                ->value('is_enabled')
        );
    }

    public function test_an_enabled_channel_can_be_switched_off_from_the_livewire_action(): void
    {
        $this->actingAs($this->owner());
        $this->configuredSetting(true);

        Livewire::test(ManageIntegrationSettings::class)
            ->call('toggleChannel', IntegrationProvider::EcpayPayment->value, false)
            ->assertOk()
            ->assertSee('已關閉');

        $this->assertFalse(
            IntegrationSetting::query()
                ->where('provider', IntegrationProvider::EcpayPayment)
                ->where('environment', IntegrationEnvironment::Production)
                ->value('is_enabled')
        );
    }

    private function extractSwitchMarkup(string $html, string $providerValue): string
    {
        $needle = 'channel-switch-'.$providerValue;
        $start = strpos($html, $needle);
        $this->assertNotFalse($start, "switch for {$providerValue} not found in HTML");

        // 往前找到這個 button 開始的地方，往後抓一段固定長度即可涵蓋整個 <button>。
        $buttonStart = strrpos(substr($html, 0, $start), '<button');

        return substr($html, $buttonStart, 600);
    }
}
