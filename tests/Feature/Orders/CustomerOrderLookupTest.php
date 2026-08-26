<?php

namespace Tests\Feature\Orders;

use App\Actions\Orders\CreatePendingOrder;
use App\Actions\Orders\FindOrdersForCustomer;
use App\Enums\FulfillmentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Http\Middleware\NeverIndex;
use App\Models\FulfillmentOrder;
use App\Models\Order;
use App\Models\ServiceVariant;
use App\Support\ContactLookupHash;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The public, account-free order lookup.
 *
 * ⭐ Owner 規則：訂單編號、Email、手機**任選兩項**；三項都填時三項都要相符。
 *
 * ⛔ 這個檔案的重點有兩個，兩者同等重要：
 *
 *  1. **查得到自己的訂單**——功能要真的能用。
 *  2. **查不到別人的，也看不到不該看的**——公開頁只准出現 allowlist 欄位，
 *     且 Email／手機絕不能進 URL、redirect、log 或 HTML。
 *
 * ⛔ 全程 0 外呼。
 */
class CustomerOrderLookupTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'buyer@example.test';

    private const PHONE = '0912345678';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    /** 一張已付款、含一個商品項目的訂單。 */
    private function orderFor(array $overrides = []): Order
    {
        $order = Order::factory()->create(array_merge([
            'order_status' => OrderStatus::Paid,
            'payment_status' => PaymentStatus::Succeeded,
            'paid_at' => now(),
            'customer_email' => self::EMAIL,
            'customer_phone' => self::PHONE,
            'customer_email_lookup_hash' => ContactLookupHash::forEmail(self::EMAIL),
            'customer_phone_lookup_hash' => ContactLookupHash::forPhone(self::PHONE),
        ], $overrides));

        $order->items()->create([
            'platform_name' => 'Instagram',
            'service_name' => 'Instagram 粉絲',
            'variant_label' => '一般粉絲',
            'sku' => 'ig-followers-standard',
            'unit_price_mills' => 5900,
            'quantity' => 1000,
            'quantity_unit' => '個',
            'amount' => 590,
            'target_kind' => 'account',
            'target_value' => 'secret_customer_account',
        ]);

        return $order->fresh();
    }

    private function lookup(array $payload)
    {
        return $this->post('/order-lookup', $payload);
    }

    // ==================================== 1. 三種二項組合

    public static function twoOfThreeProvider(): array
    {
        return [
            'reference + email' => ['reference', 'email'],
            'reference + phone' => ['reference', 'phone'],
            'email + phone' => ['email', 'phone'],
        ];
    }

    #[DataProvider('twoOfThreeProvider')]
    public function test_any_two_of_three_identifiers_find_the_order(string $a, string $b): void
    {
        $order = $this->orderFor();

        $all = [
            'reference' => $order->reference,
            'email' => self::EMAIL,
            'phone' => self::PHONE,
        ];

        $response = $this->lookup([$a => $all[$a], $b => $all[$b]]);

        $response->assertOk();
        $response->assertSee($order->reference);
        $response->assertSee('Instagram 粉絲');
    }

    /** 三項全符：仍然查得到。 */
    public function test_all_three_matching_identifiers_find_the_order(): void
    {
        $order = $this->orderFor();

        $this->lookup([
            'reference' => $order->reference,
            'email' => self::EMAIL,
            'phone' => self::PHONE,
        ])->assertOk()->assertSee($order->reference);
    }

    /**
     * ⛔ 三項都填但其中一項不符：查不到。
     *
     * 這是 AND 而不是 OR 的關鍵測試。OR 會讓「編號對、Email 錯」也查得到，
     * 等於把兩項門檻降回一項。
     */
    public static function thirdFieldWrongProvider(): array
    {
        return [
            // ⛔ 必須是**合法形狀但不同**的編號：形狀不合法會被視為「沒填」，
            // 那樣測到的是「兩項門檻」而不是「第三項不符」。
            'wrong reference' => ['reference', 'IGL-ZZZZZZZZZZZZ'],
            'wrong email' => ['email', 'someone-else@example.test'],
            'wrong phone' => ['phone', '0900000000'],
        ];
    }

    #[DataProvider('thirdFieldWrongProvider')]
    public function test_a_single_wrong_field_finds_nothing(string $field, string $wrong): void
    {
        $order = $this->orderFor();

        $payload = [
            'reference' => $order->reference,
            'email' => self::EMAIL,
            'phone' => self::PHONE,
        ];
        $payload[$field] = $wrong;

        $response = $this->lookup($payload);

        $response->assertOk();
        $response->assertDontSee($order->reference);
        $response->assertSee('查不到符合的訂單');
    }

    /** ⛔ 少於兩項：一律查不到，不退化成單項查詢。 */
    public static function insufficientProvider(): array
    {
        return [
            'reference only' => ['reference'],
            'email only' => ['email'],
            'phone only' => ['phone'],
        ];
    }

    #[DataProvider('insufficientProvider')]
    public function test_a_single_identifier_is_never_enough(string $field): void
    {
        $order = $this->orderFor();

        $all = [
            'reference' => $order->reference,
            'email' => self::EMAIL,
            'phone' => self::PHONE,
        ];

        $response = $this->lookup([$field => $all[$field]]);

        $response->assertOk();
        $response->assertDontSee($order->reference);
        $response->assertSee('查不到符合的訂單');
    }

    public function test_an_empty_submission_finds_nothing(): void
    {
        $this->orderFor();

        $this->lookup([])->assertOk()->assertSee('查不到符合的訂單');
    }

    // ==================================== 2. 正規化

    /** Email 大小寫與前後空白不影響匹配。 */
    public function test_email_matching_ignores_case_and_surrounding_space(): void
    {
        $order = $this->orderFor();

        $this->lookup([
            'reference' => $order->reference,
            'email' => '  BUYER@Example.TEST  ',
        ])->assertOk()->assertSee($order->reference);
    }

    /** 手機的格式字元（`+ - ( ) 空白`）不影響匹配。 */
    public static function phoneFormatProvider(): array
    {
        return [
            'plain' => ['0912345678'],
            'dashes' => ['0912-345-678'],
            'spaces' => ['0912 345 678'],
            'parens' => ['(0912)345678'],
        ];
    }

    #[DataProvider('phoneFormatProvider')]
    public function test_phone_matching_ignores_allowed_format_characters(string $typed): void
    {
        $order = $this->orderFor();

        $this->lookup([
            'reference' => $order->reference,
            'phone' => $typed,
        ])->assertOk()->assertSee($order->reference);
    }

    /**
     * ⛔ 不自行把 `+886` 與 `09` 推定為同一支號碼。
     *
     * 那是一個關於台灣號碼格式的假設，而這個判斷決定「誰能看到哪一張訂單」
     * ——猜錯的方向是讓甲看到乙的訂單。
     */
    public function test_a_different_country_code_form_is_not_assumed_equal(): void
    {
        $order = $this->orderFor();

        $this->lookup([
            'reference' => $order->reference,
            'phone' => '+886912345678',
        ])->assertOk()->assertDontSee($order->reference);
    }

    /** 沒有手機的訂單：phone hash 為 null，用手機查不到。 */
    public function test_an_order_without_a_phone_cannot_be_found_by_phone(): void
    {
        $order = $this->orderFor([
            'customer_phone' => null,
            'customer_phone_lookup_hash' => null,
        ]);

        $this->lookup([
            'email' => self::EMAIL,
            'phone' => self::PHONE,
        ])->assertOk()->assertDontSee($order->reference);

        // 但 email + reference 仍然查得到。
        $this->lookup([
            'reference' => $order->reference,
            'email' => self::EMAIL,
        ])->assertOk()->assertSee($order->reference);
    }

    /** ⛔ 訂單編號只接受本站合法形狀，不做前綴或模糊搜尋。 */
    public static function malformedReferenceProvider(): array
    {
        return [
            'prefix only' => ['IGL-'],
            'too short' => ['IGL-ABC'],
            'wildcard' => ['IGL-%'],
            'sql-ish' => ["IGL-' OR '1'='1"],
            'no prefix' => ['ABCDEFGHIJKL'],
            'too long' => ['IGL-ABCDEFGHIJKLM'],
        ];
    }

    #[DataProvider('malformedReferenceProvider')]
    public function test_a_malformed_reference_never_matches(string $reference): void
    {
        $order = $this->orderFor();

        $this->lookup([
            'reference' => $reference,
            'email' => self::EMAIL,
        ])->assertOk()->assertDontSee($order->reference);
    }

    // ==================================== 3. 多訂單／多商品

    public function test_email_and_phone_return_every_matching_order(): void
    {
        $first = $this->orderFor();
        $second = $this->orderFor();

        $response = $this->lookup(['email' => self::EMAIL, 'phone' => self::PHONE]);

        $response->assertOk();
        $response->assertSee($first->reference);
        $response->assertSee($second->reference);
        $response->assertSee('共 2 筆訂單');
    }

    /** ⛔ 只回傳符合條件的訂單，不得混入別人的。 */
    public function test_another_customers_order_is_never_returned(): void
    {
        $mine = $this->orderFor();

        $theirs = Order::factory()->create([
            'customer_email' => 'other@example.test',
            'customer_email_lookup_hash' => ContactLookupHash::forEmail('other@example.test'),
            'customer_phone_lookup_hash' => ContactLookupHash::forPhone('0987654321'),
        ]);

        $response = $this->lookup(['email' => self::EMAIL, 'phone' => self::PHONE]);

        $response->assertOk();
        $response->assertSee($mine->reference);
        $response->assertDontSee($theirs->reference);
    }

    public function test_multiple_items_in_one_order_are_all_listed(): void
    {
        $order = $this->orderFor();
        $order->items()->create([
            'platform_name' => 'Facebook',
            'service_name' => 'Facebook 讚',
            'variant_label' => '貼文讚',
            'sku' => 'fb-likes-standard',
            'unit_price_mills' => 3900,
            'quantity' => 500,
            'quantity_unit' => '個',
            'amount' => 195,
            'target_kind' => 'post',
            'target_value' => 'https://example.invalid/secret-post',
        ]);

        $response = $this->lookup(['reference' => $order->reference, 'email' => self::EMAIL]);

        $response->assertOk();
        $response->assertSee('Instagram 粉絲');
        $response->assertSee('Facebook 讚');
    }

    // ==================================== 4. 公開狀態語意

    /**
     * ⛔ 需要人處理的四種狀態一律「請聯絡客服」，⛔ 不冒充「進行中」。
     *
     * 把它們顯示成進行中，會讓客人一直等一個永遠不會到的結果。
     *
     * @return array<string, array{0: FulfillmentStatus, 1: string}>
     */
    public static function fulfillmentStatusProvider(): array
    {
        return [
            'completed' => [FulfillmentStatus::Completed, '已完成'],
            'processing' => [FulfillmentStatus::Processing, '進行中'],
            'submitted' => [FulfillmentStatus::Submitted, '進行中'],
            'partial' => [FulfillmentStatus::Partial, '請聯絡客服'],
            'canceled' => [FulfillmentStatus::Canceled, '請聯絡客服'],
            'failed' => [FulfillmentStatus::Failed, '請聯絡客服'],
            'submission unknown' => [FulfillmentStatus::SubmissionUnknown, '請聯絡客服'],
        ];
    }

    #[DataProvider('fulfillmentStatusProvider')]
    public function test_the_public_status_never_misrepresents_a_problem(
        FulfillmentStatus $status,
        string $expected,
    ): void {
        $order = $this->orderFor();
        $row = FulfillmentOrder::factory()->submitted('99001')->create([
            'order_item_id' => $order->items()->first()->id,
        ]);
        $row->forceFill(['status' => $status])->save();

        $response = $this->lookup(['reference' => $order->reference, 'email' => self::EMAIL]);

        $response->assertOk();
        $response->assertSee($expected);
    }

    /** 未付款：顯示本站真實狀態，⛔ 不假裝進行中。 */
    public function test_an_unpaid_order_shows_its_real_local_status(): void
    {
        $order = $this->orderFor([
            'order_status' => OrderStatus::PendingPayment,
            'payment_status' => PaymentStatus::Pending,
            'paid_at' => null,
        ]);

        $response = $this->lookup(['reference' => $order->reference, 'email' => self::EMAIL]);

        $response->assertOk();
        $response->assertSee('等待付款');
        $response->assertDontSee('進行中');
    }

    /** 已付款但尚未建立履約：誠實說「準備中」。 */
    public function test_a_paid_order_without_fulfillment_shows_preparing(): void
    {
        $order = $this->orderFor();

        $this->lookup(['reference' => $order->reference, 'email' => self::EMAIL])
            ->assertOk()
            ->assertSee('準備中');
    }

    /** 剩餘數量：null 顯示「更新中」，0 顯示 `0`，⛔ 不推算。 */
    public function test_remains_is_shown_only_when_actually_known(): void
    {
        $order = $this->orderFor();
        $row = FulfillmentOrder::factory()->submitted('99002')->create([
            'order_item_id' => $order->items()->first()->id,
        ]);

        // 尚未同步。
        $this->lookup(['reference' => $order->reference, 'email' => self::EMAIL])
            ->assertOk()->assertSee('更新中');

        // 0 是真實值。
        $row->forceFill(['provider_remains' => 0, 'status' => FulfillmentStatus::Completed])->save();

        $response = $this->lookup(['reference' => $order->reference, 'email' => self::EMAIL]);
        $response->assertOk();
        $response->assertSee('已完成');
        $response->assertDontSee('更新中');
    }

    // ==================================== 5. 公開頁不得洩漏

    /**
     * ⭐ 這是本輪最重要的安全測試。
     *
     * 把所有不該出現的東西都填成明顯的 sentinel 值，然後確認公開回應裡
     * 一個字都沒有。
     */
    public function test_the_public_response_leaks_nothing_sensitive(): void
    {
        $order = $this->orderFor();
        $item = $order->items()->first();

        $row = FulfillmentOrder::factory()->submitted('SENTINEL-PROVIDER-ORDER-ID')->create([
            'order_item_id' => $item->id,
            'provider_service_id_snapshot' => 'SENTINEL-SERVICE-ID',
            'provider_service_name_snapshot' => 'SENTINEL-PROVIDER-SERVICE-NAME',
        ]);
        $row->forceFill([
            'provider_status_code' => 'SENTINEL-RAW-STATUS',
            'provider_remains' => 42,
        ])->save();

        $response = $this->lookup(['reference' => $order->reference, 'email' => self::EMAIL]);
        $response->assertOk();

        $html = $response->getContent();

        foreach ([
            // provider 資料。
            'SENTINEL-PROVIDER-ORDER-ID', 'SENTINEL-SERVICE-ID',
            'SENTINEL-PROVIDER-SERVICE-NAME', 'SENTINEL-RAW-STATUS',
            // 供應商字樣。
            'TheMostPanel', 'themostpanel', 'SMM',
            // 客戶個資與交付目標。
            self::EMAIL, self::PHONE, 'secret_customer_account',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, (string) $html, "公開頁外洩：{$forbidden}");
        }
    }

    /** ⛔ Email／手機不得出現在 URL、redirect location 或 session。 */
    public function test_no_personal_data_reaches_the_url_or_a_redirect(): void
    {
        $order = $this->orderFor();

        $response = $this->lookup([
            'reference' => $order->reference,
            'email' => self::EMAIL,
            'phone' => self::PHONE,
        ]);

        // ⛔ 直接 render，不 redirect——redirect 會把條件推進 URL 或 session。
        $response->assertOk();
        $this->assertNull($response->headers->get('Location'));

        $serialisedSession = json_encode(session()->all());

        foreach ([self::EMAIL, self::PHONE] as $secret) {
            $this->assertStringNotContainsString($secret, (string) $serialisedSession);
        }
    }

    // ==================================== 6. Route／header／CSRF

    /** ⛔ 只接受 POST：GET 會把條件放進 URL。 */
    public function test_the_lookup_route_rejects_get(): void
    {
        $this->get('/order-lookup')->assertStatus(405);
    }

    public function test_the_result_page_is_never_indexable_or_cacheable(): void
    {
        $order = $this->orderFor();

        $response = $this->lookup(['reference' => $order->reference, 'email' => self::EMAIL]);

        $response->assertOk();

        $robots = (string) $response->headers->get('X-Robots-Tag');
        $this->assertStringContainsString('noindex', $robots);
        $this->assertStringContainsString('nofollow', $robots);

        $cache = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('private', $cache);
        $this->assertStringContainsString('no-store', $cache);

        // meta robots 作為第二道。
        $response->assertSee('noindex, nofollow', false);
    }

    /**
     * ⛔ CSRF 與 throttle 必須實際掛在這條 route 上。
     *
     * ⛔ 這裡檢查 route 的 middleware 清單，而不是送一個沒有 token 的請求
     * ——Laravel 的 feature test 預設停用 CSRF 驗證，那樣的請求會通過，
     * 得到一個「看起來有測、其實沒測」的綠燈。
     *
     * `web` group 已包含 `ValidateCsrfToken`，所以確認這條 route 在 web
     * group 內、且另外掛了嚴格 throttle 與 NeverIndex。
     */
    public function test_the_lookup_route_enforces_csrf_throttle_and_noindex(): void
    {
        $route = collect(app('router')->getRoutes())
            ->first(fn ($r) => $r->uri() === 'order-lookup');

        $this->assertNotNull($route, '找不到 order-lookup route');

        // ⛔ POST only。
        $this->assertSame(['POST'], array_values(array_diff($route->methods(), ['HEAD'])));

        $middleware = $route->gatherMiddleware();

        // ⛔ 嚴格 throttle：擋住拿一份 Email 名單慢慢試的行為。
        $this->assertContains('throttle:10,1', $middleware);
        // ⛔ 永不索引。
        $this->assertContains(NeverIndex::class, $middleware);

        /*
         * ⛔ CSRF 由 `web` group 提供。
         *
         * `gatherMiddleware()` 不展開 group，所以這裡先確認 route 在 web
         * group 內，再確認該 group 確實含 `ValidateCsrfToken`——兩步都要，
         * 只驗其中一步都可能漏掉真正的問題。
         */
        $this->assertContains('web', $middleware);

        $this->assertContains(
            ValidateCsrfToken::class,
            app('router')->getMiddlewareGroups()['web'],
        );
    }

    // ==================================== 7. HMAC 與資料層

    /** ⛔ Email 與手機使用不同 domain：同一字串不得產生相同 hash。 */
    public function test_email_and_phone_hashes_use_different_domains(): void
    {
        $same = '0912345678';

        $this->assertNotSame(
            ContactLookupHash::forEmail($same),
            ContactLookupHash::forPhone($same),
            '⛔ 缺少 domain separation：同一字串在兩個欄位會互相匹配。',
        );
    }

    /** ⛔ hash 必須綁定 APP_KEY：換 key 後結果必須改變。 */
    public function test_the_hash_depends_on_the_application_key(): void
    {
        $first = ContactLookupHash::forEmail(self::EMAIL);

        config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        $this->assertNotSame($first, ContactLookupHash::forEmail(self::EMAIL));
        // ⛔ 也不得等於無 key 的裸雜湊。
        $this->assertNotSame(hash('sha256', self::EMAIL), $first);
    }

    /** ⛔ raw DB 不得出現 Email／手機明文的 lookup 欄位。 */
    public function test_the_lookup_columns_never_store_plaintext(): void
    {
        $this->orderFor();

        $raw = json_encode(DB::table('orders')->get(['customer_email_lookup_hash', 'customer_phone_lookup_hash']));

        $this->assertStringNotContainsString(self::EMAIL, (string) $raw);
        $this->assertStringNotContainsString(self::PHONE, (string) $raw);
        // 64 個十六進位字元。
        $this->assertMatchesRegularExpression(
            '/[0-9a-f]{64}/',
            (string) DB::table('orders')->value('customer_email_lookup_hash'),
        );
    }

    /** action 層：少於兩項回空集合。 */
    public function test_the_finder_requires_two_identifiers(): void
    {
        $order = $this->orderFor();
        $finder = app(FindOrdersForCustomer::class);

        $this->assertCount(0, $finder->handle($order->reference, null, null));
        $this->assertCount(0, $finder->handle(null, self::EMAIL, null));
        $this->assertCount(1, $finder->handle($order->reference, self::EMAIL, null));
    }

    /** ⛔ 新訂單在建立時就寫入 hash（與訂單同一 transaction）。 */
    public function test_a_new_order_gets_its_lookup_hashes_on_creation(): void
    {
        $variant = ServiceVariant::factory()->create();

        $order = app(CreatePendingOrder::class)->handle(
            $variant,
            $variant->min_quantity,
            [
                'customer_email' => self::EMAIL,
                'customer_phone' => self::PHONE,
                'invoice_kind' => 'personal',
                'personal_invoice_mode' => 'email',
                'target' => 'example_account',
            ],
            'token-'.uniqid(),
            'ecpay',
        );

        $this->assertSame(ContactLookupHash::forEmail(self::EMAIL), $order->customer_email_lookup_hash);
        $this->assertSame(ContactLookupHash::forPhone(self::PHONE), $order->customer_phone_lookup_hash);
    }

    // ==================================== 8. Backfill command

    /** ⛔ 預設 dry-run：0 寫入。 */
    public function test_the_backfill_defaults_to_dry_run(): void
    {
        $order = $this->orderFor([
            'customer_email_lookup_hash' => null,
            'customer_phone_lookup_hash' => null,
        ]);

        $this->artisan('orders:backfill-lookup-hashes')
            ->expectsOutputToContain('dry-run')
            ->assertSuccessful();

        // ⛔ 什麼都沒改。
        $this->assertNull($order->fresh()->customer_email_lookup_hash);
    }

    /** `--apply` 才寫入，且 idempotent。 */
    public function test_the_backfill_applies_and_is_idempotent(): void
    {
        $order = $this->orderFor([
            'customer_email_lookup_hash' => null,
            'customer_phone_lookup_hash' => null,
        ]);

        $this->artisan('orders:backfill-lookup-hashes --apply')->assertSuccessful();

        $filled = $order->fresh();
        $this->assertSame(ContactLookupHash::forEmail(self::EMAIL), $filled->customer_email_lookup_hash);
        $this->assertSame(ContactLookupHash::forPhone(self::PHONE), $filled->customer_phone_lookup_hash);

        // ⛔ 重跑不改變任何值。
        $this->artisan('orders:backfill-lookup-hashes --apply')->assertSuccessful();

        $again = $order->fresh();
        $this->assertSame($filled->customer_email_lookup_hash, $again->customer_email_lookup_hash);
        $this->assertSame($filled->customer_phone_lookup_hash, $again->customer_phone_lookup_hash);
    }

    /** ⛔ 輸出只有計數，不含 reference、Email、手機或 hash。 */
    public function test_the_backfill_output_contains_no_personal_data(): void
    {
        $order = $this->orderFor([
            'customer_email_lookup_hash' => null,
            'customer_phone_lookup_hash' => null,
        ]);

        $this->artisan('orders:backfill-lookup-hashes --apply')->assertSuccessful();

        $output = Artisan::output();

        foreach ([
            self::EMAIL, self::PHONE, $order->reference,
            (string) ContactLookupHash::forEmail(self::EMAIL),
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $output, "backfill 輸出外洩：{$forbidden}");
        }
    }

    /** 補完之後，既有訂單就查得到了。 */
    public function test_a_backfilled_order_becomes_findable(): void
    {
        $order = $this->orderFor([
            'customer_email_lookup_hash' => null,
            'customer_phone_lookup_hash' => null,
        ]);

        // 補之前查不到。
        $this->lookup(['email' => self::EMAIL, 'phone' => self::PHONE])
            ->assertOk()->assertDontSee($order->reference);

        $this->artisan('orders:backfill-lookup-hashes --apply')->assertSuccessful();

        // 補之後查得到。
        $this->lookup(['email' => self::EMAIL, 'phone' => self::PHONE])
            ->assertOk()->assertSee($order->reference);
    }

    // ==================================== 9. 首頁 SEO 不變

    /** ⛔ 查詢區塊必須在**初始 HTML**，且首頁既有 SEO 元素不變。 */
    public function test_the_home_page_keeps_its_seo_and_gains_the_lookup_section(): void
    {
        $response = $this->get('/');

        $response->assertOk();

        $html = (string) $response->getContent();

        // 新區塊存在於初始 HTML（不是 JS 產生）。
        $this->assertStringContainsString('id="order-lookup"', $html);
        $this->assertStringContainsString('訂單查詢', $html);
        $this->assertStringContainsString(route('order-lookup'), $html);

        // ⛔ 首頁仍只有一個 H1。
        $this->assertSame(1, substr_count($html, '<h1'), '⛔ 首頁必須維持單一 H1。');

        // ⛔ 查詢區塊用 H2，不搶 H1。
        $this->assertMatchesRegularExpression('/<h2[^>]*>訂單查詢<\/h2>/u', $html);
    }
}
