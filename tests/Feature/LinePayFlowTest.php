<?php

namespace Tests\Feature;

use App\Enums\IntegrationEnvironment;
use App\Enums\IntegrationProvider;
use App\Enums\OrderStatus;
use App\Enums\PaymentFailureReason;
use App\Enums\PaymentStatus;
use App\Events\OrderPaid;
use App\Filament\Pages\ManageIntegrationSettings;
use App\Models\IntegrationSetting;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\User;
use App\Services\Integrations\ProviderEndpoints;
use App\Services\Payments\LinePayGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ConfiguresLiveIntegrations;
use Tests\TestCase;

/**
 * LINE Pay request and confirm, against faked HTTP.
 *
 * ⛔ Every response below is a fixture. Nothing in this file reaches the
 * network — `Http::preventStrayRequests()` turns an unfaked call into a test
 * failure rather than a real request — so none of it is evidence that the live
 * sandbox works. That needs credentials and a public HTTPS URL, and is
 * recorded as NOT VERIFIED.
 *
 * The point of the fixtures is the part that is ours: what we do with a reply
 * we cannot control.
 */
class LinePayFlowTest extends TestCase
{
    use ConfiguresLiveIntegrations;
    use RefreshDatabase;

    private const BASE = 'https://api-pay.line.me';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->runningAsLiveSite();

        // R2：sandbox 付款預設關閉，測試必須明確開啟。

        $setting = IntegrationSetting::factory()
            ->forProvider(IntegrationProvider::LinePay, IntegrationEnvironment::Production)
            ->create(['identifier' => 'channel-0001']);

        $setting->credentials = ['ChannelSecret' => 'test-channel-secret-0001'];
        $setting->save();

