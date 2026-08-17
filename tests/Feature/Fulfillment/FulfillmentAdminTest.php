<?php

namespace Tests\Feature\Fulfillment;

use App\Models\FulfillmentMapping;
use App\Models\FulfillmentOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The admin pages: who reaches them, and what they must never leak.
 */
class FulfillmentAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    private function owner(): User
    {
        return User::factory()->create(['role' => 'owner', 'is_active' => true]);
    }

    private function editor(): User
    {
        return User::factory()->create(['role' => 'editor', 'is_active' => true]);
    }

    public static function fulfillmentRouteProvider(): array
    {
        return [
            'mapping index' => ['/admin/fulfillment-mappings'],
            'mapping create' => ['/admin/fulfillment-mappings/create'],
            'fulfillment index' => ['/admin/fulfillment-orders'],
        ];
    }

    #[DataProvider('fulfillmentRouteProvider')]
    public function test_a_guest_is_redirected(string $url): void
    {
        $this->get($url)->assertRedirect();
    }

    /** ⛔ 後台頁面一律 noindex：它們絕不能出現在搜尋結果裡。 */
    #[DataProvider('fulfillmentRouteProvider')]
    public function test_admin_pages_are_noindex(string $url): void
    {
        $response = $this->actingAs($this->owner())->get($url);

        $response->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_an_owner_reaches_the_mapping_pages(): void
    {
        $this->actingAs($this->owner())->get('/admin/fulfillment-mappings')->assertOk();
        $this->actingAs($this->owner())->get('/admin/fulfillment-mappings/create')->assertOk();
    }

    public function test_an_editor_is_forbidden_from_mappings(): void
    {
        // ⛔ 後端擋，不是只把選單藏起來。
        $this->actingAs($this->editor())->get('/admin/fulfillment-mappings')->assertForbidden();
        $this->actingAs($this->editor())->get('/admin/fulfillment-mappings/create')->assertForbidden();
    }

    public function test_an_editor_may_read_fulfillment_records(): void
    {
        $this->actingAs($this->editor())->get('/admin/fulfillment-orders')->assertOk();
    }

    public function test_an_editor_never_sees_the_provider_service_id(): void
    {
        $row = FulfillmentOrder::factory()->submitted()->create();

        $response = $this->actingAs($this->editor())->get('/admin/fulfillment-orders/'.$row->id);

        $response->assertOk();
        // ⛔ 供應商代碼是商業敏感資訊，客服不需要知道我們從哪裡進貨。
        $response->assertDontSee('FAKE-SERVICE-0000');
    }

    public function test_an_owner_does_see_the_provider_service_id(): void
    {
        $row = FulfillmentOrder::factory()->submitted()->create();

        $response = $this->actingAs($this->owner())->get('/admin/fulfillment-orders/'.$row->id);

        $response->assertOk();
        $response->assertSee('FAKE-SERVICE-0000');
    }

    public function test_no_page_offers_a_retry_or_cancel_action(): void
    {
        $row = FulfillmentOrder::factory()->submitted()->create();

        $response = $this->actingAs($this->owner())->get('/admin/fulfillment-orders/'.$row->id);

        $response->assertOk();
        /*
         * ⛔ 沒有重送、取消或手動標記完成。
         *
         * 每一個都是在宣稱供應商做了什麼，而在我們後台按一下不會讓它成真。
         */
        $response->assertDontSee('重新送出');
        $response->assertDontSee('取消履約');
        $response->assertDontSee('標記完成');
    }

    public function test_the_customer_target_never_appears_in_the_admin_html(): void
    {
        $row = FulfillmentOrder::factory()->submitted()->create();
        $row->orderItem->update(['target_value' => '@private-customer-handle']);

        foreach (['owner', 'editor'] as $role) {
            $user = User::factory()->create(['role' => $role, 'is_active' => true]);

            $response = $this->actingAs($user)->get('/admin/fulfillment-orders/'.$row->id);

            $response->assertOk();
            // ⛔ 履約頁面不需要顯示客人的帳號。
            $response->assertDontSee('@private-customer-handle');
        }
    }

    public function test_a_mapping_cannot_be_deleted_through_the_admin(): void
    {
        $mapping = FulfillmentMapping::factory()->create();

        $response = $this->actingAs($this->owner())
            ->get('/admin/fulfillment-mappings/'.$mapping->id.'/edit');

        $response->assertOk();
        // ⛔ 只能停用：既有履約紀錄需要它才能解釋自己送去了哪裡。
        $response->assertDontSee('刪除');
    }
}
