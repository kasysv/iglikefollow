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
        // ⛔ M4C：逐項回報三個 Owner 通道，不再有一個「付款能力」總結。
        // 一個布林值答不出 Owner 需要知道的事：哪一個開著、哪一個還缺欄位。
        $response->assertSee('綠界付款通道');
        $response->assertSee('LINE Pay 通道');
        $response->assertSee('綠界發票通道');
        $response->assertSee('此環境可對外送出交易請求');
        // ⛔ R1:自動派單改為 Owner 總開關逐項回報;輪詢跟隨同一開關。
        $response->assertSee('TheMostPanel 自動派單');
        $response->assertSee('TheMostPanel 派單端點');
        $response->assertSee('履約狀態輪詢(隨自動派單總開關)');
        $response->assertSee('credential 已填」不等於「允許連線');
        // ⛔ secret 0 出現。
        $response->assertDontSee(self::KEY_MARKER);
        // ⛔ 沒有任何危險動作。
        foreach (['測試連線', '重送', '標記為已付款', '標記完成', '清除 failed', '立即啟用'] as $action) {
            $response->assertDontSee($action);
        }
    }
}
