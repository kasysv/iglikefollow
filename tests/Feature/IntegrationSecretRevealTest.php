<?php

namespace Tests\Feature;

use App\Actions\Integrations\RecordCredentialRevealAudit;
use App\Actions\Integrations\RevealIntegrationSecret;
use App\Enums\IntegrationEnvironment;
use App\Enums\IntegrationProvider;
use App\Filament\Pages\ManageIntegrationSettings;
use App\Models\AdminAuditLog;
use App\Models\IntegrationSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class IntegrationSecretRevealTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        return User::factory()->create(['role' => 'owner', 'is_active' => true]);
    }

    private function editor(): User
    {
        return User::factory()->create(['role' => 'editor', 'is_active' => true]);
    }

    private function setting(
        IntegrationProvider $provider = IntegrationProvider::EcpayPayment,
        array $credentials = ['HashKey' => 'real-hash-key', 'HashIV' => 'other-secret'],
    ): IntegrationSetting {
        $setting = IntegrationSetting::factory()
            ->forProvider($provider, IntegrationEnvironment::Production)
            ->create(['identifier' => $provider->identifierLabel() === null ? null : 'PUBLIC-ID']);
        $setting->credentials = $credentials;
        $setting->save();

        return $setting->fresh();
    }

    public function test_initial_page_contains_only_the_fixed_mask_and_no_secret(): void
    {
        $this->actingAs($this->owner());
        $this->setting();

        $page = Livewire::test(ManageIntegrationSettings::class)->assertOk()
            ->assertSet('data.ecpay_payment_secret_HashKey', ManageIntegrationSettings::MASK)
            ->assertSet('data.ecpay_payment_secret_HashIV', ManageIntegrationSettings::MASK);

        $surface = $page->html().json_encode($page->snapshot, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('real-hash-key', $surface);
        $this->assertStringNotContainsString('other-secret', $surface);
        $this->assertStringNotContainsString('目前狀態：', $surface);
        $this->assertStringNotContainsString('留空保留；輸入新值才覆寫', $surface);
        $this->assertStringNotContainsString('不會再顯示真值', $surface);
    }

    public function test_owner_reveals_only_one_allowlisted_field_then_hides_it(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner);
        $this->setting();

        $page = Livewire::test(ManageIntegrationSettings::class)
            ->call('toggleSecretReveal', 'ecpay_payment', 'HashKey')
            ->assertSet('data.ecpay_payment_secret_HashKey', 'real-hash-key')
            ->assertSet('revealedSecrets.ecpay_payment_secret_HashKey', true);

        $surface = $page->html().json_encode($page->snapshot, JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('real-hash-key', $surface);
        $this->assertStringNotContainsString('other-secret', $surface);

        $audit = AdminAuditLog::where('action', 'credential_revealed')->sole();
        $this->assertSame($owner->id, $audit->user_id);
        $this->assertSame('ecpay_payment', $audit->after['provider']);
        $this->assertSame('HashKey', $audit->after['field']);
        $this->assertStringNotContainsString('real-hash-key', $audit->toJson());
        $this->assertStringNotContainsString('other-secret', $audit->toJson());

        $page->call('toggleSecretReveal', 'ecpay_payment', 'HashKey')
            ->assertSet('data.ecpay_payment_secret_HashKey', ManageIntegrationSettings::MASK)
            ->assertSet('revealedSecrets.ecpay_payment_secret_HashKey', null);

        $hiddenSurface = $page->html().json_encode($page->snapshot, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('real-hash-key', $hiddenSurface);
    }

    #[DataProvider('providerSecretProvider')]
    public function test_each_provider_secret_type_can_be_revealed_independently(
        IntegrationProvider $provider,
        array $credentials,
        string $field,
        string $expected,
    ): void {
        $this->actingAs($this->owner());
        $this->setting($provider, $credentials);

        $revealed = app(RevealIntegrationSecret::class)->handle($provider, $field);

        $this->assertSame($expected, $revealed);
        $this->assertDatabaseHas('admin_audit_logs', [
            'action' => 'credential_revealed',
        ]);
    }

    public static function providerSecretProvider(): array
    {
        return [
            'ECPay payment' => [
                IntegrationProvider::EcpayPayment,
                ['HashKey' => 'payment-hash-key', 'HashIV' => 'payment-hash-iv'],
                'HashKey',
                'payment-hash-key',
            ],
            'LINE Pay' => [
                IntegrationProvider::LinePay,
                ['ChannelSecret' => 'line-pay-secret'],
                'ChannelSecret',
                'line-pay-secret',
            ],
            'ECPay invoice' => [
                IntegrationProvider::EcpayInvoice,
                ['HashKey' => 'invoice-hash-key', 'HashIV' => 'invoice-hash-iv'],
                'HashIV',
                'invoice-hash-iv',
            ],
            'TheMostPanel' => [
                IntegrationProvider::TheMostPanel,
                ['ApiKey' => 'the-most-panel-key'],
                'ApiKey',
                'the-most-panel-key',
            ],
        ];
    }

    public function test_same_value_is_a_no_op_and_new_value_is_saved_then_masked(): void
    {
        $this->actingAs($this->owner());
        $setting = $this->setting();

        $page = Livewire::test(ManageIntegrationSettings::class)
            ->call('toggleSecretReveal', 'ecpay_payment', 'HashKey')
            ->call('save')
            ->assertSet('data.ecpay_payment_secret_HashKey', ManageIntegrationSettings::MASK);

        $this->assertSame('real-hash-key', $setting->fresh()->secret('HashKey'));
        $this->assertSame(0, AdminAuditLog::where('action', 'credentials_updated')->count());

        $page->call('toggleSecretReveal', 'ecpay_payment', 'HashKey')
            ->set('data.ecpay_payment_secret_HashKey', 'rotated-hash-key')
            ->call('save')
            ->assertSet('data.ecpay_payment_secret_HashKey', ManageIntegrationSettings::MASK);

        $this->assertSame('rotated-hash-key', $setting->fresh()->secret('HashKey'));
        $this->assertSame(1, AdminAuditLog::where('action', 'credentials_updated')->count());
    }

    public function test_editor_inactive_owner_and_unknown_field_fail_closed(): void
    {
        $setting = $this->setting();

        foreach ([$this->editor(), User::factory()->create(['role' => 'owner', 'is_active' => false])] as $user) {
            $this->actingAs($user);

            try {
                app(RevealIntegrationSecret::class)->handle(IntegrationProvider::EcpayPayment, 'HashKey');
                $this->fail('Unauthorized user received a credential.');
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        }

        auth()->logout();

        try {
            app(RevealIntegrationSecret::class)->handle(IntegrationProvider::EcpayPayment, 'HashKey');
            $this->fail('Guest received a credential.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->actingAs($this->owner());

        try {
            app(RevealIntegrationSecret::class)->handle(IntegrationProvider::EcpayPayment, 'NotARealKey');
            $this->fail('Unknown credential field was accepted.');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }

        try {
            app(ManageIntegrationSettings::class)->toggleSecretReveal(
                'not-a-provider',
                'HashKey',
                app(RevealIntegrationSecret::class),
            );
            $this->fail('Unknown provider was accepted.');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }

        $this->assertSame('real-hash-key', $setting->fresh()->secret('HashKey'));
    }

    public function test_audit_failure_prevents_the_reveal_from_returning(): void
    {
        $this->actingAs($this->owner());
        $this->setting();

        $audit = Mockery::mock(RecordCredentialRevealAudit::class);
        $audit->shouldReceive('handle')->once()->andThrow(new RuntimeException('audit unavailable'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('audit unavailable');

        (new RevealIntegrationSecret($audit))->handle(IntegrationProvider::EcpayPayment, 'HashKey');
    }
}
