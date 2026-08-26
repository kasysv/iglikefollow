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
     * ⭐ R1：presenter 的 key 完全等於批准清單，⛔ 不多不少。
     *
     * ⛔ 移除了 `placed_at`：它看起來無害，但**不在 Owner 批准的欄位內**。
     * 公開輸出的判準是「批准了什麼」，不是「這個看起來還好吧」——後者正是
     * allowlist 會逐漸擴張、最後洩漏東西的方式。
     */
    public function test_the_presenter_exposes_exactly_the_approved_keys(): void
    {
        $order = $this->orderFor();
        FulfillmentOrder::factory()->submitted('99010')->create([
            'order_item_id' => $order->items()->first()->id,
        ]);

        $shaped = PublicOrderPresenter::for($order->fresh());

        $this->assertSame(['reference', 'items'], array_keys($shaped));
        $this->assertArrayNotHasKey('placed_at', $shaped);

        $this->assertSame(
            ['platform', 'service', 'variant', 'quantity', 'status', 'remains'],
            array_keys($shaped['items'][0]),
        );
    }

    /** 結果頁不得出現「下單時間」。 */
    public function test_the_result_page_no_longer_shows_the_order_time(): void
    {
        $order = $this->orderFor();

        $this->lookup(['reference' => $order->reference, 'email' => self::EMAIL])
            ->assertOk()
            ->assertDontSee('下單時間');
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
