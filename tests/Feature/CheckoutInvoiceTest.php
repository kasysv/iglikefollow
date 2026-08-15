<?php

namespace Tests\Feature;

use App\Models\ServiceVariant;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Single-page checkout: contact details and e-invoice (local mock only).
 *
 * Every conditional rule is asserted against the server. The Alpine show/hide
 * in the sidebar is a convenience for the customer and carries no security
 * meaning: a crafted POST reaches the controller with whatever fields it likes.
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

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'variant' => $this->variantId(),
            'quantity' => 1000,
            'target' => 'example_account',
            'payment' => 'line-pay',
            'customer_email' => 'buyer@example.com',
            'invoice_kind' => 'personal',
            'personal_invoice_mode' => 'email',
        ], $overrides);
    }

    private function serviceHtml(): string
    {
        return $this->get('/services/instagram/followers')->assertOk()->getContent();
    }

    // ------------------------------------------------------------ 初始 HTML

    public function test_the_sidebar_ships_every_step_in_the_initial_html(): void
    {
        $html = $this->serviceHtml();

        foreach ([
            'name="quantity"',
            'name="target"',
            'name="customer_email"',
            'name="customer_phone"',
            'name="invoice_kind"',
            'name="personal_invoice_mode"',
            'name="payment"',
        ] as $field) {
            $this->assertStringContainsString($field, $html, "初始 HTML 缺少 {$field}");
        }
    }

    public function test_the_invoice_step_offers_personal_and_business(): void
    {
        $html = $this->serviceHtml();

        $this->assertStringContainsString('個人電子發票', $html);
        $this->assertStringContainsString('公司統編發票', $html);
        $this->assertStringContainsString('手機條碼載具', $html);
        $this->assertStringContainsString('捐贈發票', $html);
    }

    public function test_the_page_states_that_no_paper_invoice_is_offered(): void
    {
        $html = $this->serviceHtml();

        // D-019：不提供紙本發票與郵寄，介面必須講清楚。
        $this->assertStringContainsString('不提供紙本', $html);
        $this->assertStringNotContainsString('郵寄地址', $html);
        $this->assertStringNotContainsString('列印發票', $html);
    }

    public function test_the_mock_cta_does_not_claim_real_payment(): void
    {
        $html = $this->serviceHtml();

        $this->assertStringContainsString('測試單頁結帳', $html);
        $this->assertStringContainsString('本機 MOCK', $html);
        $this->assertStringContainsString('不扣款、不建立真實訂單、不開立發票', $html);
        // 正式版才會出現的文案，⛔ 現在不得出現。
        $this->assertStringNotContainsString('前往付款', $html);
    }

    public function test_the_service_page_still_has_exactly_one_h1(): void
    {
        $this->assertSame(1, substr_count($this->serviceHtml(), '<h1'));
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

        $this->post('/checkout/mock', $payload)->assertSessionHasErrors('customer_email');
    }

    public function test_a_malformed_email_is_rejected(): void
    {
        $this->post('/checkout/mock', $this->payload(['customer_email' => 'not-an-email']))
            ->assertSessionHasErrors('customer_email');
    }

    public function test_phone_is_optional(): void
    {
        $this->post('/checkout/mock', $this->payload())->assertOk();
    }

    public function test_a_valid_phone_is_accepted(): void
    {
        $this->post('/checkout/mock', $this->payload(['customer_phone' => '0912-345-678']))->assertOk();
    }

    public function test_a_phone_containing_markup_is_rejected(): void
    {
        $this->post('/checkout/mock', $this->payload(['customer_phone' => '<script>alert(1)</script>']))
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
        $this->post('/checkout/mock', $this->payload())
            ->assertOk()
            ->assertSee('個人電子發票（寄送至 Email）');
    }

    public function test_mobile_barcode_requires_a_carrier_number(): void
    {
        $this->post('/checkout/mock', $this->payload(['personal_invoice_mode' => 'mobile_barcode']))
            ->assertSessionHasErrors('carrier_number');
    }

    public function test_a_valid_mobile_barcode_is_accepted(): void
    {
        $this->post('/checkout/mock', $this->payload([
            'personal_invoice_mode' => 'mobile_barcode',
            'carrier_number' => '/ABC1234',
        ]))->assertOk()->assertSee('個人電子發票（手機條碼載具）');
    }

    public function test_a_mobile_barcode_without_the_leading_slash_is_rejected(): void
    {
        $this->post('/checkout/mock', $this->payload([
            'personal_invoice_mode' => 'mobile_barcode',
            'carrier_number' => 'ABC1234',
        ]))->assertSessionHasErrors('carrier_number');
    }

    public function test_a_lowercase_mobile_barcode_is_rejected(): void
    {
        $this->post('/checkout/mock', $this->payload([
            'personal_invoice_mode' => 'mobile_barcode',
            'carrier_number' => '/abc1234',
        ]))->assertSessionHasErrors('carrier_number');
    }

    public function test_donation_requires_a_love_code(): void
    {
        $this->post('/checkout/mock', $this->payload(['personal_invoice_mode' => 'donation']))
            ->assertSessionHasErrors('love_code');
    }

    public function test_a_valid_love_code_is_accepted(): void
    {
        $this->post('/checkout/mock', $this->payload([
            'personal_invoice_mode' => 'donation',
            'love_code' => '12345',
        ]))->assertOk()->assertSee('個人電子發票（捐贈）');
    }

    public function test_a_love_code_outside_three_to_seven_digits_is_rejected(): void
    {
        foreach (['12', '12345678', 'ABCDE'] as $code) {
            $this->post('/checkout/mock', $this->payload([
                'personal_invoice_mode' => 'donation',
                'love_code' => $code,
            ]))->assertSessionHasErrors('love_code');

            $this->flushSession();
        }
    }

    public function test_an_unknown_personal_mode_is_rejected(): void
    {
        $this->post('/checkout/mock', $this->payload(['personal_invoice_mode' => 'paper']))
            ->assertSessionHasErrors('personal_invoice_mode');
    }

    public function test_a_stale_carrier_number_from_another_mode_is_ignored(): void
    {
        // 使用者切回 Email 模式後，前一模式殘留的值不得被採用。
        $this->post('/checkout/mock', $this->payload([
            'personal_invoice_mode' => 'email',
            'carrier_number' => 'NOT-A-VALID-CARRIER',
            'love_code' => 'nonsense',
        ]))->assertOk()->assertSee('個人電子發票（寄送至 Email）');
    }

    // ------------------------------------------------------------ 公司統編發票

    public function test_business_requires_a_tax_id_and_name(): void
    {
        $this->post('/checkout/mock', $this->payload([
            'invoice_kind' => 'business',
            'personal_invoice_mode' => null,
        ]))->assertSessionHasErrors(['buyer_tax_id', 'buyer_name']);
    }

    public function test_a_valid_business_invoice_is_accepted(): void
    {
        $this->post('/checkout/mock', $this->payload([
            'invoice_kind' => 'business',
            'personal_invoice_mode' => null,
            'buyer_tax_id' => '12345678',
            'buyer_name' => '測試股份有限公司',
        ]))->assertOk()->assertSee('公司統編電子發票');
    }

    public function test_a_tax_id_that_is_not_eight_digits_is_rejected(): void
    {
        foreach (['1234567', '123456789', 'ABCDEFGH'] as $taxId) {
            $this->post('/checkout/mock', $this->payload([
                'invoice_kind' => 'business',
                'personal_invoice_mode' => null,
                'buyer_tax_id' => $taxId,
                'buyer_name' => '測試公司',
            ]))->assertSessionHasErrors('buyer_tax_id');

            $this->flushSession();
        }
    }

    public function test_business_combined_with_donation_is_rejected(): void
    {
        // 公司統編固定為 Email 電子載具，⛔ 與捐贈是矛盾組合，必須明確失敗。
        $this->post('/checkout/mock', $this->payload([
            'invoice_kind' => 'business',
            'personal_invoice_mode' => 'donation',
            'love_code' => '12345',
            'buyer_tax_id' => '12345678',
            'buyer_name' => '測試公司',
        ]))->assertSessionHasErrors('personal_invoice_mode');
    }

    public function test_business_combined_with_a_personal_carrier_is_rejected(): void
    {
        $this->post('/checkout/mock', $this->payload([
            'invoice_kind' => 'business',
            'personal_invoice_mode' => 'mobile_barcode',
            'carrier_number' => '/ABC1234',
            'buyer_tax_id' => '12345678',
            'buyer_name' => '測試公司',
        ]))->assertSessionHasErrors('personal_invoice_mode');
    }

    public function test_an_unknown_invoice_kind_is_rejected(): void
    {
        $this->post('/checkout/mock', $this->payload(['invoice_kind' => 'paper']))
            ->assertSessionHasErrors('invoice_kind');
    }

    // ------------------------------------------------------------ 個資最小化

    public function test_the_success_page_masks_the_email(): void
    {
        $this->post('/checkout/mock', $this->payload(['customer_email' => 'buyer@example.com']))
            ->assertOk()
            // ⛔ 不得完整回顯 Email。
            ->assertDontSee('buyer@example.com')
            ->assertSee('@example.com');
    }

    public function test_the_success_page_masks_the_phone(): void
    {
        $this->post('/checkout/mock', $this->payload(['customer_phone' => '0912345678']))
            ->assertOk()
            ->assertDontSee('0912345678');
    }

    public function test_the_success_page_never_echoes_the_carrier_number(): void
    {
        $this->post('/checkout/mock', $this->payload([
            'personal_invoice_mode' => 'mobile_barcode',
            'carrier_number' => '/ABC1234',
        ]))->assertOk()->assertDontSee('/ABC1234');
    }

    public function test_the_success_page_never_echoes_the_full_tax_id(): void
    {
        $this->post('/checkout/mock', $this->payload([
            'invoice_kind' => 'business',
            'personal_invoice_mode' => null,
            'buyer_tax_id' => '12345678',
            'buyer_name' => '測試公司',
        ]))->assertOk()->assertDontSee('12345678');
    }

    public function test_the_mock_states_that_no_invoice_is_issued(): void
    {
        $this->post('/checkout/mock', $this->payload())
            ->assertOk()
            ->assertSee('不會開立任何發票');
    }

    // ------------------------------------------------------------ 後端仍是唯一權威

    public function test_a_client_supplied_amount_is_still_ignored(): void
    {
        // 1000 × 0.59 = 590。
        $this->post('/checkout/mock', $this->payload([
            'price' => 1,
            'amount' => 1,
            'mockAmount' => 1,
        ]))->assertOk()->assertSee('NT$590');
    }

    public function test_a_draft_variant_is_still_not_purchasable(): void
    {
        $variant = ServiceVariant::query()->where('sku', 'ig-followers-real')->first();
        $variant->update(['status' => 'draft']);

        $this->post('/checkout/mock', $this->payload(['variant' => $variant->id]))
            ->assertSessionHasErrors('variant');
    }

    public function test_the_mock_makes_no_outbound_request(): void
    {
        Http::preventStrayRequests();

        // ⛔ 本輪不呼叫綠界／LINE Pay／統編／載具任何 API。
        $this->post('/checkout/mock', $this->payload([
            'invoice_kind' => 'business',
            'personal_invoice_mode' => null,
            'buyer_tax_id' => '12345678',
            'buyer_name' => '測試公司',
        ]))->assertOk();
    }

    public function test_the_mock_writes_nothing_to_the_database(): void
    {
        // 基準是 seeder 建立目錄後的狀態（那些寫入是 audit observer 的正常行為）。
        $before = DB::table('admin_audit_logs')->count();

        $this->post('/checkout/mock', $this->payload([
            'customer_email' => 'private@example.com',
            'customer_phone' => '0912345678',
            'personal_invoice_mode' => 'mobile_barcode',
            'carrier_number' => '/ABC1234',
        ]))->assertOk();

        // 結帳本身不得新增任何資料列，⛔ 也不得把個資寫進既有資料表。
        $this->assertSame($before, DB::table('admin_audit_logs')->count());

        foreach (DB::table('admin_audit_logs')->pluck('after') as $payload) {
            $this->assertStringNotContainsString('private@example.com', (string) $payload);
            $this->assertStringNotContainsString('0912345678', (string) $payload);
            $this->assertStringNotContainsString('ABC1234', (string) $payload);
        }
    }
}
