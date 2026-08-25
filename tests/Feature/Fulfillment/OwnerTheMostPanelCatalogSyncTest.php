<?php

namespace Tests\Feature\Fulfillment;

use App\Actions\Fulfillment\SyncTheMostPanelServiceCatalogFromOwner;
use App\Enums\IntegrationEnvironment;
use App\Enums\IntegrationProvider;
use App\Filament\Pages\ManageIntegrationSettings;
use App\Models\AdminAuditLog;
use App\Models\IntegrationSetting;
use App\Models\ProviderService;
use App\Models\User;
use App\Services\Fulfillment\TheMostPanelCurlCapability;
use Filament\Actions\Action;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** The Owner button may perform one fake read-only services request, never an order. */
class OwnerTheMostPanelCatalogSyncTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = 'https://themostpanel.com/api/v2';

    private const KEY = 'FAKE-OWNER-CATALOG-KEY-330011';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->app->singleton(
            TheMostPanelCurlCapability::class,
            fn () => TheMostPanelCurlCapability::supported('7.68.0'),
        );
    }

    private function owner(): User
    {
        return User::factory()->create(['role' => 'owner', 'is_active' => true]);
    }

    private function editor(): User
    {
        return User::factory()->create(['role' => 'editor', 'is_active' => true]);
    }

    private function credential(bool $dispatchEnabled): IntegrationSetting
    {
        $setting = IntegrationSetting::factory()
            ->forProvider(IntegrationProvider::TheMostPanel, IntegrationEnvironment::Production)
            ->create();

        $setting->credentials = ['ApiKey' => self::KEY];
        $setting->save();
        $setting->forceFill(['is_enabled' => $dispatchEnabled])->saveQuietly();

        return $setting;
    }

    /** @return list<array<string, mixed>> */
    private function services(): array
    {
        return [
            [
                'service' => 77001,
                'name' => '虛構 Owner 同步服務 A',
                'type' => 'Default',
                'category' => '虛構分類',
                'rate' => '0.59',
                'min' => '100',
                'max' => '10000',
                'refill' => true,
                'cancel' => false,
            ],
            [
                'service' => 77002,
                'name' => '虛構 Owner 同步服務 B',
                'type' => 'Default',
                'category' => '虛構分類',
                'rate' => '1.25',
                'min' => '50',
                'max' => '5000',
                'refill' => false,
                'cancel' => false,
            ],
        ];
    }

    public static function dispatchStates(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('dispatchStates')]
    public function test_owner_syncs_exactly_once_regardless_of_dispatch_switch(bool $dispatchEnabled): void
    {
        $this->app->detectEnvironment(fn () => 'staging');
        $this->actingAs($this->owner());
        $this->credential($dispatchEnabled);

        // The new Owner path does not depend on either legacy CLI env flag.
        config()->set('integrations.themostpanel_read_only.enabled', false);
        config()->set('integrations.themostpanel_catalog_sync.enabled', false);

        Http::fake([self::ENDPOINT => Http::response($this->services())]);

        $result = app(SyncTheMostPanelServiceCatalogFromOwner::class)->handle();

        $this->assertTrue($result->applied);
        $this->assertSame('catalog_applied', $result->outcome);
        $this->assertSame(2, ProviderService::query()->count());
        Http::assertSentCount(1);
        Http::assertSent(function ($request): bool {
            return $request->url() === self::ENDPOINT
                && $request->method() === 'POST'
                && $request->data() === ['key' => self::KEY, 'action' => 'services'];
        });

        $audit = AdminAuditLog::query()
            ->where('action', SyncTheMostPanelServiceCatalogFromOwner::AUDIT_ACTION)
            ->sole();

        $this->assertSame('completed', $audit->after['state']);
        $this->assertSame(2, $audit->after['service_count']);
        $this->assertTrue($audit->after['applied']);

        $auditJson = $audit->toJson(JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString(self::KEY, $auditJson);
        $this->assertStringNotContainsString('虛構 Owner 同步服務', $auditJson);
    }

    public function test_local_environment_fails_closed_without_http(): void
    {
        $this->actingAs($this->owner());
        $this->credential(false);
        Http::fake();

        $result = app(SyncTheMostPanelServiceCatalogFromOwner::class)->handle();

        $this->assertSame('blocked_environment', $result->outcome);
        $this->assertFalse($result->applied);
        $this->assertSame(0, ProviderService::query()->count());
        Http::assertNothingSent();
    }

    public function test_missing_credential_in_staging_sends_nothing(): void
    {
        $this->app->detectEnvironment(fn () => 'staging');
        $this->actingAs($this->owner());
        Http::fake();

        $result = app(SyncTheMostPanelServiceCatalogFromOwner::class)->handle();

        $this->assertSame('blocked_no_credential', $result->outcome);
        Http::assertNothingSent();
    }

    public function test_an_unavailable_audit_log_blocks_before_http(): void
    {
        $this->app->detectEnvironment(fn () => 'staging');
        $this->actingAs($this->owner());
        $this->credential(false);
        Http::fake();

        AdminAuditLog::creating(function (): void {
            throw new \RuntimeException('fictional audit failure');
        });

        $result = app(SyncTheMostPanelServiceCatalogFromOwner::class)->handle();

        $this->assertSame('blocked_audit_unavailable', $result->outcome);
        Http::assertNothingSent();
        $this->assertSame(0, ProviderService::query()->count());
    }

    public function test_editor_cannot_forge_the_owner_action(): void
    {
        $this->app->detectEnvironment(fn () => 'staging');
        $this->actingAs($this->editor());
        $this->credential(false);
        Http::fake();

        try {
            app(SyncTheMostPanelServiceCatalogFromOwner::class)->handle();
            $this->fail('Editor 不得執行 Owner catalog sync。');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        Http::assertNothingSent();
        $this->assertSame(0, AdminAuditLog::query()
            ->where('action', SyncTheMostPanelServiceCatalogFromOwner::AUDIT_ACTION)
            ->count());
    }

    public function test_the_admin_page_shows_catalog_state_and_registered_action(): void
    {
        $this->actingAs($this->owner());
        ProviderService::factory()->create([
            'provider' => IntegrationProvider::TheMostPanel,
            'last_seen_at' => now(),
            'first_seen_at' => now(),
        ]);

        $page = Livewire::test(ManageIntegrationSettings::class)
            ->assertOk()
            ->assertActionExists('syncTheMostPanelCatalog', function (Action $action): bool {
                return $action->isConfirmationRequired()
                    && (string) $action->getModalHeading() === '同步 SMM 服務清單？'
                    && str_contains((string) $action->getModalDescription(), '一次唯讀 services 查詢')
                    && str_contains((string) $action->getModalDescription(), '不會建立訂單');
            });

        $this->assertStringContainsString('同步 SMM 服務', $page->html());
        $this->assertStringContainsString('目前已保存', $page->html());
        $this->assertStringContainsString('最後成功同步', $page->html());
    }
}
