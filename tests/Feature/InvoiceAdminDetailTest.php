<?php

namespace Tests\Feature;

use App\Enums\IntegrationEnvironment;
use App\Enums\IntegrationProvider;
use App\Filament\Resources\Invoices\Pages\ViewInvoice;
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
}
