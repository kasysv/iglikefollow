<?php

namespace Tests\Feature\Notifications;

use App\Actions\Notifications\SendLineTestMessage;
use App\Enums\IntegrationEnvironment;
use App\Enums\IntegrationProvider;
use App\Filament\Pages\ManageIntegrationSettings;
use App\Models\IntegrationSetting;
use App\Models\User;
use App\Services\Integrations\ProviderEndpoints;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Concerns\ConfiguresLiveIntegrations;
use Tests\TestCase;

/**
 * The Owner-only LINE notification settings and its test button.
 *
 * ⛔ 這個檔案守的是三件事：只有 active Owner 能碰、secret 不回到瀏覽器、
 * 以及本機／testing 絕不真的送出訊息。
 */
class LineNotificationSettingsTest extends TestCase
{
    use ConfiguresLiveIntegrations;
    use RefreshDatabase;

    private const TARGET = 'Cf7a1b2c3d4e5f60718293a4b5c6d7e8f';

    private const TOKEN = 'super-secret-channel-access-token';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    private function owner(): User
    {
        return User::factory()->create(['role' => 'owner', 'is_active' => true]);
    }

    private function editor(): User
    {
        return User::factory()->create(['role' => 'editor', 'is_active' => true]);
    }

    private function configured(bool $enabled = false): IntegrationSetting
    {
        $setting = IntegrationSetting::factory()->create([
            'provider' => IntegrationProvider::LineOrderNotification,
            'environment' => 'production',
            'identifier' => self::TARGET,
            'is_enabled' => false,
        ]);

        $setting->forceFill(['credentials' => ['ChannelAccessToken' => self::TOKEN]])->save();

        if ($enabled) {
            DB::table('integration_settings')->where('id', $setting->id)->update(['is_enabled' => true]);
        }

        return $setting->fresh();
    }

    // ==================================== 1. provider 設定本身

    /** ⭐ 新 provider 有自己的區塊、接收 ID 欄位與 token 欄位。 */
    public function test_the_provider_appears_with_its_own_fields(): void
    {
        $this->actingAs($this->owner());

        Livewire::test(ManageIntegrationSettings::class)
            ->assertOk()
            ->assertSee('LINE 新訂單通知')
            ->assertSee('接收 ID')
            ->assertSee('Channel Access Token');
    }

    /**
     * ⛔⛔ **不存 Channel Secret**。
     *
     * Push Message 只需要 Channel Access Token；Channel Secret 只在 webhook
     * 驗簽時才有用途，而本輪不新增 webhook。⛔ 存一個現在用不到的 secret，
     * 只是多一個會外洩的東西。
     */
    public function test_the_channel_secret_is_never_stored(): void
    {
        $this->assertSame(
            ['ChannelAccessToken'],
            IntegrationProvider::LineOrderNotification->secretKeys(),
        );

        $this->assertNotContains(
            'ChannelSecret',
            IntegrationProvider::LineOrderNotification->secretKeys(),
        );
    }

    /** ⛔ 與 LINE Pay 完全分開：兩者是不同產品、不同金鑰。 */
    public function test_it_does_not_share_credentials_with_line_pay(): void
    {
        $this->configured();

        // LINE Pay 那一列不存在，也不該被這個 provider 影響。
        $this->assertDatabaseMissing('integration_settings', [
            'provider' => IntegrationProvider::LinePay->value,
        ]);

        $this->assertNotSame(
            IntegrationProvider::LinePay->value,
            IntegrationProvider::LineOrderNotification->value,
        );
    }

    /** ⛔ 只有 production 一列：LINE Messaging API 沒有本專案要用的 sandbox。 */
    public function test_only_production_is_offered(): void
    {
        $this->assertSame(
            [IntegrationEnvironment::Production],
            IntegrationProvider::LineOrderNotification->environments(),
        );
    }

    /** ⛔ 已儲存的 token 只以固定遮罩出現，⛔ 真值不進初始 HTML。 */
    public function test_a_stored_token_is_masked_in_the_form(): void
    {
        $this->configured();
        $this->actingAs($this->owner());

        $page = Livewire::test(ManageIntegrationSettings::class)->assertOk();

        $page->assertDontSee(self::TOKEN);
        $this->assertSame(
            ManageIntegrationSettings::MASK,
            $page->get('data')['line_order_notification_secret_ChannelAccessToken'],
        );
    }

    // ==================================== 2. 權限

    /** ⛔ 非 Owner 不得開啟這一頁。 */
    public function test_an_editor_cannot_access_the_page(): void
    {
        $this->actingAs($this->editor());

        $this->assertFalse(ManageIntegrationSettings::canAccess());
    }