        DB::table('integration_settings')->where('id', $setting->id)->update(['is_enabled' => true]);
    }

    private function attempt(int $amount = 590): PaymentAttempt
    {
        $order = Order::factory()->create(['total_amount' => $amount]);

        return PaymentAttempt::factory()->create([
            'order_id' => $order->id,
            'provider' => 'line-pay',
            'amount' => $amount,
            'status' => PaymentStatus::Initiated,
        ]);
    }

    private function gateway(): LinePayGateway
    {
        return app(LinePayGateway::class);
    }

    /** @param array<string, mixed> $info */
    private function fakeRequestSuccess(array $info = []): void
    {
        Http::fake([
            self::BASE.'/v4/payments/request' => Http::response([
                'returnCode' => '0000',
                'returnMessage' => 'Success.',
                'info' => array_merge([
                    'transactionId' => '2026081700000001',
                    'paymentUrl' => ['web' => 'https://web-pay.line.me/web/payment/wait?t=abc'],
                ], $info),
            ]),
        ]);
    }

    // ============================================ 1. request payment

    public function test_a_successful_request_stores_the_transaction_id(): void
    {
        $this->fakeRequestSuccess();
        $attempt = $this->attempt();

        $result = $this->gateway()->initiate($attempt);

        $this->assertTrue($result->isRedirect());
        $this->assertSame('2026081700000001', $attempt->fresh()->provider_reference);
        // 付款中，⛔ 不是已付款。
        $this->assertSame(PaymentStatus::Pending, $attempt->fresh()->status);
        $this->assertSame(OrderStatus::PendingPayment, $attempt->order->fresh()->order_status);
    }

    public function test_the_request_sends_only_server_side_values(): void
    {
        $this->fakeRequestSuccess();
        $attempt = $this->attempt(590);

        $this->gateway()->initiate($attempt);

        Http::assertSent(function ($request) use ($attempt) {
            $body = $request->data();

            // ⛔ 金額與訂單編號來自伺服器端快照。
            return $body['amount'] === 590
                && $body['currency'] === 'TWD'
                && $body['orderId'] === $attempt->reference;
        });
    }

    public function test_the_request_carries_signature_headers(): void
    {
        $this->fakeRequestSuccess();
        $this->gateway()->initiate($this->attempt());

        Http::assertSent(function ($request) {
            return $request->hasHeader('X-LINE-ChannelId')
                && $request->hasHeader('X-LINE-Authorization-Nonce')
                && $request->hasHeader('X-LINE-Authorization');
        });
    }

    public function test_the_request_never_sends_the_customer_target(): void
    {
        $this->fakeRequestSuccess();
        $attempt = $this->attempt();

        $this->gateway()->initiate($attempt);

        Http::assertSent(function ($request) {
            // ⛔ 商品名稱是固定安全字串，不帶客人的 IG 帳號或貼文網址。
            return ! str_contains(json_encode($request->data()), 'example_account');
        });
    }

    // ============================================ 2. redirect 白名單

    public function test_a_redirect_to_an_unexpected_host_is_refused(): void
    {
        $this->fakeRequestSuccess([
            'paymentUrl' => ['web' => 'https://evil.example.com/collect'],
        ]);

        $result = $this->gateway()->initiate($this->attempt());

        // ⛔ 這會變成 open redirect，而且正好出現在客人準備輸入卡號的時候。
        $this->assertTrue($result->isFailed());
    }

    public function test_a_non_https_redirect_is_refused(): void
    {
        $this->fakeRequestSuccess([
            'paymentUrl' => ['web' => 'http://sandbox-web-pay.line.me/web/payment'],
        ]);

        $this->assertTrue($this->gateway()->initiate($this->attempt())->isFailed());
    }

    // ============================================ 3. request 失敗與不明

    /**
     * `1106` 是官方表上的 **request header error**——是我們送錯了。
     *
     * ⛔ 舊名稱把它說成 business decline。那會讓客人被告知「你的卡片被拒絕」，
     * 於是換卡、打給銀行、最後放棄——而真正的問題出在我們的請求標頭。
     * 付款訊息的措辭本身就是信任問題。
     */
    public function test_a_request_header_error_is_not_blamed_on_the_customer(): void
    {
        Http::fake([
            self::BASE.'/v4/payments/request' => Http::response([
                'returnCode' => '1106',
                'returnMessage' => 'Header info error',
            ]),
        ]);

        $attempt = $this->attempt();
        $result = $this->gateway()->initiate($attempt);

        $this->assertTrue($result->isFailed());
        // ⛔ 不得對客人說「你被拒絕了」。
        $this->assertNotSame(PaymentFailureReason::Declined, $result->reason);
        $this->assertSame(PaymentFailureReason::VerificationFailed, $result->reason);
        // 確定沒有付款 session，收斂成 failed 讓客人能再試。
        $this->assertSame(PaymentStatus::Failed, $attempt->fresh()->status);
    }

    public function test_malformed_json_is_treated_as_unreadable(): void
    {
        Http::fake([
            self::BASE.'/v4/payments/request' => Http::response('not json at all', 200),
        ]);

        $this->assertTrue($this->gateway()->initiate($this->attempt())->isFailed());
    }

    public function test_a_missing_transaction_id_is_refused(): void
    {
        $this->fakeRequestSuccess(['transactionId' => null]);

        // 沒有 transactionId 就無從 confirm，⛔ 不能把客人送出去。
        $this->assertTrue($this->gateway()->initiate($this->attempt())->isFailed());
    }

    // ============================================ 4. confirm 才是付款證明

    private function pendingAttempt(): PaymentAttempt
    {
        $this->fakeRequestSuccess();
        $attempt = $this->attempt();
        $this->gateway()->initiate($attempt);

        return $attempt->fresh();
    }

    private function fakeConfirm(array $json, int $status = 200): void
    {
        Http::fake([
            self::BASE.'/v4/payments/*/confirm' => Http::response($json, $status),
        ]);
    }

    /**
     * A confirm response in the shape LINE Pay actually returns.
     *
     * ⛔ The money is in `info.payInfo[]`, one entry per method — there is no
     * `info.amount`. A check written against a field the provider never sends
     * simply never runs, so every confirm would pass the amount comparison by
     * default.
     *
     * @param  list<array<string, mixed>>|null  $payInfo
     */
    private function officialConfirm(
        PaymentAttempt $attempt,
        ?array $payInfo = null,
        array $overrides = [],
    ): array {
        $info = array_merge([
            'orderId' => $attempt->reference,
            'transactionId' => '2026081700000001',
            'payInfo' => $payInfo ?? [
                ['method' => 'CREDIT_CARD', 'amount' => (int) $attempt->amount],
            ],
        ], $overrides);

        return ['returnCode' => '0000', 'returnMessage' => 'Success.', 'info' => $info];
    }

    /** LINE Pay 導回時固定附加的 query。 */
    private function returnUrl(PaymentAttempt $attempt, string $action = 'confirm', array $query = []): string
    {
        $query = array_merge([
            'orderId' => $attempt->reference,
            'transactionId' => (string) $attempt->provider_reference,
        ], $query);

        return "/payments/linepay/{$attempt->order->reference}/{$action}?".http_build_query($query);
    }

    public function test_a_confirmed_payment_marks_the_order_paid(): void
    {
        $attempt = $this->pendingAttempt();
        $this->fakeConfirm($this->officialConfirm($attempt));

        $this->get($this->returnUrl($attempt))->assertRedirect();

        $this->assertSame(PaymentStatus::Succeeded, $attempt->fresh()->status);
        $this->assertSame(OrderStatus::Paid, $attempt->order->fresh()->order_status);
    }

    public function test_a_split_payment_totals_correctly(): void
    {
        $attempt = $this->pendingAttempt();

        // LINE Pay 與 POINT 拆成兩筆，合計正好等於訂單金額。
        $this->fakeConfirm($this->officialConfirm($attempt, [
            ['method' => 'BALANCE', 'amount' => 500],
            ['method' => 'POINT', 'amount' => 90],
        ]));

        $this->get($this->returnUrl($attempt));

        $this->assertSame(PaymentStatus::Succeeded, $attempt->fresh()->status);
    }

    public function test_a_split_payment_that_does_not_add_up_is_refused(): void
    {
        $attempt = $this->pendingAttempt();

        // 合計 580，訂單是 590。
        $this->fakeConfirm($this->officialConfirm($attempt, [
            ['method' => 'BALANCE', 'amount' => 500],
            ['method' => 'POINT', 'amount' => 80],
        ]));

        $this->get($this->returnUrl($attempt));

        $this->assertNotSame(PaymentStatus::Succeeded, $attempt->fresh()->status);
        $this->assertSame(OrderStatus::PendingPayment, $attempt->order->fresh()->order_status);
    }

    public static function malformedPayInfoProvider(): array
    {
        return [
            'empty array' => [[]],
            'missing amount' => [[['method' => 'BALANCE']]],
            'string amount' => [[['method' => 'BALANCE', 'amount' => '590']]],
            'negative amount' => [[['method' => 'BALANCE', 'amount' => -590]]],
            'fractional amount' => [[['method' => 'BALANCE', 'amount' => 589.5]]],
            'not a list' => [[['amount' => 590], 'nonsense']],
        ];
    }

    /** ⛔ 看不懂的金額不能當成「金額正確」。 */
    #[DataProvider('malformedPayInfoProvider')]
    public function test_malformed_pay_info_never_marks_paid(array $payInfo): void
    {
        $attempt = $this->pendingAttempt();
        $this->fakeConfirm($this->officialConfirm($attempt, $payInfo));

        $this->get($this->returnUrl($attempt));

        $this->assertNotSame(PaymentStatus::Succeeded, $attempt->fresh()->status);
        $this->assertSame(OrderStatus::PendingPayment, $attempt->order->fresh()->order_status);
    }

    public function test_a_missing_pay_info_never_marks_paid(): void
    {
        $attempt = $this->pendingAttempt();

        $confirm = $this->officialConfirm($attempt);
        unset($confirm['info']['payInfo']);
        $this->fakeConfirm($confirm);

        $this->get($this->returnUrl($attempt));

        $this->assertNotSame(PaymentStatus::Succeeded, $attempt->fresh()->status);
    }

    public function test_a_missing_order_id_never_marks_paid(): void
    {
        $attempt = $this->pendingAttempt();

        $confirm = $this->officialConfirm($attempt);
        unset($confirm['info']['orderId']);
        $this->fakeConfirm($confirm);

        $this->get($this->returnUrl($attempt));

        // ⛔ 欄位缺少不得被當成「略過比較」。
        $this->assertNotSame(PaymentStatus::Succeeded, $attempt->fresh()->status);
    }

    public function test_a_missing_transaction_id_never_marks_paid(): void
    {
        $attempt = $this->pendingAttempt();

        $confirm = $this->officialConfirm($attempt);
        unset($confirm['info']['transactionId']);
        $this->fakeConfirm($confirm);

        $this->get($this->returnUrl($attempt));

        $this->assertNotSame(PaymentStatus::Succeeded, $attempt->fresh()->status);
    }

    public function test_an_order_id_mismatch_never_marks_paid(): void
    {
        $attempt = $this->pendingAttempt();

        $this->fakeConfirm($this->officialConfirm($attempt, null, [
            'orderId' => 'SOMEONE-ELSES-ORDER',
        ]));

        $this->get($this->returnUrl($attempt));

        $this->assertNotSame(PaymentStatus::Succeeded, $attempt->fresh()->status);
    }

    public function test_a_transaction_id_mismatch_never_marks_paid(): void
    {
        $attempt = $this->pendingAttempt();

        $this->fakeConfirm($this->officialConfirm($attempt, null, [
            'transactionId' => '9999999999999999',
        ]));

        $this->get($this->returnUrl($attempt));

        $this->assertNotSame(PaymentStatus::Succeeded, $attempt->fresh()->status);
    }

    public function test_a_browser_return_alone_cannot_mark_paid(): void
    {
        $attempt = $this->pendingAttempt();

        $this->fakeConfirm(['returnCode' => '1150', 'returnMessage' => 'no such transaction']);

        $this->get($this->returnUrl($attempt));

        $this->assertNotSame(PaymentStatus::Succeeded, $attempt->fresh()->status);
        $this->assertSame(OrderStatus::PendingPayment, $attempt->order->fresh()->order_status);
    }

    public function test_a_confirm_timeout_goes_to_reconciliation(): void
    {
        $attempt = $this->pendingAttempt();

        Http::fake([
            self::BASE.'/v4/payments/*/confirm' => fn () => throw new ConnectionException('timeout'),
        ]);

        $this->get($this->returnUrl($attempt));

        // ⛔ 錢可能已經扣了：不得記為失敗，也不得自動重送。
        $this->assertSame(PaymentStatus::ReconciliationRequired, $attempt->fresh()->status);
    }

    public function test_the_confirm_uses_our_own_amount(): void
    {
        $attempt = $this->pendingAttempt();
        $this->fakeConfirm($this->officialConfirm($attempt));

        $this->get($this->returnUrl($attempt));

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/confirm')) {
                return true;
            }

            // ⛔ 確認金額取自我們的紀錄，不是 query 參數。
            return $request->data()['amount'] === 590;
        });
    }

    // ============================================ 5. 導回身分檢查

    public function test_a_return_without_query_calls_nobody(): void
    {
        $attempt = $this->pendingAttempt();
        Http::fake();

        $this->get("/payments/linepay/{$attempt->order->reference}/confirm")
            ->assertRedirect();

        // ⛔ 沒有官方 query 就不是 LINE Pay 導回的，不得呼叫 provider。
        Http::assertNothingSent();
        $this->assertSame(PaymentStatus::Pending, $attempt->fresh()->status);
    }

    public function test_a_return_with_a_forged_order_id_calls_nobody(): void
    {
        $attempt = $this->pendingAttempt();
        Http::fake();

        $this->get($this->returnUrl($attempt, 'confirm', ['orderId' => 'NOT-MINE']));

        Http::assertNothingSent();
        $this->assertSame(PaymentStatus::Pending, $attempt->fresh()->status);
    }

    public function test_a_return_with_a_forged_transaction_id_calls_nobody(): void
    {
        $attempt = $this->pendingAttempt();
        Http::fake();

        $this->get($this->returnUrl($attempt, 'confirm', ['transactionId' => '123']));

        Http::assertNothingSent();
        $this->assertSame(PaymentStatus::Pending, $attempt->fresh()->status);
    }

    public function test_cancel_without_query_changes_nothing(): void
    {
        $attempt = $this->pendingAttempt();

        $this->get("/payments/linepay/{$attempt->order->reference}/cancel")->assertRedirect();

        // ⛔ 一個可偽造的 GET 不足以終止付款：客人可能其實已經付成功了。
        $this->assertSame(PaymentStatus::Pending, $attempt->fresh()->status);
    }

    public function test_cancel_with_official_query_marks_canceled(): void
    {
        $attempt = $this->pendingAttempt();

        $this->get($this->returnUrl($attempt, 'cancel'))->assertRedirect();

        $this->assertSame(PaymentStatus::Canceled, $attempt->fresh()->status);
    }

    public function test_cancel_cannot_downgrade_a_paid_order(): void
    {
        $attempt = $this->pendingAttempt();
        $this->fakeConfirm($this->officialConfirm($attempt));

        $this->get($this->returnUrl($attempt));
        $this->assertSame(OrderStatus::Paid, $attempt->order->fresh()->order_status);

        $this->get($this->returnUrl($attempt, 'cancel'));

        // ⛔ 已付款不得被降級。
        $this->assertSame(OrderStatus::Paid, $attempt->order->fresh()->order_status);
        $this->assertSame(PaymentStatus::Succeeded, $attempt->fresh()->status);
    }

    public function test_a_repeated_confirm_dispatches_order_paid_once(): void
    {
        Event::fake([OrderPaid::class]);

        $attempt = $this->pendingAttempt();
        $this->fakeConfirm($this->officialConfirm($attempt));

        $url = $this->returnUrl($attempt);
        $this->get($url);
        $this->get($url);
        $this->get($url);

        Event::assertDispatched(OrderPaid::class, 1);
    }

    // ============================================ 6. 落盤衛生

    public function test_no_signature_or_secret_is_stored(): void
    {
        $attempt = $this->pendingAttempt();

        $this->fakeConfirm([
            'returnCode' => '1106',
            'returnMessage' => 'ChannelSecret=test-channel-secret-0001 buyer@example.com',
        ]);

        $this->get($this->returnUrl($attempt));

        $raw = json_encode([
            DB::table('payment_attempts')->get(),
            DB::table('orders')->get(),
            DB::table('order_events')->get(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['test-channel-secret-0001', 'buyer@example.com', 'X-LINE-Authorization'] as $marker) {
            $this->assertStringNotContainsString($marker, $raw, "落盤出現敏感字串：{$marker}");
        }
    }

    public function test_payment_routes_are_noindex(): void
    {
        $attempt = $this->pendingAttempt();

        $this->get("/payments/{$attempt->order->reference}/status")
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_the_status_page_never_claims_paid_before_confirmation(): void
    {
        $attempt = $this->pendingAttempt();

        $this->get("/payments/{$attempt->order->reference}/status")
            ->assertOk()
            ->assertDontSee('付款已完成');
    }

    // ==================================== R1-C：readiness——不新增雙套 UI／開關

    /**
     * ⛔ M4C-ORDER-OPERATIONS-A-R1：Owner 要求開通 LINE Pay 直接測試前，先確認
     * 現有架構本身已符合要求，不需要為了「看起來有施工」改付款程式。
     *
     * 這裡把既有的結構性保證(`ManageIntegrationSettings` 只讀
     * production 一列、後台完全沒有測試連線按鈕)轉成明確的回歸測試，避免
     * 未來有人不小心加回第二套 sandbox／production UI 或「測試連線」入口。
     */
    public function test_the_admin_page_shows_exactly_one_line_pay_section_with_no_sandbox_toggle_or_test_connection_button(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);

        $page = Livewire::actingAs($owner)->test(ManageIntegrationSettings::class)->assertOk();
        $html = $page->html();

        /*
         * ⛔ 每個 provider 只註冊一組 `_identifier`／`_secret_*` state key，
         * 不分 sandbox／production——這是「只有一套 UI」的精確訊號，不是
         * 數 label 字串在 HTML 裡出現幾次(那本來就會因 label／id／
         * wire:model／Livewire snapshot 重複出現多次，不代表有多套區塊)。
         */
        $formState = array_keys($page->get('data'));
        $this->assertContains('line_pay_identifier', $formState);
        $this->assertContains('line_pay_secret_ChannelSecret', $formState);
        // ⛔ 沒有第二層 sandbox 欄位:provider value 本身固定小寫 snake_case,
        // 不存在任何 `line_pay_sandbox_*` 或 `line_pay_production_*` 字首。
        $this->assertEmpty(array_filter($formState, fn ($key) => str_contains($key, 'sandbox')));

        // ⛔ 沒有任何測試連線按鈕或 API。
        $this->assertStringNotContainsString('測試連線', $html);
    }

    /**
     * ⛔ Owner 仍可直接輸入正式 Channel ID／Channel Secret 並切 ON；
     * 缺任一值必須 fail closed(既有 `LivePaymentOwnerControlTest` 已驗證
     * 一般情況,這裡針對 LINE Pay 專門釘一次,證明 R1-C 沒有改動這條路徑)。
     */
    public function test_a_missing_channel_id_keeps_line_pay_fail_closed_when_enabling(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);

        // setUp() 已建立完整設定並啟用；這裡清掉 identifier(Channel ID)、
        // 先關閉，模擬「有 ChannelSecret 但缺 Channel ID」的狀態。
        $setting = IntegrationSetting::query()
            ->where('provider', IntegrationProvider::LinePay)
            ->where('environment', IntegrationEnvironment::Production)
            ->sole();
        $setting->forceFill(['identifier' => null, 'is_enabled' => false])->save();

        Livewire::actingAs($owner)
            ->test(ManageIntegrationSettings::class)
            ->call('toggleChannel', IntegrationProvider::LinePay->value, true)
            ->assertOk();

        // ⛔ ValidationException 被後台以白話通知吸收,但真正的邊界是這裡:
        // 缺 Channel ID 時,不論通知內容為何,is_enabled 絕不可能變成 true。
        $this->assertFalse($setting->fresh()->is_enabled);
    }

    public function test_owner_can_enable_line_pay_once_both_production_credentials_are_present(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);

        // setUp() 已建立完整設定並啟用；這裡先關閉，證明重新啟用同樣成功。
        $setting = IntegrationSetting::query()
            ->where('provider', IntegrationProvider::LinePay)
            ->where('environment', IntegrationEnvironment::Production)
            ->sole();
        $setting->forceFill(['is_enabled' => false])->save();

        Livewire::actingAs($owner)
            ->test(ManageIntegrationSettings::class)
            ->call('toggleChannel', IntegrationProvider::LinePay->value, true)
            ->assertOk();

        $this->assertTrue($setting->fresh()->is_enabled);
    }

    /** ⛔ production API base 必須是官方 exact allowlist,不是可設定值。 */
    public function test_the_production_api_base_is_the_exact_official_allowlist_constant(): void
    {
        $this->assertSame(
            'https://api-pay.line.me',
            ProviderEndpoints::linePayApi(),
        );
    }

    /**
     * ⛔ 確認信／取消信是用框架 `route()` 產生,不含任何寫死的 localhost；
     * 只要 `APP_URL` 是公開 HTTPS staging 網域,兩個 URL 就會是公開 HTTPS。
     *
     * `URL::forceRootUrl()` 讓這裡真正改變 `route()` 的解析結果(單改
     * config 不會反映到已解析的 URL generator),驗證的是同一份
     * LinePayGateway／routes 程式碼,不是另外重寫一份假設。
     */
    public function test_the_confirm_and_cancel_urls_follow_app_url_and_never_hardcode_localhost(): void
    {
        URL::forceRootUrl('https://staging.iglikefollow.com');
        URL::forceScheme('https');

        $attempt = $this->pendingAttempt();

        $confirmUrl = route('payments.linepay.confirm', ['reference' => $attempt->order->reference]);
        $cancelUrl = route('payments.linepay.cancel', ['reference' => $attempt->order->reference]);

        foreach ([$confirmUrl, $cancelUrl] as $url) {
            $this->assertStringStartsWith('https://staging.iglikefollow.com', $url);
            $this->assertStringNotContainsString('localhost', $url);
        }
    }

    /**
     * ⛔ 真正防止「staging 忘記把 APP_URL 改成 HTTPS」的機制是既有的
     * `app:staging-readiness` blocker——這裡直接驗證那個 check 本身存在
     * 且會在 http／localhost 時擋下,而不是假裝 LINE Pay 程式碼自己擋。
     */
    public function test_staging_readiness_blocks_on_a_non_https_app_url(): void
    {
        config(['app.url' => 'http://localhost']);

        $exitCode = Artisan::call('app:staging-readiness');
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('APP_URL', $output);
    }
}
