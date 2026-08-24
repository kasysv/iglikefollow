<?php

namespace Tests\Feature;

use App\Actions\Integrations\ToggleIntegrationChannel;
use App\Actions\Integrations\UpdateIntegrationCredentials;
use App\Actions\Invoices\CreateInvoiceForPaidOrder;
use App\Enums\IntegrationEnvironment;
use App\Enums\IntegrationProvider;
use App\Enums\InvoiceStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Filament\Pages\ManageIntegrationSettings;
use App\Jobs\IssueInvoiceForOrder;
use App\Models\AdminAuditLog;
use App\Models\IntegrationSetting;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\User;
use App\Services\Integrations\LiveIntegration;
use App\Services\Integrations\ProviderEndpoints;
use App\Services\Payments\PaymentGatewayRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\ConfiguresLiveIntegrations;
use Tests\TestCase;

/**
 * M4C-LIVE-PAYMENT-OWNER-CONTROL-A: the Owner's switches, and what they cannot do.
 *
 * Owner 於 2026-08-24 明確改變方向:網站直接按正式營運設計,後台不再區分
 * sandbox／production,而「這個通道要不要收款」是 Owner 在後台的決定——不是
 * 改 `.env`、不是改 code、不是發一次版。
 *
 * ⛔ 這一檔要同時證明兩件相反的事:
 *   1. Owner 真的能自己開關,而且開了就真的可用、關了就真的不可用;
 *   2. 那份權力沒有順手變成別的東西——密鑰不外洩、遮罩不會被存成密鑰、
 *      非 Owner 擋在門外、本機永不外呼、端點不可被輸入、自動派單仍未開放。
 *
 * ⛔ 全程 `Http::preventStrayRequests()`:任何外部呼叫都會讓測試失敗。
 * 切換一個開關、儲存一次 credential,都不該產生一個 byte 的對外流量。
 */
class LivePaymentOwnerControlTest extends TestCase
{
    use ConfiguresLiveIntegrations;
    use RefreshDatabase;

    /**
     * 一個明顯假造的密鑰標記。
     *
     * ⛔ 任何真實或官方公布的金鑰都不該進 repository:這裡的東西對每一個能讀到
     * 這個 repo 的人都是公開的。
     */
    private const SECRET_MARKER = 'FAKE-SECRET-MARKER-M4C-77219';

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

    private function toggle(): ToggleIntegrationChannel
    {
        return app(ToggleIntegrationChannel::class);
    }

    private function registry(): PaymentGatewayRegistry
    {
        return app(PaymentGatewayRegistry::class);
    }

    // ==================================== 1. Owner 的開關真的有作用

    /**
     * 這是整輪的核心:填完 credential → 在後台按啟用 → 通道真的可用。
     *
     * ⛔ 全程不改 `.env`、不改 code、不重新部署。Owner 之前點綠界付款得到
     * 「付款方式目前無法使用」,根因就是那個必須改 `.env` 的旗標。
     */
    public function test_the_owner_can_enable_a_channel_from_the_admin_alone(): void
    {
        $this->runningAsLiveSite();

        $this->configureChannelWithoutEnabling(IntegrationProvider::EcpayPayment, '3000001');
        $this->assertFalse($this->registry()->availableToCustomer('ecpay'));

        $this->assertTrue($this->toggle()->handle(IntegrationProvider::EcpayPayment, true));

        $this->assertTrue($this->registry()->availableToCustomer('ecpay'));
        $this->assertNotNull($this->registry()->for('ecpay'));
    }

    /** ⛔ 停用必須立即生效,而且不需要任何前置條件。 */
    public function test_disabling_takes_effect_immediately(): void
    {
        $this->runningAsLiveSite();
        $this->enableChannel(IntegrationProvider::EcpayPayment, '3000001');

        $this->assertFalse($this->toggle()->handle(IntegrationProvider::EcpayPayment, false));

        $this->assertFalse($this->registry()->availableToCustomer('ecpay'));
        $this->assertNull($this->registry()->for('ecpay'));
    }

    /**
     * ⛔ 停用永遠允許,即使 credential 已經不完整。
     *
     * 讓「關掉」也要通過啟用檢查,會出現「因為金鑰不全,所以不能停止收款」
     * 這種荒謬的失敗——而那正是最需要能關掉的時候。
     */
    public function test_disabling_works_even_when_the_credentials_became_incomplete(): void
    {
        $this->runningAsLiveSite();
        $setting = $this->enableChannel(IntegrationProvider::EcpayPayment, '3000001');

        // 繞過 model 把 credential 弄壞,模擬資料損壞或部分刪除。
        DB::table('integration_settings')->where('id', $setting->id)->update(['identifier' => null]);

        $this->assertFalse($this->toggle()->handle(IntegrationProvider::EcpayPayment, false));
        $this->assertFalse((bool) DB::table('integration_settings')->where('id', $setting->id)->value('is_enabled'));
    }

