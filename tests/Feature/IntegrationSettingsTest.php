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
use App\Services\Integrations\LiveIntegration;
use App\Services\Integrations\ProviderEndpoints;
use App\Services\Invoices\InvoiceSandboxGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\ConfiguresLiveIntegrations;
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
    use ConfiguresLiveIntegrations;
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

    /**
     * M4C 反轉了這一條：Owner 必須能自己開啟正式通道。
     *
     * 舊規則是「production 不得被啟用」，其代價是 Owner 在後台按了開關卻沒有
     * 反應，然後有人回來改 `.env` 或發一次版——那正是 Owner 明確要求消除的。
     *
     * ⛔ 換掉的只有「誰可以決定」，不是「有沒有規則」：下面幾個測試證明
     * credential 不齊、sandbox 列、非 Owner 與自動派單仍然全部被擋。
     */
    public function test_production_can_be_enabled_once_it_is_fully_configured(): void
    {
        $setting = IntegrationSetting::factory()
            ->forProvider(IntegrationProvider::EcpayPayment, IntegrationEnvironment::Production)
            ->configured()->create();

        $setting->update(['is_enabled' => true]);

        $this->assertTrue($setting->fresh()->is_enabled);
    }

    /**
     * ⛔ credential 不齊時不得開啟，而且規則在 model 層。
     *
     * 前端 disabled 擋不住 `$setting->update(['is_enabled' => true])`，
     * 也擋不住一份手寫的 Livewire payload。
     */
    public function test_an_incompletely_configured_channel_cannot_be_enabled(): void
    {
        $setting = IntegrationSetting::factory()
            ->forProvider(IntegrationProvider::EcpayPayment, IntegrationEnvironment::Production)
            ->create(['identifier' => 'MERCHANT-1']);

        // ⛔ 只有一半的金鑰。
        $setting->credentials = ['HashKey' => 'k'];
        $setting->save();

        $this->expectException(ValidationException::class);

        $setting->update(['is_enabled' => true]);
    }

    /** ⛔ 缺少的欄位名稱必須寫在訊息裡，否則 Owner 只能逐欄猜。 */
    public function test_the_refusal_names_the_missing_fields_without_any_value(): void
    {
        $setting = IntegrationSetting::factory()
            ->forProvider(IntegrationProvider::LinePay, IntegrationEnvironment::Production)
            ->create(['identifier' => 'channel-1']);

        try {
            $setting->update(['is_enabled' => true]);
            $this->fail('缺少 Channel Secret 時不應該可以啟用');
        } catch (ValidationException $e) {
            $message = $e->validator->errors()->first();

            $this->assertStringContainsString('Channel Secret', $message);
            // ⛔ 訊息不得含任何值。
            $this->assertStringNotContainsString('channel-1', $message);
        }
    }

    /**
     * ⛔ sandbox 列不得被開啟。
     *
     * runtime 只讀 production 列，所以一列開著的 sandbox 設定在後台看起來像
     * 「已啟用」，實際上永遠不會被讀到——那是一個會讓人以為已經開始收款的顯示。
     */
    public function test_a_sandbox_row_cannot_be_enabled(): void
    {
        $setting = IntegrationSetting::factory()
            ->forProvider(IntegrationProvider::EcpayPayment, IntegrationEnvironment::Sandbox)
            ->configured()->create();

        $this->expectException(ValidationException::class);

        $setting->update(['is_enabled' => true]);
    }

    /**
     * R1 反轉了這一條:自動派單總開關也交給 Owner。
     *
     * ⛔ model 層仍然把守 credential 完整度與「只有 production 列」;端點與
     * runtime 技術條件由 toggle action(Owner 的唯一路徑)與 dispatch gate
     * (每次送出前)把關——所以一列被偽造 update 出來的 enabled row,在缺
     * 技術條件時仍然一單都派不出去,LiveDispatchOwnerControlTest 另有反證。
     */
    public function test_themostpanel_can_be_enabled_once_configured(): void
    {
        $setting = IntegrationSetting::factory()
            ->forProvider(IntegrationProvider::TheMostPanel)
            ->configured()->create();

        $setting->update(['is_enabled' => true]);

        $this->assertTrue($setting->fresh()->is_enabled);
    }

    /** ⛔ 但沒有 API Key 仍然開不了,而且訊息點名缺什麼。 */
    public function test_themostpanel_cannot_be_enabled_without_a_key(): void
    {
        $setting = IntegrationSetting::factory()
            ->forProvider(IntegrationProvider::TheMostPanel)
            ->create();

        try {
            $setting->update(['is_enabled' => true]);
            $this->fail('缺少 API Key 時不應該可以啟用');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('API Key', $e->validator->errors()->first());
        }
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
     * ⛔ 交易端點必須恰好是官方正式網址，而且固定在版本控制中。
     *
     * 這一條取代了舊的「所有 production 端點必須為空」。端點不再是開關——
     * 它是 SSRF 邊界：一個可以在後台輸入的網址，等於這台伺服器會帶著我們的
     * 金鑰去連任何有人填進去的主機。真正的開關是 Owner 的後台切換。
     */
    public function test_the_transaction_endpoints_are_exactly_the_official_ones(): void
    {
        $endpoints = config('integrations.endpoints');

        $this->assertSame(ProviderEndpoints::ECPAY_PAYMENT, $endpoints['ecpay_payment']['production']);
        $this->assertSame(ProviderEndpoints::LINE_PAY_API, $endpoints['line_pay']['production']);
        $this->assertSame(ProviderEndpoints::ECPAY_INVOICE_ISSUE, $endpoints['ecpay_invoice']['production']);
        $this->assertSame(ProviderEndpoints::ECPAY_INVOICE_QUERY, $endpoints['ecpay_invoice_query']['production']);

        // R1:派單端點也固定為官方正式網址;開不開由 Owner 的總開關決定。
        $this->assertSame(ProviderEndpoints::THEMOSTPANEL_DISPATCH, $endpoints['themostpanel']['production']);
    }

    /**
     * ⛔ 端點不可由資料庫或後台提供。
     *
     * `ProviderEndpoints` 用整串精確比對:設定值被改成任何其他東西時,adapter
     * 在送出一個位元組之前就 fail closed。
     */
    public function test_a_tampered_endpoint_fails_closed(): void
    {
        config()->set('integrations.endpoints.ecpay_payment.production', 'https://evil.example.com/pay');
        $this->assertNull(ProviderEndpoints::ecpayPayment());

        config()->set('integrations.endpoints.line_pay.production', 'https://api-pay.line.me/');
        // ⛔ 只差一個尾斜線也拒絕:需要被「整理」才符合的值,不是白名單裡的那一個。
        $this->assertNull(ProviderEndpoints::linePayApi());
    }

    /**
     * ⛔ R1:code 層的 `enablable` allowlist 已整組移除——沒有任何 provider
     * 需要 code 批准才能啟用。
     *
     * M4C 初版還留著 themostpanel 的鍵;Owner 隨後明確推翻「自動派單另需
     * code 批准」。⛔ 整組必須是 null 而不是空陣列:留著一個空殼,下一個人
     * 會以為還有東西在讀它。
     */
    public function test_the_enablable_allowlist_is_gone_entirely(): void
    {
        $this->assertNull(config('integrations.enablable'));
    }

    /**
     * ⛔ 已 deprecated 的 sandbox 旗標不得再否決 Owner 的後台開關。
     *
     * 這是這一輪最重要的回歸:Owner 之前點綠界付款得到「付款方式目前無法
     * 使用」,根因就是這兩個預設為 false 的旗標。把它們明確設成 false,通道
     * 仍然必須可用——否則就是同一個問題換個地方再發生一次。
     */
    public function test_the_deprecated_sandbox_flags_cannot_override_the_owner_switch(): void
    {
        config()->set('integrations.payments.sandbox_enabled', false);
        config()->set('integrations.invoice.sandbox_enabled', false);
        config()->set('integrations.invoice.gateway', 'fake');

        $this->runningAsLiveSite();
        $this->enableAllChannels();

        $this->assertTrue(LiveIntegration::availableToCustomer(IntegrationProvider::EcpayPayment));
        $this->assertTrue(LiveIntegration::availableToCustomer(IntegrationProvider::LinePay));
        $this->assertNotNull(InvoiceSandboxGuard::setting());
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
