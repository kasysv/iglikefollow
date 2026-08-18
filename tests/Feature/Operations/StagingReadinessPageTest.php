<?php

namespace Tests\Feature\Operations;

use App\Enums\IntegrationEnvironment;
use App\Enums\IntegrationProvider;
use App\Models\IntegrationSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The Owner-only readiness page: status only, action-free, secret-free.
 */
class StagingReadinessPageTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/admin/staging-readiness';

    private const KEY_MARKER = 'FAKE-PAGE-KEY-MARKER-770033';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    private function owner(): User
    {
        return User::factory()->create(['role' => 'owner', 'is_active' => true]);
    }

    public function test_a_guest_is_redirected(): void
    {
        $this->get(self::URL)->assertRedirect();
    }

    public function test_an_editor_is_forbidden(): void
    {
        $editor = User::factory()->create(['role' => 'editor', 'is_active' => true]);

        $this->actingAs($editor)->get(self::URL)->assertForbidden();
    }

    public function test_an_owner_sees_status_without_secrets_or_actions(): void
    {
        $setting = IntegrationSetting::factory()
            ->forProvider(IntegrationProvider::TheMostPanel, IntegrationEnvironment::Production)
            ->create();
        $setting->credentials = ['ApiKey' => self::KEY_MARKER];
        $setting->save();

        $response = $this->actingAs($this->owner())->get(self::URL);

        $response->assertOk();
        $response->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        // 狀態欄位存在。
        $response->assertSee('APP_ENV');
        $response->assertSee('Sandbox 付款能力');
        $response->assertSee('TheMostPanel staging 派單能力');
        $response->assertSee('履約狀態輪詢');
        $response->assertSee('credential 已填」不等於「允許連線');
        // ⛔ secret 0 出現。
        $response->assertDontSee(self::KEY_MARKER);
        // ⛔ 沒有任何危險動作。
        foreach (['測試連線', '重送', '標記為已付款', '標記完成', '清除 failed', '立即啟用'] as $action) {
            $response->assertDontSee($action);
        }
    }
}