    /**
     * ⛔⛔ 偽造的 Livewire 呼叫必須 fail closed。
     *
     * `canAccess()` 只擋得住畫面；一份手寫的 Livewire payload 從來不經過畫面。
     */
    public function test_a_forged_call_to_the_test_button_is_refused(): void
    {
        $this->configured(enabled: true);
        $this->runningAsLiveSite();
        $this->actingAs($this->editor());

        /*
         * ⛔ 這裡刻意**不**用 `Livewire::test()->call()`。
         *
         * 以 editor 身分連 mount 都會被 `abort_unless(canAccess())` 擋下，
         * component 根本不會初始化——那樣測到的是「畫面進不去」，
         * ⭐ 而真正要驗證的是「就算繞過畫面直接呼叫，也一樣被拒」。
         *
         * 因此直接呼叫 action 本身，那是偽造 Livewire payload 會打到的地方。
         */
        $outcome = app(SendLineTestMessage::class)->handle();

        $this->assertFalse($outcome->successful());
        Http::assertNothingSent();

        // 頁面本身也確實擋住 editor。
        $this->assertFalse(ManageIntegrationSettings::canAccess());
    }

    /** ⛔ 未登入同樣拒絕，且 action 內部自己也再擋一次。 */
    public function test_the_action_refuses_a_guest_even_if_called_directly(): void
    {
        $this->configured(enabled: true);
        $this->runningAsLiveSite();

        $outcome = app(SendLineTestMessage::class)->handle();

        $this->assertFalse($outcome->successful());
        Http::assertNothingSent();
    }

    // ==================================== 3. 測試訊息按鈕

    /**
     * ⛔⛔ 本機／testing 絕不真的送出。
     *
     * 開發時按到這顆按鈕不該讓 Owner 的手機響。
     */
    public function test_the_test_button_never_sends_from_a_local_environment(): void
    {
        $this->configured(enabled: true);
        $this->actingAs($this->owner());

        // 預設就是 testing 環境。
        $outcome = app(SendLineTestMessage::class)->handle();

        $this->assertFalse($outcome->successful());
        $this->assertSame('outbound_blocked', $outcome->reason);
        Http::assertNothingSent();
    }

    /**
     * ⭐ 自動通知**關閉**時，測試按鈕仍然可用。
     *
     * 這正是它存在的理由：Owner 必須能在開啟自動通知之前先確認設定正確。
     * 若測試也被開關擋住，他就只能盲目開啟，拿真實訂單當測試。
     */
    public function test_the_test_button_works_while_auto_notify_is_off(): void
    {
        $this->configured(enabled: false);
        $this->runningAsLiveSite();
        $this->actingAs($this->owner());

        Http::fake([ProviderEndpoints::LINE_PUSH_MESSAGE => Http::response([], 200)]);

        $outcome = app(SendLineTestMessage::class)->handle();

        $this->assertTrue($outcome->successful());
        Http::assertSentCount(1);
    }

    /** ⛔ credential 不完整時不送，且訊息是白話的。 */
    public function test_an_incomplete_configuration_blocks_the_test(): void
    {
        $this->runningAsLiveSite();
        $this->actingAs($this->owner());

        // 只有接收 ID，沒有 token。
        IntegrationSetting::factory()->create([
            'provider' => IntegrationProvider::LineOrderNotification,
            'environment' => 'production',
            'identifier' => self::TARGET,
            'is_enabled' => false,
        ]);

        $outcome = app(SendLineTestMessage::class)->handle();

        $this->assertFalse($outcome->successful());
        $this->assertStringContainsString('請先儲存', SendLineTestMessage::message($outcome));
        Http::assertNothingSent();
    }

    /** ⛔ 測試訊息是固定文字，⛔ 不接受呼叫端傳入內容。 */
    public function test_the_test_message_is_fixed_text(): void
    {
        $this->configured(enabled: false);
        $this->runningAsLiveSite();
        $this->actingAs($this->owner());

        Http::fake([ProviderEndpoints::LINE_PUSH_MESSAGE => Http::response([], 200)]);

        app(SendLineTestMessage::class)->handle();

        Http::assertSent(function ($request) {
            $text = json_decode($request->body(), true)['messages'][0]['text'];

            return str_contains($text, '測試訊息')
                // ⛔ 測試訊息不得含任何訂單資料。
                && ! str_contains($text, '訂購電郵');
        });
    }

    /**
     * ⛔ 後台訊息只顯示白話，⛔ 不回顯 token、target 或原始 response。
     */
    public function test_the_result_message_never_echoes_a_secret(): void
    {
        $this->configured(enabled: false);
        $this->runningAsLiveSite();
        $this->actingAs($this->owner());

        Http::fake([
            ProviderEndpoints::LINE_PUSH_MESSAGE => Http::response(
                ['message' => 'Invalid channel access token '.self::TOKEN],
                401,
            ),
        ]);

        $outcome = app(SendLineTestMessage::class)->handle();
        $message = SendLineTestMessage::message($outcome);

        foreach ([self::TOKEN, self::TARGET, 'Invalid channel access token'] as $secret) {
            $this->assertStringNotContainsString($secret, $message);
        }

        // outcome 本身也不得帶原文。
        $this->assertStringNotContainsString(self::TOKEN, json_encode($outcome->toLogContext()));
    }
}