    /** ⛔ credential 不齊時後端拒絕啟用,而且指出缺少哪些欄位。 */
    public function test_enabling_without_credentials_is_refused_and_names_the_fields(): void
    {
        $this->runningAsLiveSite();

        IntegrationSetting::factory()
            ->forProvider(IntegrationProvider::LinePay, IntegrationEnvironment::Production)
            ->create(['identifier' => 'channel-0001']);

        try {
            $this->toggle()->handle(IntegrationProvider::LinePay, true);
            $this->fail('缺少 Channel Secret 時不應該可以啟用');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Channel Secret', $e->validator->errors()->first());
        }

        $this->assertFalse($this->registry()->availableToCustomer('line-pay'));
    }

    /** ⛔ 完全沒有設定卻要求啟用:拒絕,而且不順手建一列空的。 */
    public function test_enabling_a_channel_with_no_row_does_not_create_one(): void
    {
        $this->expectException(ValidationException::class);

        try {
            $this->toggle()->handle(IntegrationProvider::EcpayPayment, true);
        } finally {
            $this->assertSame(0, IntegrationSetting::query()->count());
        }
    }

    /** ⛔ 沒有變化時不寫稽核:一堆「什麼都沒改」的紀錄會把真的變更蓋掉。 */
    public function test_a_no_op_toggle_writes_no_audit(): void
    {
        $this->runningAsLiveSite();
        $this->enableChannel(IntegrationProvider::EcpayPayment, '3000001');
        AdminAuditLog::query()->delete();

        $this->toggle()->handle(IntegrationProvider::EcpayPayment, true);

        $this->assertSame(0, AdminAuditLog::query()->count());
    }

    /** ⛔ 每次真正的切換都要留稽核,而且不含 secret 或密文。 */
    public function test_each_real_toggle_is_audited_without_any_secret(): void
    {
        $this->runningAsLiveSite();
        $setting = $this->configureChannelWithoutEnabling(IntegrationProvider::EcpayPayment, '3000001');
        $setting->credentials = ['HashKey' => self::SECRET_MARKER, 'HashIV' => self::SECRET_MARKER];
        $setting->save();
        AdminAuditLog::query()->delete();

        $this->toggle()->handle(IntegrationProvider::EcpayPayment, true);
        $this->toggle()->handle(IntegrationProvider::EcpayPayment, false);

        $this->assertSame(2, AdminAuditLog::query()->count());

        $raw = AdminAuditLog::query()->get()->toJson();
        $this->assertStringNotContainsString(self::SECRET_MARKER, $raw);
        // ⛔ 密文也不行:那是密鑰加上一段延遲——備份會旅行,而金鑰在同一台機器上。
        $this->assertStringNotContainsString('eyJpdiI6', $raw);
        $this->assertStringContainsString('is_enabled:on', $raw);
        $this->assertStringContainsString('is_enabled:off', $raw);
    }

    // ==================================== 2. 只有 Owner 能切換

    /**
     * 直接呼叫 `toggleChannel()`,完全繞過畫面。
     *
     * ⛔ 這才是真正的威脅模型:一份手寫的 Livewire payload 不會經過選單隱藏、
     * 不會經過 disabled 屬性,也不會經過 `Livewire::test()` 的 mount。
     *
     * @return int 被擋下時的 HTTP 狀態碼
     */
    private function toggleAsUnauthorised(?User $user, string $provider): int
    {
        if ($user !== null) {
            $this->actingAs($user);
        }

        $page = new ManageIntegrationSettings;

        try {
            $page->toggleChannel($provider, true, $this->toggle());
            $this->fail('未授權的呼叫不應該成功。');
        } catch (HttpException $e) {
            return $e->getStatusCode();
        }
    }

    public function test_an_editor_cannot_toggle_through_a_forged_livewire_call(): void
    {
        $this->configureChannelWithoutEnabling(IntegrationProvider::EcpayPayment, '3000001');

        $this->assertSame(403, $this->toggleAsUnauthorised($this->editor(), IntegrationProvider::EcpayPayment->value));
        $this->assertFalse(LiveIntegration::enabledByOwner(IntegrationProvider::EcpayPayment));
    }

