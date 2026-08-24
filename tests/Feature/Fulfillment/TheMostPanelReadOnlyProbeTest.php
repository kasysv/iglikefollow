<?php

namespace Tests\Feature\Fulfillment;

use App\Contracts\FulfillmentGateway;
use App\Contracts\TheMostPanelReadOnlyProbe;
use App\Enums\IntegrationEnvironment;
use App\Enums\IntegrationProvider;
use App\Enums\TheMostPanelReadOnlyAction;
use App\Models\IntegrationSetting;
use App\Services\Fulfillment\FulfillmentDispatchGate;
use App\Services\Fulfillment\TheMostPanelBoundedResponseStream;
use App\Services\Fulfillment\TheMostPanelCurlCapability;
use App\Services\Fulfillment\TheMostPanelReadOnlyHttpProbe;
use App\Services\Fulfillment\TheMostPanelResponseSizeGuard;
use App\Services\Fulfillment\TheMostPanelTransferState;
use App\Services\Integrations\ProviderEndpoints;
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

        /*
         * ⛔ 明確描述一個支援的 runtime，而不是修改這台機器的 PHP。
         *
         * 本機 libcurl 是 7.85.0（低於 8.4.0），真實 runtime 下探針會正確地
         * 一律拒絕。要測其餘所有行為，必須把「runtime 支援與否」變成可注入的
         * 條件——而不是為了讓測試通過就放寬那道閘。
         */
        $this->useCapability(TheMostPanelCurlCapability::supported());
    }

    private function useCapability(TheMostPanelCurlCapability $capability): void
    {
        $this->app->bind(
            TheMostPanelReadOnlyProbe::class,
            fn () => new TheMostPanelReadOnlyHttpProbe($capability),
        );
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
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $observation->bodyFingerprint);
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

    /**
     * ⛔ 這只證明「解析前的第二層」，不證明 transport 真的中止了。
     *
     * `Http::fake()` 的回應本來就已經完整在記憶體裡，所以用一個大字串測出來的
     * 是事後檢查，不是傳輸中止。真正的 transport 上限由下面的 guard 測試單獨
     * 驗證；實機接線狀況見結果文件的誠實標註。
     */
    public function test_an_oversized_body_is_refused_by_the_second_layer(): void
    {
        Http::fake([self::ENDPOINT => Http::response(str_repeat('a', 2_097_153))]);
        $this->withCredential();

        $observation = $this->probe()->probe(TheMostPanelReadOnlyAction::Services);

        $this->assertContains($observation->outcome, ['body_too_large', 'unparseable_body']);
    }

    // ==================================== 10. R1：傳輸層大小上限

    /**
     * ⛔ 宣告長度超限時，body 一個 byte 都不該讀。
     *
     * guard 直接測試，不透過 `Http::fake()`：fake 的回應早就在記憶體裡了，
     * 用它「證明」傳輸中止等於什麼都沒證明。
     */
    public function test_the_guard_refuses_an_oversized_declared_length(): void
    {
        $this->expectException(\RuntimeException::class);

        TheMostPanelResponseSizeGuard::assertContentLength([
            'Content-Length' => [(string) (TheMostPanelResponseSizeGuard::MAX_BODY_BYTES + 1)],
        ]);
    }

    public function test_the_guard_allows_a_declared_length_within_the_cap(): void
    {
        TheMostPanelResponseSizeGuard::assertContentLength(['Content-Length' => ['1024']]);

        $this->assertTrue(true, '合理大小不得被擋下');
    }

    public function test_the_guard_matches_the_header_case_insensitively(): void
    {
        $this->expectException(\RuntimeException::class);

        // HTTP 標頭名稱不分大小寫；只比對一種寫法等於漏掉其他寫法。
        TheMostPanelResponseSizeGuard::assertContentLength([
            'content-length' => [(string) (TheMostPanelResponseSizeGuard::MAX_BODY_BYTES + 1)],
        ]);
    }

    /**
     * @return array<string, array{0: array<string, array<int, string>>}>
     */
    public static function unknownLengthHeaderProvider(): array
    {
        return [
            'absent' => [[]],
            'not numeric' => [['Content-Length' => ['abc']]],
            'conflicting' => [['Content-Length' => ['10', '99999999']]],
        ];
    }

    /**
     * ⛔ 長度未知或互相矛盾時不放行，也不猜——交給下載過程的檢查。
     *
     * @param  array<string, array<int, string>>  $headers
     */
    #[DataProvider('unknownLengthHeaderProvider')]
    public function test_an_unknown_declared_length_defers_to_progress(array $headers): void
    {
        TheMostPanelResponseSizeGuard::assertContentLength($headers);

        // 標頭階段不擋，但下載超過上限時仍會中止。
        $this->expectException(\RuntimeException::class);

        TheMostPanelResponseSizeGuard::assertProgress(TheMostPanelResponseSizeGuard::MAX_BODY_BYTES + 1);
    }

    public function test_the_guard_aborts_a_chunked_download_that_grows_too_large(): void
    {
        // 上限之內持續下載沒問題⋯⋯
        TheMostPanelResponseSizeGuard::assertProgress(1024);
        TheMostPanelResponseSizeGuard::assertProgress(TheMostPanelResponseSizeGuard::MAX_BODY_BYTES);

        // ⛔ ⋯⋯超過就中止，不管對方宣稱了什麼。
        $this->expectException(\RuntimeException::class);

        TheMostPanelResponseSizeGuard::assertProgress(TheMostPanelResponseSizeGuard::MAX_BODY_BYTES + 1);
    }

    public function test_a_size_abort_is_reported_as_body_too_large(): void
    {
        // 模擬 guard 在 transport 期間丟出的中止。
        Http::fake([
            self::ENDPOINT => fn () => throw new \RuntimeException(TheMostPanelResponseSizeGuard::REASON),
        ]);
        $this->withCredential();

        $observation = $this->probe()->probe(TheMostPanelReadOnlyAction::Services);

        // ⛔ 回報成「太大」而不是「連線失敗」：兩者的處理方式不同。
        $this->assertSame('body_too_large', $observation->outcome);
    }

    /**
     * ⛔ Guzzle 會把我們的例外包起來，訊息只留在最內層。
     *
     * `on_headers` 丟出的例外會先被 Guzzle 換成「An error was encountered
     * during the on_headers event」，Laravel 再包成 ConnectionException。
     * 只看最外層訊息，就會把「太大」誤報成一般連線失敗——兩者對讀結果的人
     * 意義完全不同。實機驗證確認了這個包裝行為。
     */
    public function test_a_wrapped_size_abort_is_still_recognised(): void
    {
        $inner = new \RuntimeException(TheMostPanelResponseSizeGuard::REASON);
        $middle = new \RuntimeException('An error was encountered during the on_headers event', 0, $inner);
        $outer = new ConnectionException('generic transport failure', 0, $middle);

        $this->assertTrue(TheMostPanelResponseSizeGuard::isSizeAbort($outer));
        $this->assertFalse(TheMostPanelResponseSizeGuard::isSizeAbort(
            new \RuntimeException('some unrelated failure')
        ));
    }

    // ==================================== 11. R2：bounded sink 才是真正的硬上限

    private function sink(?int $limit = null): TheMostPanelBoundedResponseStream
    {
        return new TheMostPanelBoundedResponseStream(
            $limit ?? TheMostPanelResponseSizeGuard::MAX_BODY_BYTES
        );
    }

    public function test_the_sink_accepts_exactly_the_cap(): void
    {
        $sink = $this->sink(1024);

        $sink->write(str_repeat('a', 1024));

        // 剛好等於上限不算超過。
        $this->assertSame(1024, $sink->bytesWritten());
    }

    /**
     * ⛔ 檢查發生在寫入之前,而且中止方式是 SHORT WRITE,不是 throw。
     *
     * 先寫進去再發現太大,代表那些 bytes 已經被留下來了——而那正是這道上限
     * 要防止的事。R1(curl 7.68):回 0 讓 libcurl 以 write error 中止,
     * 任何版本都支援;overflow state 讓 caller 辨認這是本站主動的 size abort。
     */
    public function test_the_sink_refuses_one_byte_over_the_cap_with_a_short_write(): void
    {
        $sink = $this->sink(1024);

        // ⛔ 超限的那個 chunk:回 0(short write)、一個 byte 都不寫。
        $this->assertSame(0, $sink->write(str_repeat('a', 1025)));
        $this->assertSame(0, $sink->bytesWritten());
        $this->assertTrue($sink->overflowed());
    }

    public function test_the_sink_accumulates_across_chunks(): void
    {
        $sink = $this->sink(1024);

        $sink->write(str_repeat('a', 600));
        $sink->write(str_repeat('b', 400));

        $this->assertSame(1000, $sink->bytesWritten());

        // ⛔ 分多段送達一樣要累加:逐段都不超限不代表總量不超限。
        // 跨過上限的那段回 short write,保存量停在 1000、不超限。
        $this->assertSame(0, $sink->write(str_repeat('c', 25)));
        $this->assertTrue($sink->overflowed());
        $this->assertSame(1000, $sink->bytesWritten());

        // ⛔ 溢位後鎖死:即使之後的 chunk 很小,也一律拒收,絕不恢復寫入。
        $this->assertSame(0, $sink->write('x'));
        $this->assertSame(1000, $sink->bytesWritten());
    }

    public function test_each_sink_has_its_own_counter(): void
    {
        $first = $this->sink(1024);
        $first->write(str_repeat('a', 1000));

        $second = $this->sink(1024);

        /*
         * ⛔ counter 絕不跨 request 共用。
         *
         * 共用的話，前一次的小回應會吃掉下一次的額度，而兩個探針同時執行時
         * 會互相污染。
         */
        $this->assertSame(0, $second->bytesWritten());
        $second->write(str_repeat('b', 1000));
        $this->assertSame(1000, $second->bytesWritten());
    }

    public function test_the_sink_keeps_nothing_from_a_refused_chunk(): void
    {
        $sink = $this->sink(16);

        $this->assertSame(0, $sink->write('SECRET-RESPONSE-CONTENT-THAT-IS-TOO-LONG'));

        // ⛔ 觸發拒收的那些 bytes,正是我們拒絕保留的 bytes:
        // 保存串流裡一個都不能有。
        $sink->rewind();
        $this->assertSame('', $sink->getContents());
        $this->assertTrue($sink->overflowed());
    }

    /**
     * ⛔ header 階段的拒絕(宣告長度超限)被層層包裹後仍可辨認。
     *
     * R1 之後 sink 不再拋型別化例外(short write 由 overflow state 辨認);
     * isSizeAbort 只剩 header 階段的固定 REASON token,逐層 getPrevious()
     * 尋找——⛔ 比對的是我們自己的 token,不是 provider/cURL 的錯誤文字。
     */
    public function test_a_wrapped_header_stage_abort_is_still_a_size_abort(): void
    {
        $inner = new \RuntimeException(TheMostPanelResponseSizeGuard::REASON);
        $middle = new \RuntimeException('An error was encountered during the on_headers event', 0, $inner);
        $outer = new ConnectionException('cURL error 23: Failed writing header', 0, $middle);

        $this->assertTrue(TheMostPanelResponseSizeGuard::isSizeAbort($outer));
        // 一般連線失敗不是 size abort。
        $this->assertFalse(TheMostPanelResponseSizeGuard::isSizeAbort(new ConnectionException('cURL error 28')));
    }

    public function test_a_header_stage_abort_is_reported_as_body_too_large(): void
    {
        // header 階段的拒絕:on_headers 以固定 REASON 拋出,Guzzle/Laravel 層層包裹。
        Http::fake([
            self::ENDPOINT => fn () => throw new ConnectionException(
                'cURL error 23',
                0,
                new \RuntimeException(TheMostPanelResponseSizeGuard::REASON)
            ),
        ]);
        $this->withCredential();

        $observation = $this->probe()->probe(TheMostPanelReadOnlyAction::Services);

        $this->assertSame('body_too_large', $observation->outcome);

        $encoded = json_encode($observation->toArray(), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('cURL', (string) $encoded);
    }

    public function test_a_normal_response_still_passes_through_the_sink(): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->hypotheticalServices())]);
        $this->withCredential();

        $observation = $this->probe()->probe(TheMostPanelReadOnlyAction::Services);

        // ⛔ 上限不得讓正常回應也讀不到：那樣這個工具就沒有用了。
        $this->assertTrue($observation->isObserved());
        $this->assertSame(2, $observation->itemCount);
    }

    // ==================================== 12. R3：runtime 能力閘

    /**
     * ⛔ R1(curl 7.68):「不支援」只剩一種——ext-curl 不存在。
     *
     * 傳輸中止由 bounded sink 的 short write 執行,不挑 libcurl 版本;舊的
     * 版本門檻列(7.85/8.3.x)已改列到下方的 supported 正向測試。
     *
     * @return array<string, array{0: TheMostPanelCurlCapability}>
     */
    public static function unsupportedRuntimeProvider(): array
    {
        return [
            'no curl extension' => [TheMostPanelCurlCapability::unsupported()],
        ];
    }

    /**
     * ⛔ 不支援的 runtime 必須在讀 credential 之前就停下來。
     *
     * R2 已經證明「bounded sink ＋ 15 秒 timeout」不是傳輸上限——連線期間對方
     * 想送多少就送多少，我們只是不存。所以這裡不能降級成「差不多也行」。
     */
    #[DataProvider('unsupportedRuntimeProvider')]
    public function test_an_unsupported_runtime_blocks_before_any_credential_read(
        TheMostPanelCurlCapability $capability,
    ): void {
        Http::fake();
        $this->withCredential();
        $this->useCapability($capability);

        $reads = 0;
        DB::listen(function ($query) use (&$reads) {
            if (str_contains($query->sql, 'integration_settings') && str_starts_with(trim($query->sql), 'select')) {
                $reads++;
            }
        });

        $observation = $this->probe()->probe(TheMostPanelReadOnlyAction::Services);

        $this->assertSame('blocked_unsupported_transport_cap', $observation->outcome);
        Http::assertNothingSent();
        // ⛔ credential 一次都沒查：連問都不該問。
        $this->assertSame(0, $reads, 'credential 不得在不支援的 runtime 上被讀取');
    }

    public function test_a_supported_runtime_is_allowed(): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->hypotheticalServices())]);
        $this->withCredential();
        $this->useCapability(TheMostPanelCurlCapability::supported('8.4.0'));

        $this->assertTrue($this->probe()->probe(TheMostPanelReadOnlyAction::Services)->isObserved());
    }

    public function test_the_capability_threshold_is_libcurl_8_4_0(): void
    {
        // 官方文件：8.4.0 之前 max-filesize 不套用到進行中的傳輸。
        $this->assertFalse(TheMostPanelCurlCapability::unsupported('8.3.9', 0x080309)->supportsOngoingTransferCap());
        $this->assertTrue(TheMostPanelCurlCapability::supported('8.4.0')->supportsOngoingTransferCap());
    }

    /**
     * ⛔ 常數存在不等於它會生效。
     *
     * 7.85.0 也定義了 `CURLOPT_MAXFILESIZE_LARGE`，只是不會套用到進行中的
     * 傳輸——所以版本必須另外檢查，不能只看常數。
     */
    /**
     * ⛔ R1 反轉:short write 不挑版本,舊版 libcurl 也支援。
     *
     * staging 實測的 7.68.0 必須是 supported——用 8.4 門檻永久關閉正常派單,
     * 是拿 `CURLOPT_MAXFILESIZE_LARGE` 這個額外保險層冒充必要條件。
     * ext-curl 缺失仍 fail closed。
     */
    public function test_any_libcurl_version_with_the_extension_is_supported(): void
    {
        foreach (['7.68.0', '7.85.0', '8.3.0', '8.5.0'] as $version) {
            $this->assertTrue(
                TheMostPanelCurlCapability::supported($version)->supportsOngoingTransferCap(),
                $version,
            );
        }

        $this->assertFalse(TheMostPanelCurlCapability::unsupported()->supportsOngoingTransferCap());
    }

    public function test_this_machine_is_honestly_reported(): void
    {
        $runtime = TheMostPanelCurlCapability::fromRuntime();

        /*
         * ⛔ 這個測試不斷言版本值,只確認我們讀的是真實 runtime。
         *
         * R1 之後 short write 不挑版本,本機(7.85)與 staging(7.68)都
         * supported;版本字串仍如實回報,供 readiness 顯示。
         */
        $this->assertNotSame('', $runtime->versionString());
    }

    // ==================================== 13. R3：原生上限與壓縮繞道

    /**
     * ⛔ 原生上限必須真的出現在送出的 request options 裡。
     *
     * 用 middleware 攔下 Guzzle 實際收到的 options——`Http::fake()` 的
     * assertion 看不到這一層，而「有沒有設定這個選項」正是 R3 的重點。
     */
    public function test_the_request_carries_the_native_max_filesize(): void
    {
        $captured = [];

        Http::fake([self::ENDPOINT => Http::response($this->hypotheticalServices())]);
        Http::globalMiddleware(function ($handler) use (&$captured) {
            return function ($request, array $options) use ($handler, &$captured) {
                $captured = $options;

                return $handler($request, $options);
            };
        });

        $this->withCredential();
        $this->probe()->probe(TheMostPanelReadOnlyAction::Services);

        $this->assertSame(
            TheMostPanelResponseSizeGuard::MAX_BODY_BYTES,
            $captured['curl'][CURLOPT_MAXFILESIZE_LARGE] ?? null,
            '原生 max-filesize 必須等於 2 MiB',
        );

        // ⛔ 同時關閉自動解壓：否則解壓後的大小不受任何限制。
        $this->assertFalse($captured['decode_content'] ?? null);
    }

    /**
     * ⛔ 壓縮會讓所有以 wire bytes 計算的上限失效。
     *
     * 「線路上 2 KB、解壓後 2 GB」可以通過每一道大小檢查——cURL 看到的是小
     * 傳輸，而巨大的版本只在解碼後存在，那正是接下來要被解析的東西。
     */
    public static function compressedEncodingProvider(): array
    {
        return [
            'gzip' => ['gzip'],
            'br' => ['br'],
            'deflate' => ['deflate'],
            'mixed case' => ['GZIP'],
            'multiple' => ['gzip, br'],
        ];
    }

    #[DataProvider('compressedEncodingProvider')]
    public function test_a_compressed_response_is_refused_at_the_header_stage(string $encoding): void
    {
        $this->expectException(\RuntimeException::class);

        TheMostPanelResponseSizeGuard::assertIdentityEncoding(['Content-Encoding' => [$encoding]]);
    }

    public static function acceptableEncodingProvider(): array
    {
        return [
            'identity' => [['Content-Encoding' => ['identity']]],
            'empty' => [['Content-Encoding' => ['']]],
            'absent' => [[]],
            'case insensitive header' => [['content-encoding' => ['identity']]],
        ];
    }

    /** @param array<string, array<int, string>> $headers */
    #[DataProvider('acceptableEncodingProvider')]
    public function test_an_uncompressed_response_is_allowed(array $headers): void
    {
        TheMostPanelResponseSizeGuard::assertIdentityEncoding($headers);

        $this->assertTrue(true, 'identity／未宣告不得被擋下');
    }

    public function test_an_encoding_refusal_is_reported_without_provider_text(): void
    {
        Http::fake([
            self::ENDPOINT => fn () => throw new ConnectionException(
                'transfer failed',
                0,
                new \RuntimeException(TheMostPanelResponseSizeGuard::ENCODING_REASON)
            ),
        ]);
        $this->withCredential();

        $observation = $this->probe()->probe(TheMostPanelReadOnlyAction::Services);

        $this->assertSame('unsupported_encoding', $observation->outcome);

        $encoded = json_encode($observation->toArray(), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('transfer failed', (string) $encoded);
    }

    // ==================================== 14. R3：以 errno 分類，不解析訊息

    public function test_only_error_63_means_the_size_limit_was_hit(): void
    {
        $state = new TheMostPanelTransferState;

        $this->assertFalse($state->exceededMaxFileSize(), '尚未記錄任何錯誤');

        $state->record(TheMostPanelTransferState::FILESIZE_EXCEEDED);
        $this->assertTrue($state->exceededMaxFileSize());
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function otherTransferErrorProvider(): array
    {
        return [
            'timeout 28' => [28],
            'write error 23' => [23],
            'abort by callback 42' => [42],
            'ssl 60' => [60],
            'zero' => [0],
        ];
    }

    /** ⛔ 只有 63 算「太大」；其餘一律維持一般傳輸失敗。 */
    #[DataProvider('otherTransferErrorProvider')]
    public function test_another_transfer_error_is_not_a_size_failure(mixed $code): void
    {
        $state = new TheMostPanelTransferState;
        $state->record($code);

        $this->assertFalse($state->exceededMaxFileSize());
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function nonIntegerErrorProvider(): array
    {
        return [
            'string 63' => ['63'],
            'null' => [null],
            'array' => [[63]],
            'bool' => [true],
            'float' => [63.0],
        ];
    }

    /** ⛔ 不做寬鬆轉型：被轉型的值可能讓任意字串變成有意義的代碼。 */
    #[DataProvider('nonIntegerErrorProvider')]
    public function test_a_non_integer_error_code_is_discarded(mixed $code): void
    {
        $state = new TheMostPanelTransferState;
        $state->record($code);

        $this->assertNull($state->errorCode());
        $this->assertFalse($state->exceededMaxFileSize());
    }

    public function test_a_size_abort_keeps_no_exception_text(): void
    {
        Http::fake([
            self::ENDPOINT => fn () => throw new \RuntimeException(
                TheMostPanelResponseSizeGuard::REASON.' '.self::KEY_MARKER
            ),
        ]);
        $this->withCredential();

        $observation = $this->probe()->probe(TheMostPanelReadOnlyAction::Services);

        $encoded = json_encode($observation->toArray(), JSON_UNESCAPED_UNICODE);

        // ⛔ 例外訊息同樣不落盤。
        $this->assertStringNotContainsString(self::KEY_MARKER, (string) $encoded);
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

        // ⛔ 有 key ≠ 可以派單:R1 之後由 Owner 的總開關(production row 的
        // is_enabled)決定,而這個測試的 row 沒有被啟用——探針用過也一樣。
        $this->assertFalse(FulfillmentDispatchGate::enabled());
    }

    /**
     * ⛔ 查詢端點與交易端點必須是兩個不同的設定值。
     *
     * `integrations.endpoints` 那一組代表「可用來執行交易的端點」，其
     * production 一律為空，既有安全測試以此為不變式。把唯讀位址放進去，會讓
     * 「可以查詢」在設定上看起來等於「可以下單」——而 `add` 會真的花錢。
     */
    public function test_the_read_only_endpoint_is_a_separate_setting_from_the_transaction_endpoint(): void
    {
        /*
         * R1:交易端點的 production 不再是空字串——正式派單改由 Owner 總開關
         * 決定,端點固定為官方網址。⛔ 仍然是兩個「分開的設定值」:唯讀探針
         * 讀它自己的 `themostpanel_read_only.endpoint`,永遠不讀交易那一組;
         * 兩者剛好同值,但改掉其中一個不會影響另一個的判斷。
         */
        $this->assertSame(
            ProviderEndpoints::THEMOSTPANEL_DISPATCH,
            config('integrations.endpoints.themostpanel.production'),
        );
        $this->assertSame(self::ENDPOINT, config('integrations.themostpanel_read_only.endpoint'));
    }

    // ==================================== 7. R1：欄位名稱也是供應商控制的文字

    /**
     * ⛔ 供應商挑選自己的 JSON key，所以 key 就是它可以任意填入的文字。
     *
     * 初版只把名稱截到 40 字就原樣輸出——但比 40 字短的 API key 會完整存活。
     * 這些情境全部由 GPT 的反證重現。
     *
     * @return array<string, array{0: string}>
     */
    public static function leakyFieldNameProvider(): array
    {
        return [
            'key as field name' => [self::KEY_MARKER],
            'key with prefix' => ['prefix_'.self::KEY_MARKER],
            'key with suffix' => [self::KEY_MARKER.'_suffix'],
            'key embedded' => ['a'.self::KEY_MARKER.'z'],
        ];
    }

    #[DataProvider('leakyFieldNameProvider')]
    public function test_an_api_key_in_a_field_name_is_redacted(string $fieldName): void
    {
        Http::fake([self::ENDPOINT => Http::response([$fieldName => 'x'])]);
        $this->withCredential();

        $observation = $this->probe()->probe(TheMostPanelReadOnlyAction::Services);

        $encoded = json_encode($observation->toArray(), JSON_UNESCAPED_UNICODE);

        // ⛔ 連片段都不得殘留。
        $this->assertStringNotContainsString(self::KEY_MARKER, (string) $encoded);
        $this->assertStringContainsString('redacted_field', (string) $encoded);
    }

    public function test_an_order_id_in_a_field_name_is_redacted(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['order_112233_detail' => 'x'])]);
        $this->withCredential();

        $observation = $this->probe()->probe(TheMostPanelReadOnlyAction::Status, '112233');

        $encoded = json_encode($observation->toArray(), JSON_UNESCAPED_UNICODE);

        // ⛔ 訂單編號識別一位客人的購買，出現在 key 裡也一樣。
        $this->assertStringNotContainsString('112233', (string) $encoded);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unsafeFieldNameProvider(): array
    {
        return [
            'chinese' => ['祕密欄位名稱'],
            'control chars' => ["field\x00\x1fname"],
            'newline' => ["line1\nline2"],
            'ansi escape' => ["\033[31mred"],
            'very long' => [str_repeat('z', 300)],
            'json-ish' => ['{"nested":"value"}'],
            'html' => ['<script>alert(1)</script>'],
            'leading digit' => ['1field'],
        ];
    }

    /** ⛔ 不是可辨識的技術欄位名稱就不顯示原文，只留位置佔位符。 */
    #[DataProvider('unsafeFieldNameProvider')]
    public function test_an_unrecognisable_field_name_is_never_printed(string $fieldName): void
    {
        Http::fake([self::ENDPOINT => Http::response([$fieldName => 1])]);
        $this->withCredential();

        $observation = $this->probe()->probe(TheMostPanelReadOnlyAction::Services);

        $encoded = json_encode($observation->toArray(), JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString($fieldName, (string) $encoded);
        // 仍然知道有這個欄位、型別是什麼。
        $this->assertSame(['field_1' => 'int'], $observation->fieldTypes);
    }

    public function test_a_normal_field_name_is_still_shown(): void
    {
        Http::fake([self::ENDPOINT => Http::response([
            'service' => 1, 'start_count' => 0, 'remains' => 10, 'currency-code' => 'USD',
        ])]);
        $this->withCredential();

        $observation = $this->probe()->probe(TheMostPanelReadOnlyAction::Services);

        // ⛔ 抹掉一切會讓這個工具沒有用：正常的技術欄位名稱必須看得到。
        $this->assertArrayHasKey('service', $observation->fieldTypes);
        $this->assertArrayHasKey('start_count', $observation->fieldTypes);
        $this->assertArrayHasKey('currency-code', $observation->fieldTypes);
    }

    /**
     * ⛔ 兩個都被抹掉的欄位不得互相覆蓋。
     *
     * 直接覆寫會讓輸出少一個欄位，看起來像回應比實際更簡單。
     */
    public function test_redacted_names_do_not_collide(): void
    {
        Http::fake([self::ENDPOINT => Http::response([
            '祕密一' => 1,
            '祕密二' => 'x',
            self::KEY_MARKER => true,
            self::KEY_MARKER.'-2' => 1.5,
        ])]);
        $this->withCredential();

        $observation = $this->probe()->probe(TheMostPanelReadOnlyAction::Services);

        // 四個欄位進去，四個欄位出來。
        $this->assertCount(4, $observation->fieldTypes);
        $this->assertStringNotContainsString(
            self::KEY_MARKER,
            (string) json_encode($observation->toArray(), JSON_UNESCAPED_UNICODE)
        );
    }

    // ==================================== 8. R1：keyed fingerprint

    public function test_the_fingerprint_is_keyed_not_a_plain_digest(): void
    {
        $body = json_encode(['balance' => '12.34', 'currency' => 'USD']);
        Http::fake([self::ENDPOINT => Http::response($body, 200, ['Content-Type' => 'application/json'])]);
        $this->withCredential();

        $observation = $this->probe()->probe(TheMostPanelReadOnlyAction::Balance);

        /*
         * ⛔ 普通 SHA-256 對短回應可以被枚舉還原。
         *
         * GPT 用已知欄位順序枚舉 `0.00`～`2000.00`，約 1,200 次就還原出餘額。
         * 加了金鑰之後，沒有我們 app key 的人手上沒有可比對的東西。
         */
        $this->assertNotSame(
            hash('sha256', (string) $body),
            $observation->bodyFingerprint,
        );
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $observation->bodyFingerprint);
    }

    public function test_the_fingerprint_is_stable_for_the_same_body_and_key(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['balance' => '1.00'])]);
        $this->withCredential();

        $first = $this->probe()->probe(TheMostPanelReadOnlyAction::Balance);
        $second = $this->probe()->probe(TheMostPanelReadOnlyAction::Balance);

        // 同一份 body、同一把金鑰：指紋必須一致，否則無法比對兩次回應。
        $this->assertSame($first->bodyFingerprint, $second->bodyFingerprint);
    }

    public function test_a_different_app_key_gives_a_different_fingerprint(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['balance' => '1.00'])]);
        $this->withCredential();

        $first = $this->probe()->probe(TheMostPanelReadOnlyAction::Balance);

        config()->set('app.key', 'base64:'.base64_encode(str_repeat('b', 32)));

        $second = $this->probe()->probe(TheMostPanelReadOnlyAction::Balance);

        $this->assertNotSame($first->bodyFingerprint, $second->bodyFingerprint);
    }

    public function test_a_missing_app_key_fails_closed_rather_than_degrading(): void
    {
        Http::fake();
        $this->withCredential();
        config()->set('app.key', '');

        $observation = $this->probe()->probe(TheMostPanelReadOnlyAction::Balance);

        // ⛔ 沒有金鑰就沒有指紋，而不是「先用不安全的版本頂著」。
        $this->assertSame('blocked_no_app_key', $observation->outcome);
        Http::assertNothingSent();
    }

    public function test_the_observation_no_longer_advertises_a_plain_hash(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['balance' => '1.00'])]);
        $this->withCredential();

        $array = $this->probe()->probe(TheMostPanelReadOnlyAction::Balance)->toArray();

        // ⛔ 名稱必須說清楚是 HMAC，讀報告的人不必自己推斷差別。
        $this->assertArrayNotHasKey('body_sha256', $array);
        $this->assertArrayHasKey('body_hmac_sha256', $array);
    }

    // ==================================== 9. R1：credential 只讀一次

    /**
     * ⛔ 真正的競態：設定在**第一次讀取之後**才消失。
     *
     * R1 的版本在呼叫 probe 之前就刪掉 setting，然後允許 `blocked_no_credential`
     * 直接 return——那只驗證了「缺憑證不送出」，完全沒有重現競態窗。這一版用
     * DB query listener 在第一次 retrieval **完成當下**才 raw-delete，確保 probe
     * 已經拿到 snapshot 之後設定才消失。
     */
    public function test_the_credential_is_read_once_and_that_snapshot_is_sent(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['ok' => true])]);
        $setting = $this->withCredential();

        $reads = [];
        $deleted = false;
        // ⛔ 只計算 probe 執行期間的查詢；斷言自己的 count(*) 不算。
        $watching = true;

        DB::listen(function ($query) use ($setting, &$reads, &$deleted, &$watching) {
            if (! $watching) {
                return;
            }

            if (! str_contains($query->sql, 'integration_settings') || ! str_starts_with(trim($query->sql), 'select')) {
                return;
            }

            $reads[] = $query->sql;

            // ⛔ 第一次讀取完成的當下就把設定拿掉，模擬刪除／輪替。
            if (! $deleted) {
                $deleted = true;
                DB::table('integration_settings')->where('id', $setting->id)->delete();
            }
        });

        $observation = $this->probe()->probe(TheMostPanelReadOnlyAction::Services);

        $watching = false;

        // ⛔ 設定在中途消失了，但這一次仍必須用已驗證的 snapshot 完成。
        $this->assertSame('observed', $observation->outcome);
        $this->assertSame(0, IntegrationSetting::query()->count(), '設定確實已在中途被刪除');

        // ⛔ 只讀一次：讀第二次就會拿到 null，而閘門已經說過「有 key」。
        $this->assertCount(1, $reads, 'credential 只能讀取一次，實際：'.json_encode($reads));

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => ($request->data()['key'] ?? null) === self::KEY_MARKER);
    }

    public function test_a_request_never_carries_a_null_or_blank_key(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['ok' => true])]);
        $this->withCredential();

        $this->probe()->probe(TheMostPanelReadOnlyAction::Services);

        Http::assertSent(function ($request) {
            $key = $request->data()['key'] ?? null;

            // ⛔ `key=null` 曾經真的被送出去過；這裡把它釘死。
            return is_string($key) && trim($key) !== '';
        });
    }

    public function test_a_request_is_never_sent_with_a_null_key(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['ok' => true])]);
        // ⛔ 完全沒有設定：不得送出任何帶 null key 的請求。

        $observation = $this->probe()->probe(TheMostPanelReadOnlyAction::Services);

        $this->assertSame('blocked_no_credential', $observation->outcome);
        Http::assertNothingSent();
    }

    public function test_a_blank_credential_is_treated_as_missing(): void
    {
        Http::fake();

        $setting = IntegrationSetting::factory()
            ->forProvider(IntegrationProvider::TheMostPanel, IntegrationEnvironment::Production)
            ->create();

        $setting->credentials = ['ApiKey' => '   '];
        $setting->save();

        $observation = $this->probe()->probe(TheMostPanelReadOnlyAction::Services);

        $this->assertSame('blocked_no_credential', $observation->outcome);
        Http::assertNothingSent();
    }
}
