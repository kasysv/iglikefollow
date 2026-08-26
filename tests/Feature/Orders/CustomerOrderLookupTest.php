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
use App\Support\PublicOrderPresenter;
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
        return $this->post('/order-check', $payload);
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
     * ⭐ R1：Owner 後補的台灣國碼等價規則。
     *
     * `09XXXXXXXX`、`+8869XXXXXXXX`、`008869XXXXXXXX` 是同一支號碼的三種
     * 寫法，必須雙向命中。
     *
     * ⛔ 初版明文主張「不等價」，與 Owner 最新要求相反——已撤回。
     *
     * @return array<string, array{0: string}>
     */
    public static function taiwanEquivalentPhoneProvider(): array
    {
        return [
            'local' => ['0912345678'],
            'plus 886' => ['+886912345678'],
            'zero zero 886' => ['00886912345678'],
        ];
    }

    #[DataProvider('taiwanEquivalentPhoneProvider')]
    public function test_taiwan_phone_forms_are_equivalent_in_both_directions(string $stored): void
    {
        // 以其中一種寫法建立訂單。
        $order = $this->orderFor([
            'customer_phone' => $stored,
            'customer_phone_lookup_hash' => ContactLookupHash::forPhone($stored),
        ]);

        // ⛔ 三種寫法都必須查得到。
        foreach (['0912345678', '+886912345678', '00886912345678'] as $typed) {
            $this->lookup([
                'reference' => $order->reference,
                'phone' => $typed,
            ])->assertOk()->assertSee($order->reference, false);
        }
    }

    /**
     * ⛔ 相鄰／模糊形狀不得誤撞。
     *
     * ⛔ 裸 `886912345678`（沒有 `+` 或 `00`）不視為國際格式——那是一段我們
     * 無法確定意圖的數字，可能是別國的本地號碼。
     *
     * @return array<string, array{0: string}>
     */
    public static function nonEquivalentPhoneProvider(): array
    {
        return [
            'bare 886 is not international' => ['886912345678'],
            'one digit short' => ['091234567'],
            'one digit long' => ['09123456789'],
            'landline' => ['0223456789'],
            'different number' => ['0987654321'],
        ];
    }

    #[DataProvider('nonEquivalentPhoneProvider')]
    public function test_adjacent_phone_shapes_never_collide(string $typed): void
    {
        $order = $this->orderFor();

        $this->lookup([
            'reference' => $order->reference,
            'phone' => $typed,
        ])->assertOk()->assertDontSee($order->reference, false);
    }

    /** ⭐ 明確國際前綴：`+1…` 與 `001…` 等價；裸數字不自動等價。 */
    public function test_explicit_international_prefixes_are_equivalent(): void
    {
        $order = $this->orderFor([
            'customer_phone' => '+14155552671',
            'customer_phone_lookup_hash' => ContactLookupHash::forPhone('+14155552671'),
        ]);

        foreach (['+14155552671', '0014155552671'] as $typed) {
            $this->lookup([
                'reference' => $order->reference,
                'phone' => $typed,
            ])->assertOk()->assertSee($order->reference, false);
        }

        // ⛔ 裸 `14155552671` 沒有明確國際前綴，不得自動等價。
        $this->lookup([
            'reference' => $order->reference,
            'phone' => '14155552671',
        ])->assertOk()->assertDontSee($order->reference, false);
    }

    /** normalizePhone 的封閉輸出：三類語意各帶前綴，⛔ 永不互撞。 */
    public function test_phone_canonicalization_uses_distinct_namespaces(): void
    {
        $this->assertSame('TW:0912345678', ContactLookupHash::normalizePhone('0912345678'));
        $this->assertSame('TW:0912345678', ContactLookupHash::normalizePhone('+886912345678'));
        $this->assertSame('TW:0912345678', ContactLookupHash::normalizePhone('00886912345678'));
        $this->assertSame('INT:+14155552671', ContactLookupHash::normalizePhone('+14155552671'));
        $this->assertSame('INT:+14155552671', ContactLookupHash::normalizePhone('0014155552671'));
        $this->assertSame('RAW:886912345678', ContactLookupHash::normalizePhone('886912345678'));
        $this->assertSame('RAW:0223456789', ContactLookupHash::normalizePhone('0223456789'));

        // ⛔ 非數字一律 null。
        $this->assertNull(ContactLookupHash::normalizePhone('abc'));
        $this->assertNull(ContactLookupHash::normalizePhone(''));
    }

    // ==================================== 2b. AND bypass（R1）

    /**
     * ⭐ R1 反證：**已提供但無效**的第三欄必須讓整次查詢 no-match。
     *
     * ⛔ 初版先正規化、再數有幾個非 null——於是無效的第三欄被正規化成 null
     * 而**靜默消失**，剩下的兩項仍然成立，查詢照樣命中。那等於「填錯的欄位
     * 不算數」，把 AND 門檻悄悄降回兩項。
     *
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function malformedThirdFieldProvider(): array
    {
        return [
            'malformed reference' => [['reference' => 'IGL-%']],
            'short reference' => [['reference' => 'IGL-ABC']],
            'array reference' => [['reference' => ['IGL-ABCDEFGHIJKL']]],
            'malformed phone' => [['phone' => 'not-a-phone']],
            'array phone' => [['phone' => ['0912345678']]],
            'array email' => [['email' => ['buyer@example.test']]],

            // ⭐ R2：通過 checkout 邊界檢查的非法第三欄同樣必須 no-match。
            'invalid international phone' => [['phone' => '+01234567']],
            'zero prefixed international' => [['phone' => '0001234567']],
            'over long phone' => [['phone' => '090012345678901234567']],
            'too short phone' => [['phone' => '09123']],
            'illegal phone character' => [['phone' => '09*2345678']],
            'invalid email' => [['email' => 'not-an-email']],
            'over long email' => [['email' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa@sub.example-domain.test']],
        ];
    }

    #[DataProvider('malformedThirdFieldProvider')]
    public function test_a_supplied_but_invalid_field_forces_no_match(array $extra): void
    {
        $order = $this->orderFor();

        // 兩個正確欄位 ＋ 一個已提供但無效的欄位。
        $payload = array_merge([
            'email' => self::EMAIL,
            'phone' => self::PHONE,
        ], $extra);

        // 若被查詢的欄位本身就是 email／phone，改用 reference 當第二個有效項。
        if (isset($extra['email'])) {
            $payload['reference'] = $order->reference;
        }

        $response = $this->lookup($payload);

        $response->assertOk();
        $response->assertDontSee($order->reference, false);
        $response->assertSee('查不到符合的訂單');
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

    // ==================================== 2c. R2：checkout 輸入邊界

    /**
     * ⛔⭐ R2 的核心反證：無效的**明確國際格式**不得降級成 `RAW:`。
     *
     * ⛔ R1 的 bug：`+01234567` 形狀不合格後掉進最後的 `RAW:` 分支，變成
     * `RAW:01234567`——而本地輸入 `01234567` 也是 `RAW:01234567`。兩者 hash
     * 相同，於是一個**無效**的國際號碼會撞上一個**有效**的本地號碼。
     *
     * ⛔ 這不只是「多接受了一個爛輸入」：它讓兩串語意不同的號碼變成同一個
     * 查詢鍵，也就是讓甲有機會查到乙的訂單。
     */
    public function test_an_invalid_international_prefix_does_not_collide_with_a_local_number(): void
    {
        $this->assertNull(
            ContactLookupHash::normalizePhone('+01234567'),
            '⛔ 明確國際前綴 ＋ 不合格形狀必須是 null，不得降級。',
        );

        $this->assertNotSame(
            ContactLookupHash::normalizePhone('01234567'),
            ContactLookupHash::normalizePhone('+01234567'),
        );

        // hash 層同樣不得相等（其中一邊為 null）。
        $this->assertNull(ContactLookupHash::forPhone('+01234567'));
        $this->assertNotNull(ContactLookupHash::forPhone('01234567'));
    }

    /**
     * ⛔ 帶明確國際前綴但無效者一律 null，⛔ 不得掉進 `RAW:`。
     *
     * @return array<string, array{0: string}>
     */
    public static function invalidExplicitInternationalProvider(): array
    {
        return [
            'plus zero' => ['+01234567'],
            'double zero then zero' => ['0001234567'],
            'plus too short' => ['+123456'],
            'plus too long' => ['+1234567890123456'],
            'double zero too short' => ['00123456'],
        ];
    }

    #[DataProvider('invalidExplicitInternationalProvider')]
    public function test_an_invalid_explicit_international_number_is_rejected(string $typed): void
    {
        $normalized = ContactLookupHash::normalizePhone($typed);

        $this->assertNull($normalized, '⛔ 無效的明確國際號碼必須是 null。');
    }

    /**
     * ⛔ 手機必須符合 checkout 的字元與長度邊界（6–20、僅 `0-9 + - ( ) 空白`）。
     *
     * ⭐ 邊界必須與 `CheckoutRequest` 一致：下單時存不進去的值，查詢時就不該
     * 被當成有效輸入。查詢比下單寬鬆等於開了一條下單擋得住、查詢擋不住的路。
     *
     * @return array<string, array{0: string}>
     */
    public static function outOfBoundsPhoneProvider(): array
    {
        return [
            'five characters' => ['09123'],
            'twenty one digits' => ['090012345678901234567'],
            'twenty one characters' => ['((((((0912345678)))))'],
            'asterisk' => ['09*2345678'],
            'letters' => ['0912ABC678'],
            'tab is not allowed' => ["0912\t345678"],
        ];
    }

    #[DataProvider('outOfBoundsPhoneProvider')]
    public function test_a_phone_outside_the_checkout_boundary_is_rejected(string $typed): void
    {
        $this->assertNull(ContactLookupHash::normalizePhone($typed));
    }

    /** ⛔ 6 與 20 是**包含**邊界，21 才拒絕。 */
    public function test_the_phone_length_boundary_is_inclusive(): void
    {
        $this->assertNotNull(ContactLookupHash::normalizePhone('091234'));       // 6
        $this->assertNotNull(ContactLookupHash::normalizePhone('(((((0912345678)))))')); // 20
        $this->assertNull(ContactLookupHash::normalizePhone('09123'));           // 5
        $this->assertNull(ContactLookupHash::normalizePhone('((((((0912345678)))))')); // 21
    }

    /**
     * ⛔ Email 必須符合 checkout 的 `email` 語意與 `max:80`。
     *
     * @return array<string, array{0: string}>
     */
    public static function invalidEmailProvider(): array
    {
        return [
            'no at sign' => ['not-an-email'],
            'no domain' => ['buyer@'],
            'no local part' => ['@example.test'],
            'space inside' => ['buy er@example.test'],
            'eighty one characters' => ['aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa@sub.example-domain.test'],
        ];
    }

    #[DataProvider('invalidEmailProvider')]
    public function test_an_invalid_email_is_rejected(string $typed): void
    {
        $this->assertNull(ContactLookupHash::normalizeEmail($typed));
        $this->assertNull(ContactLookupHash::forEmail($typed));
    }

    /** ⛔ 80 字元剛好可以，81 才拒絕。 */
    public function test_the_email_length_boundary_is_inclusive(): void
    {
        $domain = '@sub.example-domain.test'; // 24 字元

        $eighty = str_repeat('a', 80 - strlen($domain)).$domain;
        $eightyOne = str_repeat('a', 81 - strlen($domain)).$domain;

        $this->assertSame(80, strlen($eighty));
        $this->assertNotNull(ContactLookupHash::normalizeEmail($eighty));
        $this->assertNull(ContactLookupHash::normalizeEmail($eightyOne));
    }

    /**
     * ⛔ R1 的正面案例不得回歸。
     *
     * ⭐ 這一條存在的理由：R2 收緊了驗證，最容易犯的錯就是收過頭，把 Owner
     * 明確要求要等價的三種台灣寫法或合法國際號碼也一起擋掉。
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function stillValidPhoneProvider(): array
    {
        return [
            'tw local' => ['0912345678', 'TW:0912345678'],
            'tw plus 886' => ['+886912345678', 'TW:0912345678'],
            'tw zero zero 886' => ['00886912345678', 'TW:0912345678'],
            'tw with dashes' => ['0912-345-678', 'TW:0912345678'],
            'us plus' => ['+14155552671', 'INT:+14155552671'],
            'us zero zero' => ['0014155552671', 'INT:+14155552671'],
            'bare 886 stays raw' => ['886912345678', 'RAW:886912345678'],
            'local landline stays raw' => ['021234567', 'RAW:021234567'],
        ];
    }

    #[DataProvider('stillValidPhoneProvider')]
    public function test_valid_numbers_still_normalize_as_before(string $typed, string $expected): void
    {
        $this->assertSame($expected, ContactLookupHash::normalizePhone($typed));
    }

    /** ⛔ 收緊驗證後，端對端查詢仍必須查得到。 */
    public function test_the_lookup_still_finds_an_order_after_the_tightened_validation(): void
    {
        $order = $this->orderFor();

        foreach (['0912345678', '+886912345678', '00886912345678'] as $typed) {
            $this->lookup(['email' => self::EMAIL, 'phone' => $typed])
                ->assertOk()
                ->assertSee($order->reference, false);
        }
    }

    // ==================================== 2d. 只顯示付款成功的訂單

    /**
     * ⭐ Owner 要求：等待付款的訂單**完全不要**出現在查詢結果。
     *
     * ⛔ 即使三選二完全相符也一樣——回傳的必須是通用查無，
     * ⛔ 不是「這張單還沒付款」之類的另一種訊息。
     *
     * @return array<string, array{0: OrderStatus, 1: PaymentStatus}>
     */
    public static function unpaidOrderProvider(): array
    {
        return [
            'pending payment / initiated' => [OrderStatus::PendingPayment, PaymentStatus::Initiated],
            'pending payment / pending' => [OrderStatus::PendingPayment, PaymentStatus::Pending],
            'pending payment / failed' => [OrderStatus::PendingPayment, PaymentStatus::Failed],
            'canceled order' => [OrderStatus::Canceled, PaymentStatus::Canceled],
            'expired order' => [OrderStatus::Expired, PaymentStatus::Expired],
            'reconciliation required' => [OrderStatus::PendingPayment, PaymentStatus::ReconciliationRequired],
            // ⛔ 兩個欄位不一致時也必須排除：兩個條件都要成立才算付款成功。
            'paid but not succeeded' => [OrderStatus::Paid, PaymentStatus::Pending],
            'succeeded but not paid' => [OrderStatus::PendingPayment, PaymentStatus::Succeeded],
        ];
    }

    #[DataProvider('unpaidOrderProvider')]
    public function test_an_unpaid_order_is_never_returned(
        OrderStatus $orderStatus,
        PaymentStatus $paymentStatus,
    ): void {
        $order = $this->orderFor([
            'order_status' => $orderStatus,
            'payment_status' => $paymentStatus,
            'paid_at' => null,
        ]);

        $order->items()->first()->forceFill([
            'target_value' => 'secret_pending_target',
        ])->save();

        // 三項全符——仍然查不到。
        $response = $this->lookup([
            'reference' => $order->reference,
            'email' => self::EMAIL,
            'phone' => self::PHONE,
        ]);

        $response->assertOk();
        $response->assertSee('查不到符合的訂單');

        // ⛔ reference 與 target 都不得出現在頁面上。
        $response->assertDontSee($order->reference, false);
        $response->assertDontSee('secret_pending_target', false);
    }

    /**
     * ⛔⛔ 大量 pending 訂單不得吃掉 paid 訂單的 20 筆上限。
     *
     * ⭐ 這是「必須在 SQL 層過濾」而不是「查出來再由 Blade 隱藏」的**理由**。
     *
     * `limit(20)` 在 DB 端套用。若先撈 20 筆再隱藏未付款的，這個客人的 25 張
     * pending 單會把他唯一一張 paid 單擠出前 20 名——畫面上什麼都沒有，
     * 而他其實有單。那不是效能問題，是會吃掉結果的正確性錯誤。
     */
    public function test_many_pending_orders_do_not_crowd_out_a_paid_one(): void
    {
        // 先建 paid，再建 25 張更新的 pending（排序為最新在前）。
        $paid = $this->orderFor();

        for ($i = 0; $i < 25; $i++) {
            $this->orderFor([
                'order_status' => OrderStatus::PendingPayment,
                'payment_status' => PaymentStatus::Pending,
                'paid_at' => null,
            ]);
        }

        $response = $this->lookup(['email' => self::EMAIL, 'phone' => self::PHONE]);

        $response->assertOk();
        // ⛔ 那張唯一的 paid 訂單必須出現。
        $response->assertSee($paid->reference, false);
        $response->assertSee('共 1 筆訂單。');
    }

    /** 付款成功的訂單仍查得到，且顯示訂單時間與付款藥丸。 */
    public function test_a_paid_order_shows_its_time_and_payment_pill(): void
    {
        $order = $this->orderFor();

        $response = $this->lookup(['email' => self::EMAIL, 'phone' => self::PHONE]);

        $response->assertOk();
        $response->assertSee($order->reference, false);
        $response->assertSee('付款成功');
        $response->assertSee('訂單時間');
    }

    /**
     * ⭐ 訂單時間以 `Y-m-d H:i` 顯示，且與落盤值一致。
     *
     * ⛔ 誠實記錄一件我查證後修正的事：我原本寫這條測試時假設 `created_at`
     * 落盤是 UTC，預期 `01:30` 會被換算成 `09:30`。**那個假設是錯的**——
     * 本站 `app.timezone` 是 `Asia/Taipei` 且沒有以 UTC 落盤，取出來就已經是
     * 台北時間，presenter 裡的 `setTimezone()` 目前是 no-op。
     *
     * ⛔ 我沒有為了讓測試變綠而去改 production code 的行為，也沒有留下一條
     * 斷言錯誤時間的測試。這條現在驗證真正可觀測的性質：格式正確、值與
     * 落盤一致、⛔ 不出現秒數或時區後綴。
     *
     * `setTimezone()` 為何仍保留（以及它為什麼沒有對應測試），見
     * `PublicOrderPresenter::placedAt()` 的註解與結果文件。
     */
    public function test_the_order_time_is_formatted_for_display(): void
    {
        $order = $this->orderFor();

        DB::table('orders')->where('id', $order->id)->update([
            'created_at' => '2026-08-26 09:30:00',
        ]);

        $shaped = PublicOrderPresenter::for($order->fresh());

        $this->assertSame('2026-08-26 09:30', $shaped['placed_at']);

        $this->lookup(['email' => self::EMAIL, 'phone' => self::PHONE])
            ->assertOk()
            ->assertSee('訂單時間 2026-08-26 09:30')
            // ⛔ 不得洩漏秒數或內部時間格式。
            ->assertDontSee('09:30:00');
    }

    // ==================================== 2e. 交付目標的顯示與連結安全

    /**
     * ⛔⛔ `target_url` 會直接變成 `href`，所以它是本輪的注入邊界。
     *
     * ⛔ 只有 `http`／`https` 可以成為連結；其餘一律純文字。用 allowlist
     * 而不是把 `javascript:` 擋掉的 denylist——denylist 永遠少一個。
     *
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function targetLinkabilityProvider(): array
    {
        return [
            'https' => ['https://instagram.com/my_account', true],
            'http' => ['http://example.com/profile', true],
            'uppercase scheme' => ['HTTPS://Example.com/x', true],

            // ⛔ 以下全部不得成為連結。
            'plain account' => ['my_account', false],
            'at handle' => ['@my_handle', false],
            'javascript' => ['javascript:alert(1)', false],
            'javascript with comment' => ['javascript://comment%0aalert(1)', false],
            'mixed case javascript' => ['JavaScript:alert(1)', false],
            'data uri' => ['data:text/html,<script>alert(1)</script>', false],
            'vbscript' => ['vbscript:msgbox(1)', false],
            'file' => ['file:///etc/passwd', false],
            'scheme without host' => ['http:///nopath', false],
            'protocol relative' => ['//example.com', false],

            /*
             * ⛔⛔ 這一組是 allowlist 與 denylist 的**分界線**。
             *
             * 上面那些危險 scheme（`data:`／`vbscript:`／`file:`）都沒有 host，
             * 所以就算把 scheme 檢查換成「只擋 javascript:」的 denylist，
             * 它們仍會被 host 檢查攔下——⛔ 於是那個變異測不出來。
             *
             * 這些則是**帶合法 host 的非 http scheme**：denylist 會全部放行，
             * 只有 allowlist 擋得住。⛔ 這正是「denylist 永遠少一個」的具體
             * 樣子——沒有人會想到要把 `intent:`／`chrome:` 也列進去。
             */
            'ftp with host' => ['ftp://evil.example.com/x', false],
            'websocket with host' => ['ws://evil.example.com/x', false],
            'chrome scheme' => ['chrome://settings', false],
            'android intent' => ['intent://scan/#Intent;end', false],

            /*
             * ⛔ 結構不合法的 URL：scheme 與 host 看起來都對，但字串裡有
             * 空白、引號或角括號。這些只有 `FILTER_VALIDATE_URL` 擋得住——
             * 只檢查 scheme 與 host 的話會全部放行。
             *
             * ⛔ 這些字元正是在 `href` 裡最需要在意的：即使 Blade 會 escape，
             * 一個結構破碎的值也不該被當成「可以點的連結」交給客人。
             */
            'space in host' => ['https://exa mple.com/x', false],
            'quote in host' => ['http://exam"ple.com/', false],
            'angle bracket in host' => ['https://ex<script>.com', false],
            'space in path' => ['https://example.com/a b', false],
        ];
    }

    #[DataProvider('targetLinkabilityProvider')]
    public function test_only_real_http_targets_become_links(string $target, bool $linkable): void
    {
        $order = $this->orderFor();
        $order->items()->first()->forceFill(['target_value' => $target])->save();

        $shaped = PublicOrderPresenter::for($order->fresh());

        $this->assertSame($target, $shaped['items'][0]['target']);

        if ($linkable) {
            $this->assertSame($target, $shaped['items'][0]['target_url']);
        } else {
            $this->assertNull(
                $shaped['items'][0]['target_url'],
                '⛔ 非 http(s) 的值絕不可成為 href。',
            );
        }
    }

    /** 合法 HTTPS target 在頁面上是安全外連。 */
    public function test_a_valid_https_target_is_rendered_as_a_safe_link(): void
    {
        $order = $this->orderFor();
        $order->items()->first()->forceFill([
            'target_value' => 'https://instagram.com/my_account',
        ])->save();

        $html = (string) $this->lookup([
            'email' => self::EMAIL,
            'phone' => self::PHONE,
        ])->assertOk()->getContent();

        $this->assertStringContainsString('href="https://instagram.com/my_account"', $html);
        // ⛔ noopener：新分頁不得透過 window.opener 操作本站。
        $this->assertStringContainsString('rel="noopener noreferrer"', $html);
        $this->assertStringContainsString('target="_blank"', $html);
        // ⛔ 長網址必須可斷行。
        $this->assertStringContainsString('break-all', $html);
    }

    /** ⛔ 非 URL 帳號只顯示文字，不得產生 `<a>`。 */
    public function test_a_plain_account_target_is_text_only(): void
    {
        $order = $this->orderFor();
        $order->items()->first()->forceFill(['target_value' => 'my_plain_account'])->save();

        $html = (string) $this->lookup([
            'email' => self::EMAIL,
            'phone' => self::PHONE,
        ])->assertOk()->getContent();

        $this->assertStringContainsString('my_plain_account', $html);
        $this->assertStringNotContainsString('href="my_plain_account"', $html);
    }

    /**
     * ⛔ 惡意 target 必須被 Blade escape，不得注入 HTML／script。
     *
     * @return array<string, array{0: string}>
     */
    public static function hostileTargetProvider(): array
    {
        return [
            'script tag' => ['<script>alert(1)</script>'],
            'img onerror' => ['<img src=x onerror=alert(1)>'],
            'attribute break out' => ['" onmouseover="alert(1)'],
            'javascript href' => ['javascript:alert(document.cookie)'],
            'html in url path' => ['https://example.com/<script>alert(1)</script>'],
        ];
    }

    #[DataProvider('hostileTargetProvider')]
    public function test_a_hostile_target_is_escaped(string $hostile): void
    {
        $order = $this->orderFor();
        $order->items()->first()->forceFill(['target_value' => $hostile])->save();

        $html = (string) $this->lookup([
            'email' => self::EMAIL,
            'phone' => self::PHONE,
        ])->assertOk()->getContent();

        // ⛔ 原始 script／事件處理器不得以可執行形式出現。
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('<img src=x onerror=alert(1)>', $html);
        $this->assertStringNotContainsString('onmouseover="alert(1)"', $html);
        $this->assertStringNotContainsString('href="javascript:', $html);
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

    /**
     * ⭐ Owner 本輪改變規則：未付款訂單**完全不出現**在查詢結果。
     *
     * ⛔ 這條先前斷言未付款訂單會顯示「等待付款」——那與新規則直接矛盾，
     * 已改為斷言它根本查不到。這是產品決定的改變，不是回歸。
     *
     * ⛔ `PublicOrderPresenter::status()` 裡的未付款分支仍然保留：presenter
     * 是通用的 allowlist，不該假設呼叫端一定只餵付款成功的訂單。防線留著，
     * 只是這條路徑現在走不到。
     */
    public function test_an_unpaid_order_does_not_appear_at_all(): void
    {
        $order = $this->orderFor([
            'order_status' => OrderStatus::PendingPayment,
            'payment_status' => PaymentStatus::Pending,
            'paid_at' => null,
        ]);

        $response = $this->lookup(['reference' => $order->reference, 'email' => self::EMAIL]);

        $response->assertOk();
        $response->assertSee('查不到符合的訂單');
        $response->assertDontSee($order->reference, false);
        // ⛔ 連狀態文字都不該出現——這張單完全不在結果裡。
        $response->assertDontSee('等待付款');
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
            // 客戶個資。
            self::EMAIL, self::PHONE,
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, (string) $html, "公開頁外洩：{$forbidden}");
        }

        /*
         * ⭐ 交付目標已由 Owner 明確批准顯示，因此**不再**在禁止清單中。
         *
         * ⛔ 這一項先前是禁止的，現在改為必須出現——是 Owner 的產品決定改變。
         * ⭐ 它與上面那些的差別很實在：provider 資料與 Email／手機是**我們或
         * 第三方**的資訊，而交付目標是**客人自己填的、要給他自己看的**。
         * 顯示它不會洩漏我們用哪一家供應商，也不會洩漏別人的個資。
         *
         * ⛔ 其餘禁止項目一項都沒有放寬。
         */
        $this->assertStringContainsString('secret_customer_account', (string) $html);
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

    /**
     * ⭐ 獨立工具頁：`GET /order-check` 顯示空表單。
     *
     * ⛔ 這不是把查詢條件改成 GET——查詢仍然只走 POST（見下一條）。
     * GET 只回一張空表單，⛔ 不接受也不處理任何 query string 條件。
     */
    public function test_the_order_check_page_shows_an_empty_form_on_get(): void
    {
        $order = $this->orderFor();

        $response = $this->get('/order-check');

        $response->assertOk();
        $response->assertSee('訂單查詢');
        $response->assertSee('輸入訂單編號、Email、手機號碼其中兩項，即可查看目前處理進度。');
        // 表單存在於初始 HTML。
        $response->assertSee('name="reference"', false);
        $response->assertSee('name="email"', false);
        $response->assertSee('name="phone"', false);

        // ⛔ 還沒查就不該出現「查無」或任何結果。
        $response->assertDontSee('查不到符合的訂單');
        $response->assertDontSee($order->reference, false);
    }

    /**
     * ⛔ GET 帶 query string 不得變成一次查詢。
     *
     * ⛔ 若 GET 也會查，Email 與手機就會進 URL、瀏覽器歷史與 referrer——
     * 那正是這一頁刻意用 POST 的原因。
     */
    public function test_get_with_query_string_never_performs_a_lookup(): void
    {
        $order = $this->orderFor();

        $response = $this->get('/order-check?reference='.$order->reference.'&email='.self::EMAIL);

        $response->assertOk();
        $response->assertDontSee($order->reference, false);
        $response->assertDontSee('查詢結果');
    }

    /** ⛔ 舊的 `/order-lookup` 直接 404，⛔ 不做 301／302。 */
    public function test_the_old_lookup_path_is_gone(): void
    {
        $this->get('/order-lookup')->assertNotFound();
        $this->post('/order-lookup', ['reference' => 'IGL-ABCDEFGHIJKL'])->assertNotFound();
    }

    /** ⛔ route list 不得再有舊的公開 path。 */
    public function test_no_route_serves_the_old_public_path(): void
    {
        $uris = collect(app('router')->getRoutes())->map(fn ($r) => $r->uri())->all();

        $this->assertNotContains('order-lookup', $uris);
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
            ->first(fn ($r) => $r->uri() === 'order-check'
                && in_array('POST', $r->methods(), true));

        $this->assertNotNull($route, '找不到 order-check 的 POST route');

        // ⛔ 查詢本身仍然 POST only。
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

    /**
     * ⛔ 手機 domain 必須明確標記為 v2，且與 Email domain 不同。
     *
     * ⛔ 這條是**釘住常數字面值**，看起來像在重複實作——它不是。
     *
     * 手機語意升版後，正式站上還留著用 v1 語意算出來的 hash。domain 字串本身
     * 在本機不可觀測（本機沒有 v1 資料可比對），所以任何行為測試都殺不掉
     * 「把 v2 改回 v1」這個變異——我實測過，它是 equivalent mutant。
     *
     * 但在**正式站**上它一點都不等價：沿用 v1 domain 會讓新算出來的 hash 與
     * 殘留的 v1 值在同一個 keyspace 裡混在一起，兩種語意的值再也分不開，
     * backfill 也無法判斷哪些還沒升級。⛔ 因此這裡直接釘住字面值。
     */
    public function test_the_phone_domain_is_explicitly_versioned(): void
    {
        $reflection = new \ReflectionClass(ContactLookupHash::class);

        $this->assertSame(
            'iglikefollow.order-lookup.phone.v2',
            $reflection->getConstant('PHONE_DOMAIN'),
            '⛔ 改動手機正規化語意時必須同時進版，否則新舊 hash 會混在同一個 keyspace。',
        );

        $this->assertNotSame(
            $reflection->getConstant('EMAIL_DOMAIN'),
            $reflection->getConstant('PHONE_DOMAIN'),
            '⛔ 兩種聯絡方式不得共用 domain。',
        );
    }

    /**
     * ⭐ R1：v1 舊 hash 必須能由受控 apply 升級為 v2。
     *
     * ⛔ 初版的 backfill 只處理 null，於是曾跑過 A2 的訂單留著 v1 hash——
     * 手機語意升版後，那些客人永遠查不到自己的訂單。
     */
    public function test_the_backfill_upgrades_a_stale_v1_phone_hash(): void
    {
        /*
         * ⛔ 這裡刻意用**國際寫法**的號碼。
         *
         * v1 的語意是「原樣數字序列」，v2 是 canonical form。對 `0912345678`
         * 這種本地寫法，兩版算出來剛好相同——⛔ 拿它當 fixture 測不到升版，
         * 因為根本沒有東西改變。真正會分歧的是 `+886912345678`：
         * v1 → `886912345678`，v2 → `TW:0912345678`。
         */
        $international = '+886912345678';

        $order = $this->orderFor(['customer_phone' => $international]);

        // v1 舊值：用舊語意（去格式字元後的原樣數字序列）算出來的 hash。
        $legacy = hash_hmac(
            'sha256',
            'iglikefollow.order-lookup.phone.v1|886912345678',
            (string) config('app.key'),
        );
        $order->forceFill(['customer_phone_lookup_hash' => $legacy])->save();

        $this->assertNotSame(
            ContactLookupHash::forPhone($international),
            $legacy,
            '⛔ fixture 必須是真正過期的 v1 值，否則這個測試什麼都沒測到。',
        );

        // 舊值與 v2 desired 不同，因此查不到——這正是升版造成的斷點。
        $this->lookup(['email' => self::EMAIL, 'phone' => $international])
            ->assertOk()->assertDontSee($order->reference, false);

        $this->artisan('orders:backfill-lookup-hashes --apply')->assertSuccessful();

        $this->assertSame(
            ContactLookupHash::forPhone($international),
            $order->fresh()->customer_phone_lookup_hash,
        );

        // 升級後查得到，且三種寫法都命中同一筆。
        foreach (['+886912345678', '0912345678', '00886912345678'] as $typed) {
            $this->lookup(['email' => self::EMAIL, 'phone' => $typed])
                ->assertOk()->assertSee($order->reference, false);
        }
    }

    /**
     * ⛔ 沒有手機的訂單不得每次都被列為待更新。
     *
     * ⛔ 初版以「phone hash 為 null」判斷待補，於是這種訂單的計數永遠不會
     * 歸零，看起來像有事沒做完。
     */
    public function test_an_order_without_a_phone_never_stays_pending(): void
    {
        $this->orderFor([
            'customer_phone' => null,
            'customer_phone_lookup_hash' => null,
        ]);

        $this->artisan('orders:backfill-lookup-hashes --apply')->assertSuccessful();

        // 第二次：0 待更新、0 變更。
        $this->artisan('orders:backfill-lookup-hashes')
            ->expectsOutputToContain('待檢查：0')
            ->assertSuccessful();
    }

    /** ⛔ 第二次 apply 必須 0 change。 */
    public function test_a_second_apply_changes_nothing(): void
    {
        $this->orderFor([
            'customer_email_lookup_hash' => null,
            'customer_phone_lookup_hash' => null,
        ]);

        $this->artisan('orders:backfill-lookup-hashes --apply')->assertSuccessful();

        $this->artisan('orders:backfill-lookup-hashes --apply')
            ->expectsOutputToContain('已更新：0')
            ->expectsOutputToContain('剩餘待更新：0')
            ->assertSuccessful();
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

    // ==================================== 8b. R1：presenter allowlist 與 enum

    /**
     * ⭐ presenter 的 key 完全等於批准清單，⛔ 不多不少。
     *
     * ⭐ 本輪 Owner 明確批准新增 `placed_at`、`payment_label`、`target`
     * 與 `target_url`。前一輪曾把 `placed_at` 移除，理由是它不在批准清單內
     * ——那個理由當時成立；現在 Owner 要求顯示訂單時間，所以它回來了。
     * ⭐ 兩次相反的決定用的是**同一條規則**：判準是「Owner 批准了什麼」，
     * 不是「這個欄位看起來危不危險」。
     *
     * ⛔ 清單仍然是封閉的：任何未列出的欄位一旦出現，這條就會失敗。
     */
    public function test_the_presenter_exposes_exactly_the_approved_keys(): void
    {
        $order = $this->orderFor();
        FulfillmentOrder::factory()->submitted('99010')->create([
            'order_item_id' => $order->items()->first()->id,
        ]);

        $shaped = PublicOrderPresenter::for($order->fresh());

        $this->assertSame(
            ['reference', 'placed_at', 'payment_label', 'items'],
            array_keys($shaped),
        );

        $this->assertSame(
            ['platform', 'service', 'variant', 'quantity', 'status', 'remains', 'target', 'target_url'],
            array_keys($shaped['items'][0]),
        );

        // ⛔ 仍然嚴禁的欄位——新增四個 key 不代表放寬其他邊界。
        foreach (['email', 'phone', 'customer_email', 'customer_phone', 'provider', 'provider_order_id',
            'provider_status_code', 'provider_service_name_snapshot', 'payment_status', 'order_status',
        ] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $shaped);
            $this->assertArrayNotHasKey($forbidden, $shaped['items'][0]);
        }
    }

    /**
     * ⛔ 即使 presenter 新增了欄位，PII 與 provider 資料仍不得出現在 HTML。
     *
     * ⛔ 這條與上一條不同：上一條檢查 presenter 的回傳結構，這條檢查**實際
     * 輸出的頁面**。兩者都要——presenter 正確但 Blade 另外撈 model 的話，
     * 只驗 presenter 是看不出來的。
     */
    public function test_the_page_still_leaks_no_pii_or_provider_data(): void
    {
        $order = $this->orderFor();
        $item = $order->items()->first();
        $item->forceFill(['target_value' => 'https://instagram.com/my_account'])->save();

        FulfillmentOrder::factory()->submitted('SMM-SECRET-9911')->create([
            'order_item_id' => $item->id,
            'provider_service_name_snapshot' => 'PROVIDER SERVICE NAME',
            'provider_status_code' => 'In progress',
        ]);

        $html = (string) $this->lookup([
            'email' => self::EMAIL,
            'phone' => self::PHONE,
        ])->assertOk()->getContent();

        foreach ([self::EMAIL, self::PHONE, 'SMM-SECRET-9911', 'PROVIDER SERVICE NAME',
            'In progress', 'TheMostPanel', 'SMM',
        ] as $secret) {
            $this->assertStringNotContainsString($secret, $html, "⛔ 公開頁不得出現 {$secret}。");
        }
    }

    /**
     * ⛔ 送出按鈕必須有實際底色，⛔ 不得是看不見的白字。
     *
     * 本站主題沒有定義 `--color-black`，而 Tailwind 的 `.bg-black` 編出來是
     * `background-color: var(--color-black)`——變數不存在時背景等於沒有，
     * 只剩 `text-white` 的白字落在淺色底上，整顆按鈕在畫面上消失。
     *
     * ⛔ 實心按鈕一律用 `bg-ink`（站上其他 CTA 都是）。
     * ⛔ `bg-black/5` 這類帶透明度的寫法不受影響，因此只檢查實心底色。
     */
    public function test_the_submit_button_has_a_visible_background(): void
    {
        $html = (string) $this->get('/order-check')->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/<button type="submit"[^>]*class="[^"]*\bbg-ink\b[^"]*"/u',
            $html,
            '⛔ 送出按鈕必須用 bg-ink。',
        );

        $this->assertDoesNotMatchRegularExpression(
            '/<button type="submit"[^>]*class="[^"]*\bbg-black\b[^"]*"/u',
            $html,
            '⛔ bg-black 在本站主題下沒有底色，白字會整個看不見。',
        );
    }

    /**
     * ⭐ Owner 指定的處理說明句，顯示在**每張卡片內的最底**。
     *
     * ⛔ 只在有結果時出現。查無時說「訂單已自動安排處理」是矛盾的——
     * 客人什麼都沒查到，卻被告知系統已在處理某張他看不到的訂單。
     */
    public function test_the_processing_note_appears_only_with_results(): void
    {
        $order = $this->orderFor();

        $this->lookup(['email' => self::EMAIL, 'phone' => self::PHONE])
            ->assertOk()
            ->assertSee($order->reference, false)
            ->assertSee('訂單已自動安排處理，實際完成時間依系統狀況為準；若暫時沒有進度，還請耐心等候。');

        // ⛔ 查無：兩句說明都不得出現。
        $this->lookup(['email' => 'nobody@example.test', 'phone' => '0900000000'])
            ->assertOk()
            ->assertSee('查不到符合的訂單')
            ->assertDontSee('訂單已自動安排處理')
            ->assertDontSee('需要人工確認');

        // ⛔ 尚未查詢的空表單頁也不得出現。
        $this->get('/order-check')
            ->assertOk()
            ->assertDontSee('訂單已自動安排處理')
            ->assertDontSee('需要人工確認');
    }

    /**
     * ⛔⛔ 兩句說明**互斥**，絕不同時出現在同一張卡片。
     *
     * 「請聯絡客服」代表這張單卡住了、需要人介入；而「已自動安排處理、
     * 請耐心等候」是在叫客人不要來找我們。兩句一起出現等於同時說
     * 「來找我們」和「別來找我們」——客人只會更困惑。
     *
     * @return array<string, array{0: FulfillmentStatus, 1: bool}>
     */
    public static function noteByStatusProvider(): array
    {
        return [
            // 正常進行中：顯示「耐心等候」。
            'ready' => [FulfillmentStatus::Ready, false],
            'submitted' => [FulfillmentStatus::Submitted, false],
            'processing' => [FulfillmentStatus::Processing, false],
            'completed' => [FulfillmentStatus::Completed, false],

            // ⛔ 需要人介入的五種：顯示客服說明，⛔ 不得叫他耐心等候。
            'partial' => [FulfillmentStatus::Partial, true],
            'canceled' => [FulfillmentStatus::Canceled, true],
            'failed' => [FulfillmentStatus::Failed, true],
            'submission unknown' => [FulfillmentStatus::SubmissionUnknown, true],
            'configuration pending' => [FulfillmentStatus::ConfigurationPending, true],
        ];
    }

    #[DataProvider('noteByStatusProvider')]
    public function test_the_two_notes_are_mutually_exclusive(
        FulfillmentStatus $status,
        bool $needsSupport,
    ): void {
        $order = $this->orderFor();

        $submittedOrLater = in_array($status, [
            FulfillmentStatus::Submitted,
            FulfillmentStatus::Processing,
            FulfillmentStatus::Completed,
            FulfillmentStatus::Partial,
        ], true);

        FulfillmentOrder::factory()->create([
            'order_item_id' => $order->items()->first()->id,
            'status' => $status,
            'provider_order_id' => $submittedOrLater ? '99120' : null,
            'submitted_at' => $submittedOrLater ? now() : null,
        ]);

        $response = $this->lookup(['email' => self::EMAIL, 'phone' => self::PHONE])->assertOk();

        if ($needsSupport) {
            $response->assertSee('此訂單需要人工確認，請與我們聯繫。');
            $response->assertDontSee('訂單已自動安排處理');

            /*
             * ⛔ 卡住的單，剩餘顯示 `-` 而不是「更新中」。
             *
             * 「更新中」是在承諾這個數字待會就會有；但這五種狀態代表排程不會
             * 再帶回新的剩餘數量。⛔ 對一個永遠不會更新的欄位說「更新中」，
             * 是在請客人等一個不會來的東西。
             */
            $shaped = PublicOrderPresenter::for($order->fresh());
            $this->assertSame('-', $shaped['items'][0]['remains']);
            $response->assertDontSee('更新中');
        } else {
            $response->assertSee('訂單已自動安排處理');
            $response->assertDontSee('需要人工確認');
        }
    }

    /**
     * ⛔ 一張訂單有多個商品時，**只要有一個**卡住就顯示客服說明。
     *
     * ⛔ 若只看第一個商品，一張「第一項正常、第二項卡住」的訂單會被整張
     * 標成「請耐心等候」——那位客人會一直等一個永遠不會好的項目。
     */
    public function test_one_stuck_item_makes_the_whole_card_say_contact_support(): void
    {
        $order = $this->orderFor();

        // 第二個商品項目。
        $second = $order->items()->create([
            'platform_name' => 'Instagram',
            'service_name' => 'Instagram 觀看',
            'variant_label' => '一般觀看',
            'sku' => 'ig-views-standard',
            'unit_price_mills' => 3900,
            'quantity' => 500,
            'quantity_unit' => '個',
            'amount' => 195,
            'target_kind' => 'account',
            'target_value' => 'second_target',
        ]);

        // 第一項正常進行中。
        FulfillmentOrder::factory()->create([
            'order_item_id' => $order->items()->first()->id,
            'status' => FulfillmentStatus::Processing,
            'provider_order_id' => '99121',
            'submitted_at' => now(),
        ]);

        // ⛔ 第二項卡住。
        FulfillmentOrder::factory()->create([
            'order_item_id' => $second->id,
            'status' => FulfillmentStatus::Failed,
        ]);

        $response = $this->lookup(['email' => self::EMAIL, 'phone' => self::PHONE])->assertOk();

        // 兩個狀態都看得到。
        $response->assertSee('進行中');
        $response->assertSee('請聯絡客服');

        // ⛔ 整張卡片的說明必須是客服，不得是「耐心等候」。
        $response->assertSee('此訂單需要人工確認，請與我們聯繫。');
        $response->assertDontSee('訂單已自動安排處理');
    }

    /** 結果頁使用 Owner 指定的「訂單時間」標籤，⛔ 不用舊的「下單時間」。 */
    public function test_the_result_page_labels_the_order_time_as_approved(): void
    {
        $order = $this->orderFor();

        $response = $this->lookup(['reference' => $order->reference, 'email' => self::EMAIL])
            ->assertOk();

        // ⭐ Owner 指定的標籤是「訂單時間」。
        $response->assertSee('訂單時間');
        // ⛔ 舊的「下單時間」字樣不得同時存在，避免同一件事有兩種說法。
        $response->assertDontSee('下單時間');
    }

    /**
     * ⭐ R1：每個 fulfillment enum 都有明確的公開映射。
     *
     * ⛔ `ConfigurationPending` 不得是「進行中」：它代表 mapping／開關／
     * payload 尚未就緒，**根本還沒開始履約**——客人再等也不會好。
     *
     * ⛔ presenter 逐一窮舉 enum，不用會把未來新狀態默認成進行中的 default。
     *
     * @return array<string, array{0: FulfillmentStatus, 1: string}>
     */
    public static function everyFulfillmentStatusProvider(): array
    {
        return [
            'configuration pending' => [FulfillmentStatus::ConfigurationPending, '請聯絡客服'],
            'ready' => [FulfillmentStatus::Ready, '進行中'],
            'submitting' => [FulfillmentStatus::Submitting, '進行中'],
            'submitted' => [FulfillmentStatus::Submitted, '進行中'],
            'processing' => [FulfillmentStatus::Processing, '進行中'],
            'completed' => [FulfillmentStatus::Completed, '已完成'],
            'partial' => [FulfillmentStatus::Partial, '請聯絡客服'],
            'canceled' => [FulfillmentStatus::Canceled, '請聯絡客服'],
            'failed' => [FulfillmentStatus::Failed, '請聯絡客服'],
            'submission unknown' => [FulfillmentStatus::SubmissionUnknown, '請聯絡客服'],
        ];
    }

    #[DataProvider('everyFulfillmentStatusProvider')]
    public function test_every_fulfillment_status_has_an_explicit_public_mapping(
        FulfillmentStatus $status,
        string $expected,
    ): void {
        $order = $this->orderFor();

        /*
         * ⛔ 直接**建立**在目標狀態，⛔ 不是先 submitted 再改過去。
         *
         * `FulfillmentOrderIntegrityObserver` 與 DB trigger 兩層都會（正確地）
         * 擋下 `submitted → ready` 這類回頭轉移——連 raw `DB::table()->update()`
         * 也擋，因為那道防線在資料庫裡。
         *
         * ⭐ 這個測試要驗證的是 **presenter 對每個 enum 的公開映射**，不是轉移
         * 合法性。硬繞過那道 trigger 只會把測試變成在測 observer，而且等於在
         * 測試裡示範怎麼繞過一個真正的完整性防線。
         */
        /*
         * ⛔ 已送出（含之後）的狀態依規則必須具備供應商單號——那條完整性規則
         * 也是對的，因此這裡照著給，而不是把它關掉。
         */
        $submittedOrLater = in_array($status, [
            FulfillmentStatus::Submitted,
            FulfillmentStatus::Processing,
            FulfillmentStatus::Completed,
            FulfillmentStatus::Partial,
        ], true);

        FulfillmentOrder::factory()->create([
            'order_item_id' => $order->items()->first()->id,
            'status' => $status,
            'provider_order_id' => $submittedOrLater ? '99011' : null,
            'submitted_at' => $submittedOrLater ? now() : null,
        ]);

        $shaped = PublicOrderPresenter::for($order->fresh());

        $this->assertSame($expected, $shaped['items'][0]['status']);
    }

    /** ⛔ enum 全覆蓋：任何新增的狀態都會讓上面的 provider 少一格。 */
    public function test_the_public_mapping_covers_every_enum_case(): void
    {
        $this->assertCount(
            count(FulfillmentStatus::cases()),
            self::everyFulfillmentStatusProvider(),
            '⛔ 新增 FulfillmentStatus 時必須同時決定它的公開顯示。',
        );
    }

    // ==================================== 8c. R1：390px 可讀性

    /**
     * ⛔ 結果頁在 390px 不得依賴橫向捲動閱讀。
     *
     * ⛔ 初版用 `min-w-[32rem]` 的表格——在 390px 一定要左右捲才看得完。
     * 這裡檢查那個強制寬度已經不存在，且改用可堆疊的結構。
     *
     * ⛔ 這是結構檢查，不是視覺驗收；實機 390px 仍標記 NOT VERIFIED。
     */
    public function test_the_result_page_does_not_force_horizontal_scrolling(): void
    {
        $order = $this->orderFor();

        $html = (string) $this->lookup([
            'reference' => $order->reference,
            'email' => self::EMAIL,
        ])->getContent();

        $this->assertStringNotContainsString('min-w-[32rem]', $html, '⛔ 不得強制最小寬度。');
        // 手機以堆疊清單呈現，桌面才展開為多欄。
        $this->assertStringContainsString('grid-cols-2', $html);
    }

    // ==================================== 9. 首頁移除查詢區塊，SEO 不變

    /**
     * ⭐ Owner 指定：首頁不再放訂單查詢表單。
     *
     * ⛔ 這條測試現在斷言的方向與前一輪**相反**——前一輪要求表單必須在首頁
     * 初始 HTML，本輪要求完整移除。這是 Owner 的產品決定改變，不是回歸。
     */
    public function test_the_home_page_no_longer_contains_the_lookup_form(): void
    {
        $response = $this->get('/');

        $response->assertOk();

        $html = (string) $response->getContent();

        // ⛔ 舊區塊、舊 action 與表單欄位都必須消失。
        $this->assertStringNotContainsString('id="order-lookup"', $html);
        $this->assertStringNotContainsString('order-lookup', $html);
        $this->assertStringNotContainsString('name="reference"', $html);
        $this->assertStringNotContainsString('查詢訂單', $html);

        // ⛔ 首頁仍只有一個 H1。
        $this->assertSame(1, substr_count($html, '<h1'), '⛔ 首頁必須維持單一 H1。');
    }

    /**
     * ⭐ 入口改由 header 提供，且必須是**真實連結**。
     *
     * ⛔ 不得是 JS-only navigation：這一頁雖然 noindex，客人仍可能收藏它，
     * 也可能在沒有 JS 的環境開啟。
     */
    public function test_the_header_links_to_the_dedicated_page(): void
    {
        $html = (string) $this->get('/')->assertOk()->getContent();

        // 真實 href 存在於初始 HTML。
        $this->assertStringContainsString('href="'.route('order-check').'"', $html);

        // 桌面完整文字與手機短文字都在（同一份 HTML，靠 CSS 切換）。
        $this->assertStringContainsString('訂單查詢', $html);
        $this->assertStringContainsString('查訂單', $html);

        // ⛔ 既有導覽入口不得被擠掉。
        $this->assertStringContainsString('常見問題', $html);
        $this->assertStringContainsString('選擇服務', $html);
    }

    /** ⛔ 手機 header 的四個元素都必須存在且不換行。 */
    public function test_the_mobile_header_keeps_every_destination(): void
    {
        $html = (string) $this->get('/')->assertOk()->getContent();

        foreach (['nav-faq', 'nav-order-check', 'nav-cta'] as $probe) {
            $this->assertStringContainsString('data-probe="'.$probe.'"', $html);
        }

        // ⛔ 不得換行：換行會把 header 撐高並破壞對齊。
        $this->assertStringContainsString('whitespace-nowrap', $html);
    }

    /**
     * ⛔ 手機 header 現在有四個元素，最窄斷點必須另外收縮。
     *
     * ⛔ 這是**結構**檢查：確認 <400px 的收縮 class 存在，且既有的 44px 觸控
     * 高度與不換行沒有被犧牲掉。⛔ 它不是視覺驗收——實機 320／390px 仍標記
     * NOT VERIFIED。
     */
    public function test_the_narrowest_breakpoint_tightens_the_header(): void
    {
        $layout = (string) file_get_contents(resource_path('views/layouts/app.blade.php'));

        // wordmark 在 <400px 再收一階。
        $this->assertStringContainsString('w-24 max-w-full min-[400px]:w-32 sm:w-52', $layout);

        // 外框 padding／間距同樣分階。
        $this->assertStringContainsString('min-[400px]:px-4', $layout);

        // ⛔ 觸控高度與不換行不得因為擠空間而被犧牲。
        $this->assertSame(3, substr_count($layout, 'min-h-11 items-center whitespace-nowrap'));

        // ⛔ 不得改成 JS-only。
        $this->assertStringNotContainsString('onclick', $layout);
    }

    /** 工具頁本身：單一 H1、指定文案、noindex。 */
    public function test_the_dedicated_page_has_the_specified_copy_and_is_noindex(): void
    {
        $response = $this->get('/order-check');

        $response->assertOk();

        $html = (string) $response->getContent();

        $this->assertSame(1, substr_count($html, '<h1'), '⛔ 必須維持單一 H1。');
        $this->assertMatchesRegularExpression('/<h1[^>]*>\s*訂單查詢\s*<\/h1>/u', $html);
        $this->assertStringContainsString('訂單查詢｜IGLIKEFOLLOW', $html);

        // GET 也必須 noindex（header ＋ meta 兩層）。
        $robots = (string) $response->headers->get('X-Robots-Tag');
        $this->assertStringContainsString('noindex', $robots);
        $this->assertStringContainsString('nofollow', $robots);
        $response->assertSee('noindex, nofollow', false);
    }

    /**
     * ⭐ R1：手機提示必須與實際的等價行為一致。
     *
     * ⛔ 舊句「請與下單時填寫的格式一致」與已接受的功能矛盾——
     * `09XXXXXXXX`／`+8869XXXXXXXX`／`008869XXXXXXXX` 早已被封閉正規化為
     * 同一個值，客人根本不需要打出跟下單時相同的格式。
     *
     * ⛔ 錯誤的提示比沒有提示更糟：它會讓查不到的人以為原因是格式不同，
     * 於是反覆換格式重試，而真正的原因完全沒被指出來。
     *
     * ⛔ 這條同時釘住「新句存在」與「舊句不存在」。只驗證新句的話，
     * 兩句並存也會通過——那等於讓提示自相矛盾。
     */
    public function test_the_phone_hint_matches_the_actual_equivalence_behaviour(): void
    {
        $response = $this->get('/order-check');

        $response->assertOk();
        $response->assertSee('請輸入完整手機號碼；台灣手機可使用 09、+886 或 00886 格式。');
        $response->assertDontSee('請與下單時填寫的格式一致。');

        // ⛔ POST 後的同一頁也必須是新句（表單永遠顯示）。
        $this->lookup(['email' => self::EMAIL])
            ->assertOk()
            ->assertSee('請輸入完整手機號碼；台灣手機可使用 09、+886 或 00886 格式。')
            ->assertDontSee('請與下單時填寫的格式一致。');
    }

    /**
     * ⛔ 提示裡列出的三種格式必須真的都能命中同一筆訂單。
     *
     * ⭐ 這條把「文案」與「行為」綁在一起：若日後有人改了 parser 卻沒改文案
     * （或反過來），這裡會失敗。⛔ 一個沒有行為背書的承諾就是謊言。
     */
    public function test_every_format_promised_by_the_hint_actually_works(): void
    {
        $order = $this->orderFor();

        foreach (['0912345678', '+886912345678', '00886912345678'] as $promised) {
            $this->lookup(['email' => self::EMAIL, 'phone' => $promised])
                ->assertOk()
                ->assertSee($order->reference, false);
        }
    }

    /**
     * ⛔ 這一頁的 noindex 不得只依賴全站的 `AddRobotsHeader`。
     *
     * ⛔ 為什麼需要這條看起來像重複實作的測試：測試環境 `ALLOW_INDEXING`
     * 是關的，於是**全站** middleware 已經替每一頁設好 `X-Robots-Tag`。
     * 那讓「這一頁自己的 noindex」在行為上完全觀測不到——我實際做過突變測試，
     * 把 route 的 `NeverIndex` 與 controller 的 header 兩層同時拿掉，測試
     * 仍然全綠。
     *
     * ⛔ 但正式站一旦開放索引（`ALLOW_INDEXING=true`），全站那層就會停止
     * 輸出 noindex；屆時只剩這一頁自己的兩層擋著。⛔ 這一頁是「輸入 Email
     * 與手機」的入口，絕不能因為站台開放索引就跟著被收錄。
     *
     * 因此直接釘住 route 上的 `NeverIndex`，⛔ 不依賴環境相依的行為觀測。
     */
    public function test_the_page_carries_its_own_noindex_independent_of_the_site_wide_default(): void
    {
        $routes = collect(app('router')->getRoutes())
            ->filter(fn ($r) => $r->uri() === 'order-check');

        $this->assertCount(2, $routes, '⛔ 應有 GET 與 POST 兩條。');

        foreach ($routes as $route) {
            $this->assertContains(
                NeverIndex::class,
                $route->gatherMiddleware(),
                '⛔ GET 與 POST 都必須自帶 NeverIndex，不得只靠全站預設。',
            );
        }
    }
}