    public function test_a_guest_cannot_toggle_through_a_forged_livewire_call(): void
    {
        $this->configureChannelWithoutEnabling(IntegrationProvider::EcpayPayment, '3000001');

        $this->assertSame(403, $this->toggleAsUnauthorised(null, IntegrationProvider::EcpayPayment->value));
        $this->assertFalse(LiveIntegration::enabledByOwner(IntegrationProvider::EcpayPayment));
    }

    /** ⛔ 停用中的 Owner 帳號同樣沒有權限。 */
    public function test_an_inactive_owner_cannot_toggle(): void
    {
        $this->configureChannelWithoutEnabling(IntegrationProvider::EcpayPayment, '3000001');
        $inactive = User::factory()->create(['role' => 'owner', 'is_active' => false]);

        $this->assertSame(403, $this->toggleAsUnauthorised($inactive, IntegrationProvider::EcpayPayment->value));
        $this->assertFalse(LiveIntegration::enabledByOwner(IntegrationProvider::EcpayPayment));
    }

    /** ⛔ 不認識的 provider 一律拒絕，不是忽略。 */
    public function test_an_unknown_provider_is_refused(): void
    {
        $this->actingAs($this->owner());
        $page = new ManageIntegrationSettings;

        try {
            $page->toggleChannel('not-a-real-provider', true, $this->toggle());
            $this->fail('未知 provider 不應該被接受。');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
    }

    /**
     * ⛔ 自動派單不是 Owner 的後台開關。
     *
     * 開啟它會開始對外花錢下單，那還不是營運決定；它的批准仍必須是一次
     * reviewed 的 code 變更。⛔ 這道檢查在後端，不只在畫面上。
     */
    /**
     * R1 反轉了「自動派單不是 Owner 的後台開關」:Owner 明確要求總開關也放進
     * 同一個後台。這裡只釘住兩件仍然為真的事——完整矩陣在
     * `LiveDispatchOwnerControlTest`。
     */
    public function test_dispatch_is_owner_togglable_once_the_runtime_supports_it(): void
    {
        $this->withSupportedDispatchRuntime();

        IntegrationSetting::factory()
            ->forProvider(IntegrationProvider::TheMostPanel)
            ->configured()->create();

        $this->assertTrue($this->toggle()->handle(IntegrationProvider::TheMostPanel, true));
        $this->assertTrue(LiveIntegration::enabledByOwner(IntegrationProvider::TheMostPanel));
    }

    /** ⛔ runtime 不支援時拒絕開啟,訊息用白話點名主機環境。 */
    public function test_dispatch_cannot_be_enabled_on_an_unsupported_runtime(): void
    {
        $this->withUnsupportedDispatchRuntime();

        $setting = IntegrationSetting::factory()
            ->forProvider(IntegrationProvider::TheMostPanel)
            ->configured()->create();

        try {
            $this->toggle()->handle(IntegrationProvider::TheMostPanel, true);
            $this->fail('runtime 不支援時不應該可以啟用自動派單');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('主機環境不支援', $e->validator->errors()->first());
        }

        $this->assertFalse((bool) $setting->fresh()->is_enabled);
    }

    // ==================================== 3. 遮罩與密鑰不外洩

    /** ⛔ 已設定的密鑰只顯示固定遮罩,真值不進 HTML／Livewire state。 */
    public function test_a_stored_secret_never_reaches_the_browser(): void
    {
        $setting = $this->configureChannelWithoutEnabling(IntegrationProvider::EcpayPayment, '3000001');
        $setting->credentials = ['HashKey' => self::SECRET_MARKER, 'HashIV' => self::SECRET_MARKER];
        $setting->save();

        $rendered = Livewire::actingAs($this->owner())
            ->test(ManageIntegrationSettings::class);

        $html = $rendered->html();

        $this->assertStringNotContainsString(self::SECRET_MARKER, $html);
        $this->assertStringContainsString(ManageIntegrationSettings::MASK, $html);

        // ⛔ Livewire state 也不能帶著它走。
        $this->assertStringNotContainsString(self::SECRET_MARKER, json_encode($rendered->get('data')));
    }

    /** ⛔ 星號數量固定,不隨真實金鑰長度改變——會變的遮罩是慢一點的洩漏。 */
    public function test_the_mask_length_does_not_depend_on_the_secret(): void
    {
        $short = $this->configureChannelWithoutEnabling(IntegrationProvider::EcpayPayment, '3000001');
        $short->credentials = ['HashKey' => 'ab', 'HashIV' => 'cd'];
        $short->save();

        $shortHtml = Livewire::actingAs($this->owner())->test(ManageIntegrationSettings::class)->html();

        $long = LiveIntegration::row(IntegrationProvider::EcpayPayment);
        $long->credentials = ['HashKey' => str_repeat('x', 200), 'HashIV' => str_repeat('y', 200)];
        $long->save();

        $longHtml = Livewire::actingAs($this->owner())->test(ManageIntegrationSettings::class)->html();

        $mask = ManageIntegrationSettings::MASK;
        $this->assertSame(substr_count($shortHtml, $mask), substr_count($longHtml, $mask));
        $this->assertStringNotContainsString(str_repeat('x', 200), $longHtml);
    }

    /** 未設定時顯示「尚未設定」,⛔ 不顯示遮罩假裝已經有值。 */
    public function test_an_unset_secret_says_so_instead_of_showing_a_mask(): void
    {
        $html = Livewire::actingAs($this->owner())->test(ManageIntegrationSettings::class)->html();

        $this->assertStringContainsString('尚未設定', $html);
    }

    /**
     * ⛔ 遮罩字串永遠不會被當成新密鑰寫入。
     *
     * 正常操作不會送出它(真值不回灌到輸入框),但一份手寫的 payload、一個自動
     * 填表的擴充,或某天有人「照著畫面上看到的」貼回來,都可能送進來。真的存
     * 下去,結果是這個通道帶著八個星號去簽章,而後台顯示「已設定」。
     */
    public function test_the_mask_is_never_stored_as_a_secret(): void
    {
        $setting = $this->configureChannelWithoutEnabling(IntegrationProvider::EcpayPayment, '3000001');
        $setting->credentials = ['HashKey' => self::SECRET_MARKER, 'HashIV' => self::SECRET_MARKER];
        $setting->save();

        $changed = app(UpdateIntegrationCredentials::class)->handle(
            IntegrationProvider::EcpayPayment,
            IntegrationEnvironment::Production,
            '3000001',
            ['HashKey' => UpdateIntegrationCredentials::MASK, 'HashIV' => UpdateIntegrationCredentials::MASK],
        );

        // ⛔ no-op,不是「寫入成功」。
        $this->assertSame([], $changed);
        $this->assertSame(self::SECRET_MARKER, LiveIntegration::row(IntegrationProvider::EcpayPayment)->secret('HashKey'));
    }

    /** ⛔ 空白同樣保留原值:改 MerchantID 不該把沒重打的金鑰清掉。 */
    public function test_a_blank_field_keeps_the_stored_secret(): void
    {
        $setting = $this->configureChannelWithoutEnabling(IntegrationProvider::EcpayPayment, '3000001');
        $setting->credentials = ['HashKey' => self::SECRET_MARKER, 'HashIV' => self::SECRET_MARKER];
        $setting->save();

        app(UpdateIntegrationCredentials::class)->handle(
            IntegrationProvider::EcpayPayment,
            IntegrationEnvironment::Production,
            '3000002',
            ['HashKey' => '', 'HashIV' => null],
        );

        $fresh = LiveIntegration::row(IntegrationProvider::EcpayPayment);
        $this->assertSame('3000002', $fresh->identifier);
        $this->assertSame(self::SECRET_MARKER, $fresh->secret('HashKey'));
    }

    /** ⛔ 但真的新值必須寫得進去,否則就沒辦法換金鑰了。 */
    public function test_a_real_new_secret_replaces_the_old_one(): void
    {
        $setting = $this->configureChannelWithoutEnabling(IntegrationProvider::EcpayPayment, '3000001');
        $setting->credentials = ['HashKey' => 'old-value', 'HashIV' => 'old-value'];
        $setting->save();

        app(UpdateIntegrationCredentials::class)->handle(
            IntegrationProvider::EcpayPayment,
            IntegrationEnvironment::Production,
            '3000001',
            ['HashKey' => self::SECRET_MARKER, 'HashIV' => null],
        );

        $fresh = LiveIntegration::row(IntegrationProvider::EcpayPayment);
        $this->assertSame(self::SECRET_MARKER, $fresh->secret('HashKey'));
        // ⛔ 一次只換一個:另一個金鑰不必知道也不該被動到。
        $this->assertSame('old-value', $fresh->secret('HashIV'));
    }

    /** MerchantID／Channel ID 是識別碼,可以明文顯示讓 Owner 核對。 */
    public function test_the_public_identifier_is_shown_in_clear_text(): void
    {
        $this->configureChannelWithoutEnabling(IntegrationProvider::EcpayPayment, '3000001');

        $state = Livewire::actingAs($this->owner())
            ->test(ManageIntegrationSettings::class)
            ->get('data');

        $this->assertSame('3000001', $state['ecpay_payment_identifier']);
    }

    // ==================================== 4. 前後端可用性一致

    /**
     * availability matrix:綠界／LINE／兩者／全無,前後端逐格一致。
     *
     * ⛔ 這是這一輪最容易出錯的地方:結帳頁與 `payments.start` 各算一次,就會
     * 出現畫面上可以選、送出後被拒——而客人已經填完整張表單了。
     *
     * @param  list<string>  $enable
     * @param  list<string>  $expected
     */
    #[DataProvider('availabilityMatrixProvider')]
    public function test_the_availability_matrix_agrees_front_and_back(array $enable, array $expected): void
    {
        $this->runningAsLiveSite();

        foreach ($enable as $provider) {
            $this->enableChannel(
                $provider === 'ecpay' ? IntegrationProvider::EcpayPayment : IntegrationProvider::LinePay,
                $provider === 'ecpay' ? '3000001' : 'channel-0001',
            );
        }

        // 後端:registry 就是 `payments.start` 用的那一個。
        $this->assertSame($expected, $this->registry()->availableProviders());

        foreach (['ecpay', 'line-pay'] as $provider) {
            $available = in_array($provider, $expected, true);

            $this->assertSame($available, $this->registry()->availableToCustomer($provider), $provider);
            // ⛔ 不可用時連 adapter 都不存在。
            $this->assertSame($available, $this->registry()->for($provider) !== null, $provider);
        }
    }

    /** @return array<string, array{list<string>, list<string>}> */
    public static function availabilityMatrixProvider(): array
    {
        return [
            'none' => [[], []],
            'ecpay only' => [['ecpay'], ['ecpay']],
            'line only' => [['line-pay'], ['line-pay']],
            'both' => [['ecpay', 'line-pay'], ['ecpay', 'line-pay']],
        ];
    }

    /**
     * ⛔ 開啟一個 provider 不得連帶開啟另一個。
     *
     * 共用一個布林值的舊設計會讓「開了其中一個」變成「兩個都開了」,而另一個
     * 沒有 credential——客人選了它,按下去只會得到失敗。
     */
    public function test_enabling_payment_does_not_enable_invoice_or_the_other_provider(): void
    {
        $this->runningAsLiveSite();
        $this->enableChannel(IntegrationProvider::EcpayPayment, '3000001');

        $this->assertTrue(LiveIntegration::availableToCustomer(IntegrationProvider::EcpayPayment));
        $this->assertFalse(LiveIntegration::availableToCustomer(IntegrationProvider::LinePay));
        // ⛔ 綠界付款與綠界發票是不同的商店帳號,不同的金鑰。
        $this->assertFalse(LiveIntegration::availableToCustomer(IntegrationProvider::EcpayInvoice));
    }

    // ==================================== 4b. 發票開關與付款是分開的

    /**
     * ⛔ 發票通道關閉時,付款仍然成功——只是不送開票請求。
     *
     * 把兩者綁在一起,等於「不想開發票」就收不到錢。而且更糟的是反過來:
     * 如果收款成功卻因為發票通道關著就整個流程炸掉,錢已經進來了而訂單壞了。
     *
     * ⛔ 這一輪我真的踩到那個坑:`IssueInvoiceForOrder` 原本從方法簽章注入
     * `IssueInvoice`,container 會在進入方法主體之前解析它的 `InvoiceGateway`
     * ——而那個 binding 在發票通道未啟用的正式環境會拋出。付款成功後那個 job
     * 直接爆掉。現在改成確定要開票之後才解析。
     */
    public function test_a_paid_order_survives_with_the_invoice_channel_switched_off(): void
    {
        $this->runningAsLiveSite();

        // 只開付款,不開發票。
        $this->enableChannel(IntegrationProvider::EcpayPayment, '3000001');

        $order = Order::factory()->create([
            'order_status' => OrderStatus::Paid,
            'payment_status' => PaymentStatus::Succeeded,
            'total_amount' => 590,
            'paid_at' => now(),
            'customer_email' => 'buyer@example.invalid',
            'invoice_kind' => 'personal',
            'personal_invoice_mode' => 'email',
        ]);

        Http::fake();

        // ⛔ job 必須跑得完,不得拋出。
        app(IssueInvoiceForOrder::class, ['orderId' => $order->id])
            ->handle(app(CreateInvoiceForPaidOrder::class));

        $invoice = Invoice::query()->where('order_id', $order->id)->first();

        $this->assertNotNull($invoice);
        // ⛔ 停在「待設定」,不是假裝已開立。
        $this->assertSame(InvoiceStatus::PendingConfiguration, $invoice->status);

        // ⛔ 訂單仍然是已付款:發票通道關著不會把收款結果推翻。
        $this->assertSame(PaymentStatus::Succeeded, $order->fresh()->payment_status);

        // ⛔ 0 次外部呼叫。
        Http::assertNothingSent();
    }

    /** ⛔ 反過來:只開發票不開付款,付款方式必須仍然不可用。 */
    public function test_enabling_the_invoice_channel_does_not_open_payment(): void
    {
        $this->runningAsLiveSite();
        $this->enableChannel(IntegrationProvider::EcpayInvoice, '3000001');

        $this->assertSame([], $this->registry()->availableProviders());
    }

    // ==================================== 5. 剩下的環境邊界

    /**
     * ⛔ 本機／測試環境永遠不對外送出,即使通道全開。
     *
     * M4C 之後 production 是允許的環境;剩下的環境邊界是這一條,而它是技術
     * 邊界,不是 Owner 的營運開關。少了它,任何開發機器只要有一份正式
     * credential 就會開始真的收款。
     */
    #[DataProvider('offlineEnvironmentProvider')]
    public function test_an_offline_environment_never_becomes_available(string $env): void
    {
        $this->runningAsLiveSite($env);
        $this->enableAllChannels();

        $this->assertFalse(LiveIntegration::outboundAllowed());

        foreach (IntegrationProvider::cases() as $provider) {
            $this->assertNull(LiveIntegration::setting($provider), $provider->value);
        }
    }

    /** @return array<string, array{string}> */
    public static function offlineEnvironmentProvider(): array
    {
        return ['local' => ['local'], 'testing' => ['testing']];
    }

    /** ⛔ 但「Owner 開了嗎」與「現在能不能送」必須分開回答。 */
    public function test_the_owner_switch_is_still_readable_on_a_local_machine(): void
    {
        $this->runningAsLiveSite('local');
        $this->enableChannel(IntegrationProvider::EcpayPayment, '3000001');

        // 開關本身是開的——後台必須看得到這件事。
        $this->assertTrue(LiveIntegration::enabledByOwner(IntegrationProvider::EcpayPayment));
        // ⛔ 但現在不可用,所以不得顯示成「正在收款」。
        $this->assertFalse(LiveIntegration::availableToCustomer(IntegrationProvider::EcpayPayment));
    }

    // ==================================== 6. 端點白名單

    /** ⛔ 資料庫沒有任何可以放網址的欄位。 */
    public function test_no_endpoint_can_be_supplied_by_the_database(): void
    {
        $columns = Schema::getColumnListing('integration_settings');

        foreach (['endpoint', 'url', 'host', 'base_uri', 'callback_url', 'api_url'] as $forbidden) {
            $this->assertNotContains($forbidden, $columns);
        }
    }

    /**
     * ⛔ 每一個近似值都必須被拒絕。
     *
     * 整串比對而不是逐段解析:每多一段就多一個忘記檢查的機會——query、
     * fragment、userinfo、port 少查一個就是一個缺口。
     */
    #[DataProvider('tamperedEndpointProvider')]
    public function test_a_tampered_endpoint_fails_closed(string $key, string $method, string $value): void
    {
        config()->set("integrations.endpoints.{$key}.production", $value);

        $this->assertNull(ProviderEndpoints::{$method}());
    }

    /** @return array<string, array{string, string, string}> */
    public static function tamperedEndpointProvider(): array
    {
        return [
            'payment blank' => ['ecpay_payment', 'ecpayPayment', ''],
            'payment stage host' => ['ecpay_payment', 'ecpayPayment', 'https://payment-stage.ecpay.com.tw/Cashier/AioCheckOut/V5'],
            'payment http' => ['ecpay_payment', 'ecpayPayment', 'http://payment.ecpay.com.tw/Cashier/AioCheckOut/V5'],
            'payment lookalike' => ['ecpay_payment', 'ecpayPayment', 'https://payment.ecpay.com.tw.evil.example/Cashier/AioCheckOut/V5'],
            'payment userinfo' => ['ecpay_payment', 'ecpayPayment', 'https://user@payment.ecpay.com.tw/Cashier/AioCheckOut/V5'],
            'payment port' => ['ecpay_payment', 'ecpayPayment', 'https://payment.ecpay.com.tw:8443/Cashier/AioCheckOut/V5'],
            'payment query' => ['ecpay_payment', 'ecpayPayment', 'https://payment.ecpay.com.tw/Cashier/AioCheckOut/V5?x=1'],
            'payment fragment' => ['ecpay_payment', 'ecpayPayment', 'https://payment.ecpay.com.tw/Cashier/AioCheckOut/V5#f'],
            'payment trailing slash' => ['ecpay_payment', 'ecpayPayment', 'https://payment.ecpay.com.tw/Cashier/AioCheckOut/V5/'],
            'payment v4' => ['ecpay_payment', 'ecpayPayment', 'https://payment.ecpay.com.tw/Cashier/AioCheckOut/V4'],
            'line blank' => ['line_pay', 'linePayApi', ''],
            'line sandbox' => ['line_pay', 'linePayApi', 'https://sandbox-api-pay.line.me'],
            'line trailing slash' => ['line_pay', 'linePayApi', 'https://api-pay.line.me/'],
            'line http' => ['line_pay', 'linePayApi', 'http://api-pay.line.me'],
            'line lookalike' => ['line_pay', 'linePayApi', 'https://api-pay.line.me.evil.example'],
            'invoice blank' => ['ecpay_invoice', 'ecpayInvoiceIssue', ''],
            'invoice stage' => ['ecpay_invoice', 'ecpayInvoiceIssue', 'https://einvoice-stage.ecpay.com.tw/B2CInvoice/Issue'],
            // ⛔ 同主機、不同 operation:作廢發票絕不能因為主機對就送出去。
            'invoice invalid op' => ['ecpay_invoice', 'ecpayInvoiceIssue', 'https://einvoice.ecpay.com.tw/B2CInvoice/Invalid'],
            'query blank' => ['ecpay_invoice_query', 'ecpayInvoiceQuery', ''],
            // ⛔ 查詢端點被換成 Issue 就變成「重開一張」,最危險的一種錯配。
            'query is issue' => ['ecpay_invoice_query', 'ecpayInvoiceQuery', 'https://einvoice.ecpay.com.tw/B2CInvoice/Issue'],
        ];
    }

    /** ⛔ 導向網址:只接受官方付款頁主機,而且不接受 userinfo 或自訂 port。 */
    #[DataProvider('redirectProvider')]
    public function test_only_the_official_payment_page_may_receive_a_customer(string $url, bool $allowed): void
    {
        $this->assertSame($allowed, ProviderEndpoints::redirectIsAllowed($url));
    }

    /** @return array<string, array{string, bool}> */
    public static function redirectProvider(): array
    {
        return [
            'official page' => ['https://web-pay.line.me/web/payment', true],
            'official page with path' => ['https://web-pay.line.me/web/payment/wait?transactionId=1', true],
            'sandbox page' => ['https://sandbox-web-pay.line.me/web/payment', false],
            'api host' => ['https://api-pay.line.me/v4/payments', false],
            'http' => ['http://web-pay.line.me/web/payment', false],
            'lookalike' => ['https://web-pay.line.me.evil.example/x', false],
            // ⛔ 真正的主機是 evil.example;只比對前綴的寫法會被它騙過去。
            'userinfo' => ['https://web-pay.line.me@evil.example/x', false],
            'explicit port' => ['https://web-pay.line.me:8443/web/payment', false],
            'arbitrary' => ['https://evil.example/collect', false],
            'not a url' => ['not-a-url', false],
            'empty' => ['', false],
        ];
    }

    // ==================================== 7. 切換不產生對外流量

    /**
     * ⛔ 儲存 credential 與切換開關都不得呼叫任何外部服務。
     *
     * `Http::preventStrayRequests()` 在 setUp 就已生效,任何一次外呼都會讓
     * 這個測試失敗——「我看看能不能開」不該變成一次真實請求。
     */
    public function test_neither_saving_nor_toggling_makes_an_outbound_request(): void
    {
        Http::fake();
        $this->runningAsLiveSite();

        Livewire::actingAs($this->owner())
            ->test(ManageIntegrationSettings::class)
            ->set('data.ecpay_payment_identifier', '3000001')
            ->set('data.ecpay_payment_secret_HashKey', self::SECRET_MARKER)
            ->set('data.ecpay_payment_secret_HashIV', self::SECRET_MARKER)
            ->call('save')
            ->call('toggleChannel', IntegrationProvider::EcpayPayment->value, true)
            ->call('toggleChannel', IntegrationProvider::EcpayPayment->value, false);

        Http::assertNothingSent();
    }

    /**
     * 走完整條後台路徑:Owner 輸入 → 儲存 → 啟用 → 通道可用。
     *
     * ⛔ 這是 Owner 實際會做的事,所以它必須有一個測試走完全程,而不是只測
     * 每個零件。⛔ 儲存後輸入框必須被清空,密鑰不留在 Livewire state。
     */
    public function test_the_whole_admin_path_works_end_to_end(): void
    {
        $this->runningAsLiveSite();

        $page = Livewire::actingAs($this->owner())
            ->test(ManageIntegrationSettings::class)
            ->set('data.ecpay_payment_identifier', '3000001')
            ->set('data.ecpay_payment_secret_HashKey', self::SECRET_MARKER)
            ->set('data.ecpay_payment_secret_HashIV', self::SECRET_MARKER)
            ->call('save');

        // ⛔ 儲存後輸入框清空。
        $this->assertSame('', $page->get('data')['ecpay_payment_secret_HashKey']);

        // credential 存好了,但還沒啟用。
        $this->assertFalse(LiveIntegration::availableToCustomer(IntegrationProvider::EcpayPayment));

        $page->call('toggleChannel', IntegrationProvider::EcpayPayment->value, true);

        $this->assertTrue(LiveIntegration::availableToCustomer(IntegrationProvider::EcpayPayment));
        $this->assertSame(self::SECRET_MARKER, LiveIntegration::row(IntegrationProvider::EcpayPayment)->secret('HashKey'));
    }

    // ==================================== 8. 只有正式那一列參與 runtime

    /**
     * ⛔ 既有 sandbox 列不得被自動複製、提升或成為 fallback。
     *
     * 「跟著環境選 row」只會在某天變成用測試金鑰收真錢,或反過來。
     */
    public function test_an_existing_sandbox_row_is_never_used(): void
    {
        $this->runningAsLiveSite();

        $sandbox = IntegrationSetting::factory()
            ->forProvider(IntegrationProvider::EcpayPayment, IntegrationEnvironment::Sandbox)
            ->create(['identifier' => 'SANDBOX-MERCHANT']);
        $sandbox->credentials = ['HashKey' => 'sandbox-key', 'HashIV' => 'sandbox-iv'];
        $sandbox->save();
        // 繞過 observer 硬把它開起來,模擬舊資料。
        DB::table('integration_settings')->where('id', $sandbox->id)->update(['is_enabled' => true]);

        // ⛔ production 列不存在,所以通道必須不可用——不得退回 sandbox 列。
        $this->assertNull(LiveIntegration::row(IntegrationProvider::EcpayPayment));
        $this->assertFalse(LiveIntegration::availableToCustomer(IntegrationProvider::EcpayPayment));
        $this->assertNull($this->registry()->for('ecpay'));
    }

    /**
     * ⛔ 後台不呈現「測試環境／正式環境」的分頁或選項。
     *
     * Owner 明確要求不再區分兩者;顯示一列 runtime 永遠不會讀的設定,只會讓人
     * 以為它有作用。
     *
     * ⛔ 驗的是 form state 的鍵而不是 HTML 字串:Livewire 的 `wire:snapshot`
     * 會把元件名稱與測試方法名一起序列化進 markup,拿整份 HTML 做 substring
     * 比對會被那些雜訊誤判——這一輪我就先踩到了一次。
     */
    public function test_the_admin_exposes_only_one_set_of_fields_per_provider(): void
    {
        $state = Livewire::actingAs($this->owner())
            ->test(ManageIntegrationSettings::class)
            ->get('data');

        foreach (array_keys($state) as $key) {
            // ⛔ 沒有任何欄位帶環境後綴:每個 provider 只有一組。
            $this->assertStringNotContainsString('sandbox', $key);
            $this->assertStringNotContainsString('production', $key);
            $this->assertStringNotContainsString('__', $key);
        }

        // 四個 provider 的識別碼欄位各恰好一個。
        $this->assertArrayHasKey('ecpay_payment_identifier', $state);
        $this->assertArrayHasKey('line_pay_identifier', $state);
        $this->assertArrayHasKey('ecpay_invoice_identifier', $state);

        // ⛔ 每個 secret 也各只有一個輸入框。
        $this->assertArrayHasKey('ecpay_payment_secret_HashKey', $state);
        $this->assertArrayHasKey('line_pay_secret_ChannelSecret', $state);
    }

    /**
     * ⛔ 畫面上沒有「測試環境（sandbox）」這個 credential 選項。
     *
     * ⛔ 比對的是 `IntegrationEnvironment::Sandbox->label()` 本身,而不是
     * 「測試環境」這三個字:頁面上另有一句「這是本機／測試環境,不會對外送出
     * 任何請求」,那是在說「這台機器」,不是在提供一組 sandbox credential。
     * 用寬鬆的 substring 比對會把那句正確的說明也當成違規——我先寫錯了一次。
     */
    public function test_the_admin_never_offers_a_sandbox_credential_set(): void
    {
        $html = Livewire::actingAs($this->owner())->test(ManageIntegrationSettings::class)->html();

        $this->assertStringNotContainsString(IntegrationEnvironment::Sandbox->label(), $html);
        $this->assertStringNotContainsString(IntegrationEnvironment::Production->label(), $html);
        $this->assertStringContainsString('正式營運設定', $html);
    }
}
