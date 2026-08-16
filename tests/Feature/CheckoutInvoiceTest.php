<?php

namespace Tests\Feature;

use App\Models\ServiceVariant;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The /checkout page: fulfilment, contact details, e-invoice and payment.
 *
 * Every conditional rule is asserted against the server. The Alpine show/hide
 * is a convenience for the customer and carries no security meaning: a crafted
 * POST reaches the controller with whatever fields it likes. Product data is
 * never accepted from this form — it comes from the checkout session.
 */
class CheckoutInvoiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
    }

    private function variantId(string $sku = 'ig-followers-standard'): int
    {
        return ServiceVariant::query()->where('sku', $sku)->value('id');
    }

    /** Select a product, as the service page does, before reaching /checkout. */
    private function startCheckout(?int $variantId = null, int $quantity = 1000): void
    {
        $this->post('/checkout/start', [
            'variant' => $variantId ?? $this->variantId(),
            'quantity' => $quantity,
        ])->assertRedirect(route('checkout'));
    }

    /**
     * The final submission carries no product fields at all.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'target' => 'example_account',
            'payment' => 'line-pay',
            'customer_email' => 'buyer@example.com',
            'invoice_kind' => 'personal',
            'personal_invoice_mode' => 'email',
        ], $overrides);
    }

    /** Start a selection and submit the checkout form in one step. */
    private function submit(array $overrides = [])
    {
        $this->startCheckout();

        return $this->post('/checkout/mock', $this->payload($overrides));
    }

    /** Submit an exact payload (used when a required key must be absent). */
    private function submitRaw(array $payload)
    {
        $this->startCheckout();

        return $this->post('/checkout/mock', $payload);
    }

    private function checkoutHtml(): string
    {
        $this->startCheckout();

        return $this->get('/checkout')->assertOk()->getContent();
    }

    private function serviceHtml(): string
    {
        return $this->get('/services/instagram/followers')->assertOk()->getContent();
    }

    // ------------------------------------------------------------ 初始 HTML

    public function test_the_service_page_only_selects_a_product(): void
    {
        $html = $this->serviceHtml();

        // 服務頁保留：服務項目、數量、試算與繼續 CTA。
        $this->assertStringContainsString('name="variant"', $html);
        $this->assertStringContainsString('name="quantity"', $html);
        $this->assertStringContainsString('試算總額', $html);
        $this->assertStringContainsString('繼續結帳', $html);

        // ⛔ 履約、聯絡、發票與付款欄位一律不得出現在服務頁。
        foreach ([
            'name="target"',
            'name="customer_email"',
            'name="customer_phone"',
            'name="invoice_kind"',
            'name="personal_invoice_mode"',
            'name="payment"',
        ] as $field) {
            $this->assertStringNotContainsString($field, $html, "服務頁不該有 {$field}");
        }
    }

    public function test_the_checkout_page_ships_every_step_in_the_initial_html(): void
    {
        $html = $this->checkoutHtml();

        foreach ([
            'name="target"',
            'name="customer_email"',
            'name="customer_phone"',
            'name="invoice_kind"',
            'name="personal_invoice_mode"',
            'name="payment"',
        ] as $field) {
            $this->assertStringContainsString($field, $html, "結帳頁初始 HTML 缺少 {$field}");
        }
    }

    public function test_the_checkout_page_shows_the_product_summary(): void
    {
        $html = $this->checkoutHtml();

        $this->assertStringContainsString('Instagram', $html);
        $this->assertStringContainsString('一般粉絲', $html);
        $this->assertStringContainsString('1,000', $html);
        // 1000 × 0.59 = 590
        $this->assertStringContainsString('NT$590', $html);
        $this->assertStringContainsString('返回修改', $html);
    }

    public function test_the_invoice_step_offers_personal_and_business(): void
    {
        $html = $this->checkoutHtml();

        $this->assertStringContainsString('個人電子發票', $html);
        $this->assertStringContainsString('公司統編發票', $html);
        $this->assertStringContainsString('手機條碼載具', $html);
        $this->assertStringContainsString('捐贈發票', $html);
    }

    public function test_the_page_states_that_no_paper_invoice_is_offered(): void
    {
        $html = $this->checkoutHtml();

        // D-019：不提供紙本發票與郵寄，介面必須講清楚。
        $this->assertStringContainsString('不提供紙本', $html);
        $this->assertStringNotContainsString('郵寄地址', $html);
        $this->assertStringNotContainsString('列印發票', $html);
    }

    public function test_the_mock_cta_does_not_claim_real_payment(): void
    {
        $html = $this->checkoutHtml();

        $this->assertStringContainsString('測試前往付款', $html);
        $this->assertStringContainsString('本機 MOCK', $html);
        $this->assertStringContainsString('不會扣款', $html);
        $this->assertStringContainsString('不會開立任何發票', $html);
    }

    public function test_the_service_page_still_has_exactly_one_h1(): void
    {
        $this->assertSame(1, substr_count($this->serviceHtml(), '<h1'));
    }

    public function test_the_checkout_page_has_exactly_one_h1(): void
    {
        $this->assertSame(1, substr_count($this->checkoutHtml(), '<h1'));
    }

    public function test_the_homepage_still_does_not_embed_the_checkout_form(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertDontSee('name="customer_email"', false)
            ->assertDontSee('name="invoice_kind"', false);
    }

    // ------------------------------------------------------------ 聯絡資料

    public function test_email_is_required(): void
    {
        $payload = $this->payload();
        unset($payload['customer_email']);

        $this->submitRaw($payload)->assertSessionHasErrors('customer_email');
    }

    public function test_a_malformed_email_is_rejected(): void
    {
        $this->submit(['customer_email' => 'not-an-email'])
            ->assertSessionHasErrors('customer_email');
    }

    public function test_phone_is_optional(): void
    {
        $this->submit()->assertOk();
    }

    public function test_a_valid_phone_is_accepted(): void
    {
        $this->submit(['customer_phone' => '0912-345-678'])->assertOk();
    }

    public function test_a_phone_containing_markup_is_rejected(): void
    {
        $this->submit(['customer_phone' => '<script>alert(1)</script>'])
            ->assertSessionHasErrors('customer_phone');
    }

    public function test_no_name_or_address_is_requested(): void
    {
        $html = $this->serviceHtml();

        // 個資最小化：⛔ 不收姓名、地址或會員密碼。
        foreach (['name="customer_name"', 'name="address"', 'name="password"'] as $field) {
            $this->assertStringNotContainsString($field, $html);
        }
    }

    // ------------------------------------------------------------ 個人電子發票

    public function test_personal_email_mode_needs_no_extra_invoice_field(): void
    {
        $this->submit()
            ->assertOk()
            ->assertSee('個人電子發票（寄送至 Email）');
    }

    public function test_mobile_barcode_requires_a_carrier_number(): void
    {
        $this->submit(['personal_invoice_mode' => 'mobile_barcode'])
            ->assertSessionHasErrors('carrier_number');
    }

    public function test_a_valid_mobile_barcode_is_accepted(): void
    {
        $this->submit([
            'personal_invoice_mode' => 'mobile_barcode',
            'carrier_number' => '/ABC1234',
        ])->assertOk()->assertSee('個人電子發票（手機條碼載具）');
    }

    public function test_a_mobile_barcode_without_the_leading_slash_is_rejected(): void
    {
        $this->submit([
            'personal_invoice_mode' => 'mobile_barcode',
            'carrier_number' => 'ABC1234',
        ])->assertSessionHasErrors('carrier_number');
    }

    public function test_a_lowercase_mobile_barcode_is_rejected(): void
    {
        $this->submit([
            'personal_invoice_mode' => 'mobile_barcode',
            'carrier_number' => '/abc1234',
        ])->assertSessionHasErrors('carrier_number');
    }

    public function test_donation_requires_a_love_code(): void
    {
        $this->submit(['personal_invoice_mode' => 'donation'])
            ->assertSessionHasErrors('love_code');
    }

    public function test_a_valid_love_code_is_accepted(): void
    {
        $this->submit([
            'personal_invoice_mode' => 'donation',
            'love_code' => '12345',
        ])->assertOk()->assertSee('個人電子發票（捐贈）');
    }

    public function test_a_love_code_outside_three_to_seven_digits_is_rejected(): void
    {
        foreach (['12', '12345678', 'ABCDE'] as $code) {
            $this->submit([
                'personal_invoice_mode' => 'donation',
                'love_code' => $code,
            ])->assertSessionHasErrors('love_code');

            $this->flushSession();
        }
    }

    public function test_an_unknown_personal_mode_is_rejected(): void
    {
        $this->submit(['personal_invoice_mode' => 'paper'])
            ->assertSessionHasErrors('personal_invoice_mode');
    }

    public function test_a_stale_carrier_number_from_another_mode_is_ignored(): void
    {
        // 使用者切回 Email 模式後，前一模式殘留的值不得被採用。
        $this->submit([
            'personal_invoice_mode' => 'email',
            'carrier_number' => 'NOT-A-VALID-CARRIER',
            'love_code' => 'nonsense',
        ])->assertOk()->assertSee('個人電子發票（寄送至 Email）');
    }

    // ------------------------------------------------------------ 公司統編發票

    public function test_business_requires_a_tax_id_and_name(): void
    {
        $this->submit([
            'invoice_kind' => 'business',
            'personal_invoice_mode' => null,
        ])->assertSessionHasErrors(['buyer_tax_id', 'buyer_name']);
    }

    public function test_a_valid_business_invoice_is_accepted(): void
    {
        $this->submit([
            'invoice_kind' => 'business',
            'personal_invoice_mode' => null,
            'buyer_tax_id' => '12345678',
            'buyer_name' => '測試股份有限公司',
        ])->assertOk()->assertSee('公司統編電子發票');
    }

    public function test_a_tax_id_that_is_not_eight_digits_is_rejected(): void
    {
        foreach (['1234567', '123456789', 'ABCDEFGH'] as $taxId) {
            $this->submit([
                'invoice_kind' => 'business',
                'personal_invoice_mode' => null,
                'buyer_tax_id' => $taxId,
                'buyer_name' => '測試公司',
            ])->assertSessionHasErrors('buyer_tax_id');

            $this->flushSession();
        }
    }

    public function test_business_combined_with_donation_is_rejected(): void
    {
        // 公司統編固定為 Email 電子載具，⛔ 與捐贈是矛盾組合，必須明確失敗。
        $this->submit([
            'invoice_kind' => 'business',
            'personal_invoice_mode' => 'donation',
            'love_code' => '12345',
            'buyer_tax_id' => '12345678',
            'buyer_name' => '測試公司',
        ])->assertSessionHasErrors('personal_invoice_mode');
    }

    public function test_business_combined_with_a_personal_carrier_is_rejected(): void
    {
        $this->submit([
            'invoice_kind' => 'business',
            'personal_invoice_mode' => 'mobile_barcode',
            'carrier_number' => '/ABC1234',
            'buyer_tax_id' => '12345678',
            'buyer_name' => '測試公司',
        ])->assertSessionHasErrors('personal_invoice_mode');
    }

    public function test_an_unknown_invoice_kind_is_rejected(): void
    {
        $this->submit(['invoice_kind' => 'paper'])
            ->assertSessionHasErrors('invoice_kind');
    }

    // ------------------------------------------------------------ 個資最小化

    public function test_the_success_page_masks_the_email(): void
    {
        $this->submit(['customer_email' => 'buyer@example.com'])
            ->assertOk()
            // ⛔ 不得完整回顯 Email。
            ->assertDontSee('buyer@example.com')
            ->assertSee('@example.com');
    }

    public function test_the_success_page_masks_the_phone(): void
    {
        $this->submit(['customer_phone' => '0912345678'])
            ->assertOk()
            ->assertDontSee('0912345678');
    }

    public function test_the_success_page_never_echoes_the_carrier_number(): void
    {
        $this->submit([
            'personal_invoice_mode' => 'mobile_barcode',
            'carrier_number' => '/ABC1234',
        ])->assertOk()->assertDontSee('/ABC1234');
    }

    public function test_the_success_page_never_echoes_the_full_tax_id(): void
    {
        $this->submit([
            'invoice_kind' => 'business',
            'personal_invoice_mode' => null,
            'buyer_tax_id' => '12345678',
            'buyer_name' => '測試公司',
        ])->assertOk()->assertDontSee('12345678');
    }

    public function test_the_mock_states_that_no_invoice_is_issued(): void
    {
        $this->submit()
            ->assertOk()
            ->assertSee('不會開立任何發票');
    }

    // ------------------------------------------------------------ 後端仍是唯一權威

    public function test_a_client_supplied_amount_is_still_ignored(): void
    {
        // 1000 × 0.59 = 590。
        $this->submit([
            'price' => 1,
            'amount' => 1,
            'mockAmount' => 1,
        ])->assertOk()->assertSee('NT$590');
    }

    public function test_a_draft_variant_cannot_start_a_checkout(): void
    {
        $variant = ServiceVariant::query()->where('sku', 'ig-followers-real')->first();
        $variant->update(['status' => 'draft']);

        $this->post('/checkout/start', ['variant' => $variant->id, 'quantity' => 1000])
            ->assertSessionHasErrors('variant');
    }

    public function test_a_variant_unpublished_after_start_cannot_be_submitted(): void
    {
        $this->startCheckout();

        // 客人還在填單時商品被下架：⛔ 不得用過期的 session 完成結帳。
        ServiceVariant::query()->where('sku', 'ig-followers-standard')->update(['status' => 'draft']);

        $this->get('/checkout')->assertRedirect();
        $this->post('/checkout/mock', $this->payload())->assertRedirect(route('checkout'));
    }

    public function test_the_mock_makes_no_outbound_request(): void
    {
        Http::preventStrayRequests();

        // ⛔ 本輪不呼叫綠界／LINE Pay／統編／載具任何 API。
        $this->submit([
            'invoice_kind' => 'business',
            'personal_invoice_mode' => null,
            'buyer_tax_id' => '12345678',
            'buyer_name' => '測試公司',
        ])->assertOk();
    }

    /**
     * 結帳「會」建立訂單（M3A 起），但個資不得流進稽核紀錄。
     *
     * ⛔ 舊名稱 test_the_mock_writes_nothing_to_the_database 已不成立：
     * 現在結帳確實會新增 orders／order_items／payment_attempts。這個測試
     * 真正保障的是「後台稽核紀錄不得收錄客人個資」。
     */
    public function test_checkout_never_writes_personal_data_into_the_audit_log(): void
    {
        // 基準是 seeder 建立目錄後的狀態（那些寫入是 audit observer 的正常行為）。
        $before = DB::table('admin_audit_logs')->count();

        $this->submit([
            'customer_email' => 'private@example.com',
            'customer_phone' => '0912345678',
            'personal_invoice_mode' => 'mobile_barcode',
            'carrier_number' => '/ABC1234',
        ])->assertOk();

        // 訂單相關的表不受 AuditObserver 監看，⛔ 稽核紀錄不應因結帳而增加。
        $this->assertSame($before, DB::table('admin_audit_logs')->count());

        foreach (DB::table('admin_audit_logs')->pluck('after') as $payload) {
            $this->assertStringNotContainsString('private@example.com', (string) $payload);
            $this->assertStringNotContainsString('0912345678', (string) $payload);
            $this->assertStringNotContainsString('ABC1234', (string) $payload);
        }
    }
}
