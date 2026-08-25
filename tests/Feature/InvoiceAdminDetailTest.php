<?php

namespace Tests\Feature;

use App\Enums\IntegrationEnvironment;
use App\Enums\IntegrationProvider;
use App\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Models\IntegrationSetting;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Owner-only invoice detail page shows the full accounting identifiers.
 *
 * ⛔ InvoicePolicy already keeps editors and everyone else out of this page
 * entirely; this file only checks what an Owner who *is* let in actually sees.
 */
class InvoiceAdminDetailTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        return User::factory()->create(['role' => 'owner', 'is_active' => true]);
    }

    private function editor(): User
    {
        return User::factory()->create(['role' => 'editor', 'is_active' => true]);
    }

    private function invoice(): Invoice
    {
        $order = Order::factory()->create();

        $setting = IntegrationSetting::factory()
            ->forProvider(IntegrationProvider::EcpayInvoice, IntegrationEnvironment::Production)
            ->create();

        return Invoice::factory()->create([
            'order_id' => $order->id,
            'invoice_number' => 'AB12345678',
            'random_code' => '9876',
            'provider_reference' => 'REF-TEST-998877',
        ]);
    }

    public function test_the_owner_sees_the_full_invoice_number_random_code_and_reference(): void
    {
        $this->actingAs($this->owner());
        $invoice = $this->invoice();

        $html = Livewire::test(ViewInvoice::class, ['record' => $invoice->getKey()])->assertOk()->html();

        $this->assertStringContainsString('AB12345678', $html);
        $this->assertStringContainsString('9876', $html);
        $this->assertStringContainsString('REF-TEST-998877', $html);
    }

    public function test_an_editor_cannot_open_the_invoice_page_at_all(): void
    {
        $editor = $this->editor();
        $invoice = $this->invoice();

        // ⛔ 發票是稅務文件，比訂單更窄：InvoicePolicy 只允許 Owner。
        $this->assertFalse($editor->can('view', $invoice));

        $this->actingAs($editor)
            ->get('/admin/invoices/'.$invoice->getKey())
            ->assertForbidden();
    }

    /** ⛔ 未開立時每一欄都顯示占位字串，不是空白或推論成功／失敗。 */
    public function test_a_pending_invoice_shows_placeholders_not_blank_or_zero(): void
    {
        $this->actingAs($this->owner());

        $invoice = Invoice::factory()->create([
            'order_id' => Order::factory()->create()->id,
            'invoice_number' => null,
            'random_code' => null,
            'provider_reference' => null,
            'issued_at' => null,
            'voided_at' => null,
            'allowance_at' => null,
        ]);

        $html = Livewire::test(ViewInvoice::class, ['record' => $invoice->getKey()])->assertOk()->html();

        $this->assertStringContainsString('尚未開立', $html);
        $this->assertStringContainsString('未作廢', $html);
        $this->assertStringContainsString('無折讓', $html);
    }

    /**
     * ⛔ 同一張發票的完整號碼／隨機碼／provider 參考碼，在訂單頁的「電子發票」
     * section 與獨立發票頁必須顯示相同值——兩個後台頁面不得互相矛盾。
     */
    public function test_the_order_page_and_invoice_page_agree_on_the_same_full_values(): void
    {
        $this->actingAs($this->owner());
        $invoice = $this->invoice();
        $order = $invoice->order;

        $invoicePageHtml = Livewire::test(ViewInvoice::class, ['record' => $invoice->getKey()])
            ->assertOk()->html();
        $orderPageHtml = Livewire::test(ViewOrder::class, ['record' => $order->reference])
            ->assertOk()->html();

        foreach (['AB12345678', '9876', 'REF-TEST-998877'] as $value) {
            $this->assertStringContainsString($value, $invoicePageHtml, "發票頁缺少：{$value}");
            $this->assertStringContainsString($value, $orderPageHtml, "訂單頁缺少：{$value}");
        }
    }
}
