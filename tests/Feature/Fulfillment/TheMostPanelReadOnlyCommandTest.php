<?php

namespace Tests\Feature\Fulfillment;

use App\Enums\IntegrationEnvironment;
use App\Enums\IntegrationProvider;
use App\Models\IntegrationSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The CLI entry point.
 *
 * ⛔ Nothing here reaches the network. The command exists so that a person can
 * run one read, by hand, from a terminal — and these tests check that it cannot
 * be talked into anything else.
 */
class TheMostPanelReadOnlyCommandTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = 'https://themostpanel.com/api/v2';

    private const KEY_MARKER = 'FAKE-CLI-KEY-MARKER-5566';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config()->set('integrations.themostpanel_read_only.enabled', true);
    }

    private function withCredential(): void
    {
        $setting = IntegrationSetting::factory()
            ->forProvider(IntegrationProvider::TheMostPanel, IntegrationEnvironment::Production)
            ->create();

        $setting->credentials = ['ApiKey' => self::KEY_MARKER];
        $setting->save();
    }

    public static function forbiddenActionProvider(): array
    {
        return [
            'add' => ['add'],
            'refill' => ['refill'],
            'cancel' => ['cancel'],
            'orders' => ['orders'],
            'arbitrary' => ['whatever'],
        ];
    }

    /**
     * ⛔ 這是本檔最重要的一項。
     *
     * `add` 會在供應商那邊建立一筆真實、要付費的訂單。這個指令必須沒有任何
     * 路徑可以到達它——打錯字不行，刻意構造也不行。
     */
    #[DataProvider('forbiddenActionProvider')]
    public function test_a_mutating_action_is_refused_before_anything_is_sent(string $action): void
    {
        Http::fake();
        $this->withCredential();

        $this->artisan('themostpanel:probe', ['action' => $action])
            ->expectsOutputToContain('只允許')
            ->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_the_command_has_no_order_option(): void
    {
        $definition = Artisan::all()['themostpanel:probe']->getDefinition();

        /*
         * ⛔ 訂單編號只能互動輸入。
         *
         * shell 參數會留在 process list 與 shell history 裡——客人的訂單編號
         * 會活得比執行它的那道指令還久。
         */
        $this->assertFalse($definition->hasOption('order'));
        $this->assertFalse($definition->hasOption('orders'));
        $this->assertFalse($definition->hasOption('all'));
        $this->assertSame(['action'], array_keys($definition->getArguments()));
    }

    public function test_a_services_probe_prints_structure_only(): void
    {
        Http::fake([self::ENDPOINT => Http::response([
            ['service' => 1, 'name' => '祕密服務', 'rate' => '0.90'],
        ])]);
        $this->withCredential();

        $this->artisan('themostpanel:probe', ['action' => 'services'])
            ->expectsOutputToContain('services')
            // ⛔ 供應商的值不得出現在終端機上。
            ->doesntExpectOutputToContain('祕密服務')
            ->doesntExpectOutputToContain('0.90')
            ->doesntExpectOutputToContain(self::KEY_MARKER)
            ->assertExitCode(0);

        Http::assertSentCount(1);
    }

    public function test_a_status_probe_asks_for_the_order_id_interactively(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['status' => 'Completed'])]);
        $this->withCredential();

        $this->artisan('themostpanel:probe', ['action' => 'status'])
            ->expectsQuestion('請輸入一筆「已經存在」的供應商訂單編號（不會被保存）', '445566')
            ->assertExitCode(0);

        Http::assertSent(fn ($request) => $request->data()['order'] === '445566');
    }

    public function test_an_empty_order_id_sends_nothing(): void
    {
        Http::fake();
        $this->withCredential();

        $this->artisan('themostpanel:probe', ['action' => 'status'])
            ->expectsQuestion('請輸入一筆「已經存在」的供應商訂單編號（不會被保存）', '')
            ->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_the_order_id_is_never_printed_back(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['status' => 'Completed'])]);
        $this->withCredential();

        $this->artisan('themostpanel:probe', ['action' => 'status'])
            ->expectsQuestion('請輸入一筆「已經存在」的供應商訂單編號（不會被保存）', '778899')
            // ⛔ 輸入的編號不得回顯到終端機，也不得寫進任何輸出。
            ->doesntExpectOutputToContain('778899')
            ->assertExitCode(0);
    }

    public function test_a_disabled_flag_reports_a_local_code_and_sends_nothing(): void
    {
        Http::fake();
        $this->withCredential();
        config()->set('integrations.themostpanel_read_only.enabled', false);

        $this->artisan('themostpanel:probe', ['action' => 'services'])
            ->expectsOutputToContain('blocked_disabled')
            ->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_a_missing_credential_reports_a_local_code(): void
    {
        Http::fake();

        $this->artisan('themostpanel:probe', ['action' => 'balance'])
            ->expectsOutputToContain('blocked_no_credential')
            ->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_a_provider_error_never_prints_provider_text(): void
    {
        Http::fake([self::ENDPOINT => Http::response(
            'Invalid key '.self::KEY_MARKER.' account 90210',
            401
        )]);
        $this->withCredential();

        $this->artisan('themostpanel:probe', ['action' => 'services'])
            // ⛔ 錯誤回應最可能把我們自己的 key 回音出來。
            ->doesntExpectOutputToContain(self::KEY_MARKER)
            ->doesntExpectOutputToContain('90210')
            ->doesntExpectOutputToContain('Invalid key')
            ->expectsOutputToContain('client_error')
            ->assertExitCode(1);
    }
}
