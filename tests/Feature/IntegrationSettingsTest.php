<?php

namespace Tests\Feature;

use App\Actions\Integrations\RecordCredentialAudit;
use App\Actions\Integrations\UpdateIntegrationCredentials;
use App\Enums\IntegrationEnvironment;
use App\Enums\IntegrationProvider;
use App\Filament\Pages\ManageIntegrationSettings;
use App\Models\AdminAuditLog;
use App\Models\IntegrationSetting;
use App\Models\User;
use App\Observers\AuditObserver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Credentials that can move money, and the rules that keep them contained.
 *
 * The thing being defended against is not only an outsider. It is also a
 * routine mistake: re-saving a form and wiping a key nobody retyped, a
 * ciphertext landing in an audit row that gets exported, a "test connection"
 * button that turns out to hit production.
 */
class IntegrationSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // ⛔ 本輪不允許任何外部呼叫；有就直接讓測試失敗。
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

    private function update(): UpdateIntegrationCredentials
    {
        return app(UpdateIntegrationCredentials::class);
    }

    // ============================================ 1. 四個 provider 互相獨立

    public function test_each_provider_keeps_its_own_credentials(): void
    {
        $this->update()->handle(
            IntegrationProvider::EcpayPayment,
            IntegrationEnvironment::Sandbox,
            'PAYMENT-MERCHANT',
            ['HashKey' => 'payment-key', 'HashIV' => 'payment-iv'],
        );

        $this->update()->handle(
            IntegrationProvider::EcpayInvoice,
            IntegrationEnvironment::Sandbox,
            'INVOICE-MERCHANT',
            ['HashKey' => 'invoice-key', 'HashIV' => 'invoice-iv'],
        );

        $payment = IntegrationSetting::where('provider', IntegrationProvider::EcpayPayment)->firstOrFail();
        $invoice = IntegrationSetting::where('provider', IntegrationProvider::EcpayInvoice)->firstOrFail();

        // ⛔ 綠界付款與綠界發票是不同商店帳號，不得互相覆寫。
        $this->assertSame('payment-key', $payment->secret('HashKey'));
        $this->assertSame('invoice-key', $invoice->secret('HashKey'));
        $this->assertSame('PAYMENT-MERCHANT', $payment->identifier);
        $this->assertSame('INVOICE-MERCHANT', $invoice->identifier);
    }

    public function test_updating_payment_does_not_touch_invoice(): void
    {
        $this->update()->handle(IntegrationProvider::EcpayInvoice, IntegrationEnvironment::Sandbox,
            'INVOICE-MERCHANT', ['HashKey' => 'invoice-key', 'HashIV' => 'invoice-iv']);

        $before = IntegrationSetting::where('provider', IntegrationProvider::EcpayInvoice)
            ->firstOrFail()->credentials;

        $this->update()->handle(IntegrationProvider::EcpayPayment, IntegrationEnvironment::Sandbox,
            'PAYMENT-MERCHANT', ['HashKey' => 'changed', 'HashIV' => 'changed']);

        $after = IntegrationSetting::where('provider', IntegrationProvider::EcpayInvoice)
            ->firstOrFail()->credentials;

        $this->assertSame($before, $after);
    }

    public function test_sandbox_and_production_are_separate_rows(): void
    {
        $this->update()->handle(IntegrationProvider::LinePay, IntegrationEnvironment::Sandbox,
            'sandbox-channel', ['ChannelSecret' => 'sandbox-secret']);

        $this->update()->handle(IntegrationProvider::LinePay, IntegrationEnvironment::Production,
            'production-channel', ['ChannelSecret' => 'production-secret']);

        // ⛔ 測試與正式是不同帳號；混在一起就是拿測試金鑰收真錢。
        $this->assertSame(2, IntegrationSetting::where('provider', IntegrationProvider::LinePay)->count());
    }

    // ============================================ 2. 落盤不得有明文

    public function test_raw_database_rows_contain_no_plaintext_secret(): void
    {
        $this->update()->handle(IntegrationProvider::EcpayPayment, IntegrationEnvironment::Sandbox,
            'MERCHANT-1', ['HashKey' => 'super-secret-hash-key', 'HashIV' => 'super-secret-hash-iv']);

        $raw = json_encode(DB::table('integration_settings')->get(), JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('super-secret-hash-key', $raw);
        $this->assertStringNotContainsString('super-secret-hash-iv', $raw);
        // 公開識別碼不是機密，維持明文以便後台辨識。
        $this->assertStringContainsString('MERCHANT-1', $raw);
    }

    public function test_the_server_can_still_read_the_secret_back(): void
    {
        $this->update()->handle(IntegrationProvider::LinePay, IntegrationEnvironment::Sandbox,
            'channel-1', ['ChannelSecret' => 'the-real-secret']);

        $setting = IntegrationSetting::where('provider', IntegrationProvider::LinePay)->firstOrFail();

        // 簽章需要真值；加密只影響落盤。
        $this->assertSame('the-real-secret', $setting->secret('ChannelSecret'));
    }

    public function test_a_secret_is_never_serialised(): void
    {
        $this->update()->handle(IntegrationProvider::LinePay, IntegrationEnvironment::Sandbox,
            'channel-1', ['ChannelSecret' => 'the-real-secret']);

        $setting = IntegrationSetting::where('provider', IntegrationProvider::LinePay)->firstOrFail();

        // ⛔ toArray／toJson 會流進 Livewire state、queue payload 與錯誤頁。
        $this->assertArrayNotHasKey('credentials', $setting->toArray());
        $this->assertStringNotContainsString('the-real-secret', $setting->toJson());
    }

    public function test_an_unknown_secret_key_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        // ⛔ fail closed：不是忽略未知欄位，是拒絕整筆。
        $this->update()->handle(IntegrationProvider::LinePay, IntegrationEnvironment::Sandbox,
            'channel-1', ['ChannelSecret' => 'ok', 'AdminPassword' => 'nope']);
    }

    // ============================================ 3. 空白保留、非空覆寫

    public function test_a_blank_secret_keeps_the_stored_one(): void
    {
        $this->update()->handle(IntegrationProvider::EcpayPayment, IntegrationEnvironment::Sandbox,
            'MERCHANT-1', ['HashKey' => 'original-key', 'HashIV' => 'original-iv']);

        // 只改識別碼，兩個金鑰留白。
        $this->update()->handle(IntegrationProvider::EcpayPayment, IntegrationEnvironment::Sandbox,
            'MERCHANT-2', ['HashKey' => '', 'HashIV' => null]);

        $setting = IntegrationSetting::where('provider', IntegrationProvider::EcpayPayment)->firstOrFail();

        // ⛔ 沒有重打的金鑰不得被清空。
        $this->assertSame('original-key', $setting->secret('HashKey'));
        $this->assertSame('original-iv', $setting->secret('HashIV'));
        $this->assertSame('MERCHANT-2', $setting->identifier);
    }

    public function test_each_secret_can_be_rotated_independently(): void
    {
        $this->update()->handle(IntegrationProvider::EcpayPayment, IntegrationEnvironment::Sandbox,
            'MERCHANT-1', ['HashKey' => 'original-key', 'HashIV' => 'original-iv']);

        $this->update()->handle(IntegrationProvider::EcpayPayment, IntegrationEnvironment::Sandbox,
            null, ['HashKey' => 'rotated-key', 'HashIV' => '']);

        $setting = IntegrationSetting::where('provider', IntegrationProvider::EcpayPayment)->firstOrFail();

        $this->assertSame('rotated-key', $setting->secret('HashKey'));
        $this->assertSame('original-iv', $setting->secret('HashIV'));
    }

    public function test_the_update_reports_only_field_names(): void
    {
        $changed = $this->update()->handle(IntegrationProvider::LinePay, IntegrationEnvironment::Sandbox,
            'channel-1', ['ChannelSecret' => 'the-real-secret']);

        // ⛔ 回傳的是欄位名稱，不是值。
        $this->assertSame(['identifier', 'ChannelSecret'], $changed);
    }

    // ============================================ 4. 稽核不得含明文或密文

    public function test_the_audit_records_field_names_without_values(): void
    {
        $this->actingAs($this->owner());

        $changed = $this->update()->handle(IntegrationProvider::EcpayPayment, IntegrationEnvironment::Sandbox,
            'MERCHANT-1', ['HashKey' => 'super-secret-hash-key', 'HashIV' => 'super-secret-hash-iv']);

        app(RecordCredentialAudit::class)->handle(
            IntegrationProvider::EcpayPayment, IntegrationEnvironment::Sandbox, $changed
        );

        $raw = json_encode(DB::table('admin_audit_logs')->get(), JSON_UNESCAPED_UNICODE);

        // ⛔ 明文、密文與識別碼的值都不得出現。
        $this->assertStringNotContainsString('super-secret-hash-key', $raw);
        $this->assertStringNotContainsString('super-secret-hash-iv', $raw);
        $this->assertStringNotContainsString('MERCHANT-1', $raw);
        $this->assertStringNotContainsString('eyJpdiI6', $raw); // Laravel 密文開頭

        // 但必須查得到「誰改了哪些欄位」。
        $log = AdminAuditLog::latest('id')->firstOrFail();
        $this->assertSame('credentials_updated', $log->action);
        $this->assertSame('ecpay_payment', $log->after['provider']);
        $this->assertContains('HashKey', $log->after['changed_fields']);
    }

    public function test_the_generic_audit_observer_redacts_credentials(): void
    {
        // 就算有人日後把 IntegrationSetting 加進 AUDITED 清單，也不得寫入密文。
        $observer = new AuditObserver;
        $method = new \ReflectionMethod($observer, 'redact');

        $result = $method->invoke($observer, [
            'credentials' => 'eyJpdiI6ImFiYyIsInZhbHVlIjoiZGVmIn0=',
            'identifier' => 'MERCHANT-1',
        ]);

        $this->assertSame('[redacted]', $result['credentials']);
    }

    public function test_no_audit_row_when_nothing_changed(): void
    {
        $this->actingAs($this->owner());

        app(RecordCredentialAudit::class)->handle(
            IntegrationProvider::LinePay, IntegrationEnvironment::Sandbox, []
        );

        $this->assertSame(0, AdminAuditLog::where('action', 'credentials_updated')->count());
    }

    // ============================================ 5. 權限

    public function test_an_owner_can_open_the_page(): void
    {
        $this->actingAs($this->owner());

        $this->assertTrue(ManageIntegrationSettings::canAccess());
        Livewire::test(ManageIntegrationSettings::class)->assertOk();
    }

    public function test_an_editor_cannot_open_the_page(): void
    {
        $this->actingAs($this->editor());

        $this->assertFalse(ManageIntegrationSettings::canAccess());
    }

    public function test_a_guest_cannot_open_the_page(): void
    {
        $this->assertFalse(ManageIntegrationSettings::canAccess());
    }

    public function test_an_inactive_owner_cannot_open_the_page(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner', 'is_active' => false]));

        // ⛔ 停用的帳號不得因為角色還是 owner 就通過。
        $this->assertFalse(ManageIntegrationSettings::canAccess());
    }

    public function test_an_editor_cannot_save_through_a_forged_livewire_call(): void
    {
        $this->actingAs($this->editor());

        // ⛔ 直接呼叫 save()，完全繞過畫面與 canAccess() 的選單隱藏。
        $page = new ManageIntegrationSettings;

        try {
            $page->save(app(UpdateIntegrationCredentials::class), app(RecordCredentialAudit::class));
            $this->fail('editor 不應該能儲存 credential。');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertSame(0, IntegrationSetting::count());
    }

    public function test_a_guest_cannot_save_through_a_forged_livewire_call(): void
    {
        $page = new ManageIntegrationSettings;

        try {
            $page->save(app(UpdateIntegrationCredentials::class), app(RecordCredentialAudit::class));
            $this->fail('未登入者不應該能儲存 credential。');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertSame(0, IntegrationSetting::count());
    }

    public function test_an_editor_cannot_mount_the_page(): void
    {
        $this->actingAs($this->editor());

        $page = new ManageIntegrationSettings;

        try {
            $page->mount();
            $this->fail('editor 不應該能載入這一頁。');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    // ============================================ 6. 啟用限制與 SSRF

    public function test_production_cannot_be_enabled(): void
    {
        $setting = IntegrationSetting::factory()
            ->forProvider(IntegrationProvider::EcpayPayment, IntegrationEnvironment::Production)
            ->configured()->create();

        $this->expectException(ValidationException::class);

        // ⛔ 前端 disabled 擋不住這種寫法，所以規則在 model 層。
        $setting->update(['is_enabled' => true]);
    }

    public function test_themostpanel_cannot_be_enabled(): void
    {
        $setting = IntegrationSetting::factory()
            ->forProvider(IntegrationProvider::TheMostPanel)
            ->configured()->create();

        $this->expectException(ValidationException::class);

        $setting->update(['is_enabled' => true]);
    }

    public function test_themostpanel_has_no_sandbox(): void
    {
        // ⛔ 不得杜撰一個不存在的安全測試環境。
        $this->assertSame(
            [IntegrationEnvironment::Production],
            IntegrationProvider::TheMostPanel->environments()
        );
        $this->assertFalse(IntegrationProvider::TheMostPanel->supports(IntegrationEnvironment::Sandbox));
    }

    public function test_an_unsupported_environment_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        $this->update()->handle(IntegrationProvider::TheMostPanel, IntegrationEnvironment::Sandbox,
            null, ['ApiKey' => 'x']);
    }

    public function test_there_is_no_endpoint_column(): void
    {
        // ⛔ 可由後台輸入的網址等於 SSRF：伺服器會帶著憑證連任何被填入的主機。
        $columns = Schema::getColumnListing('integration_settings');

        foreach (['endpoint', 'url', 'host', 'base_uri', 'callback_url'] as $forbidden) {
            $this->assertNotContains($forbidden, $columns);
        }
    }

    /**
     * ⛔ 只有已核對過的 sandbox 端點可以存在。
     *
     * M3B-B1 批准了綠界 stage 與 LINE Pay sandbox，所以這兩個不再是空的；
     * 其餘一律留空——⛔ 端點不得「先填好等人誤用」，尤其是 production。
     */
    public function test_only_approved_sandbox_endpoints_exist(): void
    {
        $endpoints = config('integrations.endpoints');

        // 已批准：綠界 stage 與 LINE Pay sandbox。
        $this->assertStringStartsWith('https://payment-stage.ecpay.com.tw/', $endpoints['ecpay_payment']['sandbox']);
        $this->assertSame('https://sandbox-api-pay.line.me', $endpoints['line_pay']['sandbox']);

        // ⛔ 所有 production 端點仍為空。
        foreach ($endpoints as $provider => $environments) {
            $this->assertSame('', $environments['production'] ?? '', "{$provider} 的 production 端點不得填入");
        }

        // B2 已批准綠界 stage 發票端點；⛔ 只接受官方 stage 主機。
        $this->assertStringStartsWith(
            'https://einvoice-stage.ecpay.com.tw/',
            $endpoints['ecpay_invoice']['sandbox']
        );
        // ⛔ TheMostPanel 完全未證實，仍留空。
        $this->assertSame('', $endpoints['themostpanel']['production']);
    }

    public function test_only_sandbox_payments_are_enablable(): void
    {
        $enablable = config('integrations.enablable');

        // 已批准的 sandbox 付款測試。
        $this->assertTrue($enablable['ecpay_payment']['sandbox']);
        $this->assertTrue($enablable['line_pay']['sandbox']);

        // ⛔ 任何 production 都不得被啟用。
        foreach ($enablable as $provider => $environments) {
            $this->assertFalse($environments['production'] ?? false, "{$provider} production 不得可啟用");
        }

        // ⛔ 發票與 TheMostPanel 本輪都不開放。
        $this->assertFalse($enablable['ecpay_invoice']['sandbox']);
    }

    public function test_sandbox_payments_are_off_by_default(): void
    {
        // ⛔ 總開關預設關閉：填了 credential 也不等於開始送出請求。
        $this->assertFalse(config('integrations.payments.sandbox_enabled'));
    }

    public function test_a_raw_response_cannot_be_stored_as_a_test_message(): void
    {
        $setting = IntegrationSetting::factory()->create();

        $this->expectException(ValidationException::class);

        // ⛔ 對方的原始回應常回音請求內容，等於把憑證寫進非機密欄位。
        $setting->update(['last_test_message' => '{"MerchantID":"X","HashKey":"leaked"}']);
    }

    public function test_a_setting_is_disabled_by_default(): void
    {
        $setting = IntegrationSetting::factory()->create();

        // 新增一組 credential 不等於同意開始交易。
        $this->assertFalse($setting->is_enabled);
    }

    public function test_no_credential_ships_in_the_repository(): void
    {
        // ⛔ 種子檔不得內建任何金鑰。
        $seeders = glob(database_path('seeders/*.php'));

        foreach ($seeders as $file) {
            $source = file_get_contents($file);
            $this->assertStringNotContainsString('HashKey', $source);
            $this->assertStringNotContainsString('ChannelSecret', $source);
            $this->assertStringNotContainsString('integration_settings', $source);
        }
    }
}
