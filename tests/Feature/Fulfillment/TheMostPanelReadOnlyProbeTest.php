<?php

namespace Tests\Feature\Fulfillment;

use App\Contracts\FulfillmentGateway;
use App\Contracts\TheMostPanelReadOnlyProbe;
use App\Enums\IntegrationEnvironment;
use App\Enums\IntegrationProvider;
use App\Enums\TheMostPanelReadOnlyAction;
use App\Models\IntegrationSetting;
use App\Services\Fulfillment\TheMostPanelReadOnlyHttpProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Tests\TestCase;

/**
 * The read-only probe: what it may ask, and what it must never do.
 *
 * ⛔ Every response here is a fixture. These are *hypothetical* shapes invented
 * for this file — TheMostPanel's real contract has never been observed, and
 * nothing in this file may be cited as evidence that it has. What the tests
 * prove is what *we* do, not what the provider returns.
 *
 * ⛔ No request reaches the network. `Http::preventStrayRequests()` is on for
 * every test, and the assertions below prove the count is zero wherever a
 * refusal is expected.
 */
class TheMostPanelReadOnlyProbeTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = 'https://themostpanel.com/api/v2';

    private const KEY_MARKER = 'FAKE-API-KEY-MARKER-9182734';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        // ⛔ 探針只能由 CLI 執行；測試本身就跑在 console。
        config()->set('integrations.themostpanel_read_only.enabled', true);
    }

    private function withCredential(): IntegrationSetting
    {
        $setting = IntegrationSetting::factory()
            ->forProvider(IntegrationProvider::TheMostPanel, IntegrationEnvironment::Production)
            ->create();

        $setting->credentials = ['ApiKey' => self::KEY_MARKER];
        $setting->save();

        return $setting;
    }

    private function probe(): TheMostPanelReadOnlyProbe
    {
        return app(TheMostPanelReadOnlyProbe::class);
    }

    /** 一個假設性的 services 回應形狀；⛔ 不是供應商 contract。 */
    private function hypotheticalServices(): array
    {
        return [
            ['service' => 1, 'name' => 'Example', 'rate' => '0.90', 'min' => 100, 'max' => 10000],
            ['service' => 2, 'name' => 'Another', 'rate' => '1.20', 'min' => 50, 'max' => 5000],
        ];
    }

    // ==================================== 1. 能力封閉

    public function test_only_three_actions_exist(): void
    {
        // ⛔ add／refill／cancel 不在 enum 裡，構造不出來。
        $this->assertSame(['services', 'balance', 'status'], TheMostPanelReadOnlyAction::values());
    }

    public static function forbiddenActionProvider(): array
    {
        return [
            'add' => ['add'],
            'refill' => ['refill'],
            'cancel' => ['cancel'],
            'uppercase add' => ['ADD'],
            'padded add' => [' add '],
            'arbitrary' => ['anything'],
            'empty' => [''],
        ];
    }

    /** ⛔ 這是本檔最重要的一項：`add` 會真的下單並付費。 */
    #[DataProvider('forbiddenActionProvider')]
    public function test_a_mutating_action_cannot_be_constructed(string $action): void
    {
        $this->assertNull(TheMostPanelReadOnlyAction::tryFrom($action));
    }

    public function test_the_read_only_contract_has_no_submit_method(): void
    {
        $methods = array_column(
            (new ReflectionClass(TheMostPanelReadOnlyProbe::class))->getMethods(),
            'name'
        );

        // ⛔ 只有 probe()；沒有 submit／add／cancel。
        $this->assertSame(['probe'], $methods);
    }

    public function test_the_probe_is_not_the_fulfillment_gateway(): void
    {
        /*
         * ⛔ 兩個 contract 必須完全獨立。
         *
         * 綁在一起的話，「查詢回應格式」與「可以下付費訂單」就會共用同一條
         * 啟用路徑——打開安全的那個，等於順手把昂貴的那個也上膛了。
         */
        $this->assertNotInstanceOf(FulfillmentGateway::class, $this->probe());
        $this->assertInstanceOf(TheMostPanelReadOnlyHttpProbe::class, $this->probe());
    }

    // ==================================== 2. 送出前的硬閘

    public function test_the_flag_being_off_sends_nothing(): void
    {
        Http::fake();
        $this->withCredential();
        config()->set('integrations.themostpanel_read_only.enabled', false);

        $observation = $this->probe()->probe(TheMostPanelReadOnlyAction::Services);

        $this->assertSame('blocked_disabled', $observation->outcome);
        Http::assertNothingSent();
    }

    public function test_a_missing_credential_sends_nothing(): void
    {
        Http::fake();

        $observation = $this->probe()->probe(TheMostPanelReadOnlyAction::Balance);

        $this->assertSame('blocked_no_credential', $observation->outcome);
        Http::assertNothingSent();
    }

    public function test_production_sends_nothing(): void
    {
        Http::fake();
        $this->withCredential();
        $this->app->detectEnvironment(fn () => 'production');

        $observation = $this->probe()->probe(TheMostPanelReadOnlyAction::Services);

        // ⛔ 唯讀也一樣：在正式站上執行探針必須是刻意的、有人在場的決定。
        $this->assertSame('blocked_production', $observation->outcome);
        Http::assertNothingSent();
    }

    public static function badEndpointProvider(): array
    {
        return [
            'empty' => [''],
            'http' => ['http://themostpanel.com/api/v2'],
            'other host' => ['https://evil.example.com/api/v2'],
            'lookalike host' => ['https://themostpanel.com.evil.example.com/api/v2'],
            'subdomain' => ['https://api.themostpanel.com/api/v2'],
            'ip' => ['https://203.0.113.10/api/v2'],
            'other path' => ['https://themostpanel.com/api/v1'],
            'trailing slash' => ['https://themostpanel.com/api/v2/'],
            'query string' => ['https://themostpanel.com/api/v2?key=leak'],
            'fragment' => ['https://themostpanel.com/api/v2#x'],
            'userinfo' => ['https://user@themostpanel.com/api/v2'],
            'explicit port' => ['https://themostpanel.com:8443/api/v2'],
        ];
    }

    /** ⛔ 端點必須與版本控制中的值完全一致；請求會帶著我們的 API key。 */
    #[DataProvider('badEndpointProvider')]
    public function test_a_tampered_endpoint_sends_nothing(string $endpoint): void
    {
        Http::fake();
        $this->withCredential();
        config()->set('integrations.themostpanel_read_only.endpoint', $endpoint);

        $observation = $this->probe()->probe(TheMostPanelReadOnlyAction::Services);

        $this->assertSame('blocked_endpoint', $observation->outcome);
        Http::assertNothingSent();
    }

    public static function badOrderIdProvider(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
            'whitespace' => ['   '],
            'comma list' => ['101,102,103'],
            /*
             * ⛔ 連字號一律拒絕。
             *
             * 公開範例的訂單編號是數字，多筆查詢用的是另一個 `orders` 欄位，
             * 而我們永遠只送單一 `order`。允許連字號只會讓「看起來像範圍」的
             * 輸入通過格式檢查——寧可擋掉一個罕見的合法編號。
             */
            'range' => ['100-200'],
            'hyphenated' => ['ORDER-1'],
            'wildcard' => ['*'],
            'sql-ish' => ['1 OR 1=1'],
            'too long' => [str_repeat('9', 65)],
            'space inside' => ['12 34'],
        ];
    }

    /** ⛔ 只接受單一一筆、格式合法的訂單編號；不得猜號或批次。 */
    #[DataProvider('badOrderIdProvider')]
    public function test_an_unusable_order_id_sends_nothing(?string $orderId): void
    {
        Http::fake();
        $this->withCredential();

        $observation = $this->probe()->probe(TheMostPanelReadOnlyAction::Status, $orderId);

        $this->assertSame('blocked_invalid_order_id', $observation->outcome);
        Http::assertNothingSent();
    }

    public function test_a_non_status_action_may_not_carry_an_order_id(): void
    {
        Http::fake();
        $this->withCredential();

        $observation = $this->probe()->probe(TheMostPanelReadOnlyAction::Services, 'ORDER-1');

        // ⛔ 不需要訂單編號的查詢不得夾帶一個。
        $this->assertSame('blocked_unexpected_order_id', $observation->outcome);
        Http::assertNothingSent();
    }

    // ==================================== 3. 請求形狀

    public function test_a_services_probe_sends_exactly_one_request(): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->hypotheticalServices())]);
        $this->withCredential();

        $this->probe()->probe(TheMostPanelReadOnlyAction::Services);

        // ⛔ 一次指令，最多一次請求；rate limit 未知時不得重複打。
        Http::assertSentCount(1);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $request->url() === self::ENDPOINT
                && $request->method() === 'POST'
                && $body['action'] === 'services'
                && $body['key'] === self::KEY_MARKER
                // ⛔ 不需要的 action 不帶 order。
                && ! array_key_exists('order', $body);
        });
    }

    public function test_a_status_probe_sends_one_order_only(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['status' => 'Completed'])]);
        $this->withCredential();

        $this->probe()->probe(TheMostPanelReadOnlyAction::Status, '12345');

        Http::assertSentCount(1);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $body['action'] === 'status'
                && $body['order'] === '12345'
                && count($body) === 3;
        });
    }

    public function test_the_key_is_never_placed_in_the_url(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['balance' => '1.00'])]);
        $this->withCredential();

        $this->probe()->probe(TheMostPanelReadOnlyAction::Balance);

        Http::assertSent(function ($request) {
            // ⛔ query string 會進入伺服器日誌與 referrer。
            return ! str_contains($request->url(), self::KEY_MARKER)
                && ! str_contains($request->url(), '?');
        });
    }

    // ==================================== 4. 回應：只看形狀

    public function test_a_readable_response_yields_structure_only(): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->hypotheticalServices())]);
        $this->withCredential();

        $observation = $this->probe()->probe(TheMostPanelReadOnlyAction::Services);

        $this->assertTrue($observation->isObserved());
        $this->assertSame('list', $observation->topLevelType);
        $this->assertSame(2, $observation->itemCount);
        // 欄位名稱與型別足以寫 parser⋯⋯
        $this->assertSame('int', $observation->fieldTypes['service']);
        $this->assertSame('string', $observation->fieldTypes['rate']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $observation->bodyHash);
    }

    public function test_no_provider_values_survive_the_observation(): void
    {
        Http::fake([self::ENDPOINT => Http::response([
            ['service' => 1, 'name' => '祕密服務名稱', 'rate' => '0.90'],
        ])]);
        $this->withCredential();

        $observation = $this->probe()->probe(TheMostPanelReadOnlyAction::Services);

        $encoded = json_encode($observation->toArray(), JSON_UNESCAPED_UNICODE);

        // ⛔ ⋯⋯但值本身不得留下：服務名稱與費率是商業資訊。
        $this->assertStringNotContainsString('祕密服務名稱', (string) $encoded);
        $this->assertStringNotContainsString('0.90', (string) $encoded);
        $this->assertStringNotContainsString(self::KEY_MARKER, (string) $encoded);
    }

    public function test_a_balance_value_is_never_recorded(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['balance' => '1234.56', 'currency' => 'USD'])]);
        $this->withCredential();

        $observation = $this->probe()->probe(TheMostPanelReadOnlyAction::Balance);

        $encoded = json_encode($observation->toArray(), JSON_UNESCAPED_UNICODE);

        // 我們知道有 balance 這個欄位、型別是 string——⛔ 但不知道也不保存金額。
        $this->assertSame('string', $observation->fieldTypes['balance']);
        $this->assertStringNotContainsString('1234.56', (string) $encoded);
    }

    public function test_an_order_id_is_never_recorded(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['status' => 'Completed', 'order' => '99887766'])]);
        $this->withCredential();

        $observation = $this->probe()->probe(TheMostPanelReadOnlyAction::Status, '99887766');

        $encoded = json_encode($observation->toArray(), JSON_UNESCAPED_UNICODE);

        // ⛔ 訂單編號識別一位客人的購買。
        $this->assertStringNotContainsString('99887766', (string) $encoded);
        // ⛔ status token 也不保存：RO-A 不得先把未知值 mapping 成任何東西。
        $this->assertStringNotContainsString('Completed', (string) $encoded);
    }

    public function test_a_status_token_is_never_mapped_to_a_fulfillment_state(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['status' => 'Completed'])]);
        $this->withCredential();

        $observation = $this->probe()->probe(TheMostPanelReadOnlyAction::Status, '778899');

        // ⛔ 探針不碰履約資料：它只是看回應長什麼樣。
        $this->assertSame(0, DB::table('fulfillment_orders')->count());
        $this->assertTrue($observation->isObserved());
    }

    // ==================================== 5. 每一種失敗都安全

    public static function unusableResponseProvider(): array
    {
        return [
            'html error page' => ['<html><body>error</body></html>', 200, 'unparseable_body'],
            'plain text' => ['something went wrong', 200, 'unparseable_body'],
            'empty body' => ['', 200, 'empty_body'],
        ];
    }

    #[DataProvider('unusableResponseProvider')]
    public function test_an_unreadable_body_fails_safely(string $body, int $status, string $expected): void
    {
        Http::fake([self::ENDPOINT => Http::response($body, $status)]);
        $this->withCredential();

        $observation = $this->probe()->probe(TheMostPanelReadOnlyAction::Services);

        $this->assertSame($expected, $observation->outcome);
        $this->assertFalse($observation->isObserved());
        // ⛔ 不重試：rate limit 未知。
        Http::assertSentCount(1);
    }

    public static function badStatusProvider(): array
    {
        return [
            'moved' => [301, 'redirect_refused'],
            'found' => [302, 'redirect_refused'],
            'bad request' => [400, 'client_error'],
            'unauthorized' => [401, 'client_error'],
            'forbidden' => [403, 'client_error'],
            'not found' => [404, 'client_error'],
            'rate limited' => [429, 'rate_limited'],
            'server error' => [500, 'server_error'],
            'gateway' => [502, 'server_error'],
        ];
    }

    #[DataProvider('badStatusProvider')]
    public function test_a_bad_status_fails_safely(int $status, string $expected): void
    {
        Http::fake([self::ENDPOINT => Http::response(['x' => 1], $status)]);
        $this->withCredential();

        $observation = $this->probe()->probe(TheMostPanelReadOnlyAction::Services);

        $this->assertSame($expected, $observation->outcome);
        Http::assertSentCount(1);
    }

    public function test_a_redirect_is_never_followed(): void
    {
        Http::fake([
            self::ENDPOINT => Http::response('', 302, ['Location' => 'https://evil.example.com/collect']),
            '*' => Http::response(['captured' => true]),
        ]);
        $this->withCredential();

        $observation = $this->probe()->probe(TheMostPanelReadOnlyAction::Services);

        $this->assertSame('redirect_refused', $observation->outcome);
        // ⛔ 只送出了那一次；沒有跟著跳到別的主機。
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'evil.example.com'));
    }

    public function test_a_timeout_fails_safely_without_retrying(): void
    {
        Http::fake([self::ENDPOINT => fn () => throw new ConnectionException('timeout')]);
        $this->withCredential();

        $observation = $this->probe()->probe(TheMostPanelReadOnlyAction::Services);

        $this->assertSame('transport_failed', $observation->outcome);
    }

    public function test_an_oversized_body_is_refused(): void
    {
        // 2 MiB 上限；⛔ 這是我們的保守選擇，不是供應商的保證。
        Http::fake([self::ENDPOINT => Http::response(str_repeat('a', 2_097_153))]);
        $this->withCredential();

        $observation = $this->probe()->probe(TheMostPanelReadOnlyAction::Services);

        $this->assertContains($observation->outcome, ['body_too_large', 'unparseable_body']);
    }

    public function test_no_failure_path_keeps_provider_text(): void
    {
        Http::fake([self::ENDPOINT => Http::response(
            'Invalid API key '.self::KEY_MARKER.' for account 55123',
            401
        )]);
        $this->withCredential();

        $observation = $this->probe()->probe(TheMostPanelReadOnlyAction::Services);

        $encoded = json_encode($observation->toArray(), JSON_UNESCAPED_UNICODE);

        /*
         * ⛔ 錯誤回應正是最可能把我們自己的 key 回音出來的地方。
         *
         * outcome 一律是本地代碼，provider 原文從來沒有進入這個物件。
         */
        $this->assertStringNotContainsString(self::KEY_MARKER, (string) $encoded);
        $this->assertStringNotContainsString('55123', (string) $encoded);
        $this->assertSame('client_error', $observation->outcome);
    }

    // ==================================== 6. 不觸碰任何既有系統

    public function test_the_probe_writes_nothing_to_the_database(): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->hypotheticalServices())]);
        $setting = $this->withCredential();

        $before = [
            'audits' => DB::table('admin_audit_logs')->count(),
            'fulfillment' => DB::table('fulfillment_orders')->count(),
            'enabled' => DB::table('integration_settings')->where('id', $setting->id)->value('is_enabled'),
        ];

        $this->probe()->probe(TheMostPanelReadOnlyAction::Services);

        // ⛔ 探針是唯讀的，對我們自己的資料庫也是。
        $this->assertSame($before['audits'], DB::table('admin_audit_logs')->count());
        $this->assertSame($before['fulfillment'], DB::table('fulfillment_orders')->count());
        // ⛔ 尤其不得把設定改成 enabled——那是自動派單的開關。
        $this->assertSame(
            $before['enabled'],
            DB::table('integration_settings')->where('id', $setting->id)->value('is_enabled')
        );
    }

    public function test_using_the_probe_does_not_enable_dispatch(): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->hypotheticalServices())]);
        $this->withCredential();

        $this->probe()->probe(TheMostPanelReadOnlyAction::Services);

        // ⛔ 有 key ≠ 可以派單：這兩件事由不同的開關控制。
        $this->assertFalse(config('integrations.enablable.themostpanel.production'));
        $this->assertFalse(config('fulfillment.dispatch_enabled'));
    }

    /**
     * ⛔ 查詢端點與交易端點必須是兩個不同的設定值。
     *
     * `integrations.endpoints` 那一組代表「可用來執行交易的端點」，其
     * production 一律為空，既有安全測試以此為不變式。把唯讀位址放進去，會讓
     * 「可以查詢」在設定上看起來等於「可以下單」——而 `add` 會真的花錢。
     */
    public function test_the_read_only_endpoint_is_not_the_transaction_endpoint(): void
    {
        $this->assertSame('', config('integrations.endpoints.themostpanel.production'));
        $this->assertSame(self::ENDPOINT, config('integrations.themostpanel_read_only.endpoint'));
    }
}
