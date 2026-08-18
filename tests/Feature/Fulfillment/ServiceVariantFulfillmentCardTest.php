<?php

namespace Tests\Feature\Fulfillment;

use App\Models\FulfillmentMapping;
use App\Models\ProviderService;
use App\Models\ServiceVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The variant edit page's fulfillment card: Owner-only, edit-only,
 * read-only, escaped, and honest about what it will not calculate.
 *
 * ⛔ Fixtures are fictional. Markers are chosen to be unique so their
 * absence in editor pages and logs is meaningful.
 */
class ServiceVariantFulfillmentCardTest extends TestCase
{
    use RefreshDatabase;

    private const PROVIDER_ID = '71088';

    private const PROVIDER_NAME = '虛構卡片供應商服務MARKER';

    private const RATE = '0.9713';

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

    /** @return array{0: ServiceVariant, 1: ProviderService, 2: FulfillmentMapping} */
    private function compatibleDisabledMapping(array $providerOverrides = []): array
    {
        // variant factory:min 100/max 10000/step 100/default 1000/price 0.5。
        $variant = ServiceVariant::factory()->create(['sku' => 'card-sku-01']);

        $provider = ProviderService::factory()->available()->create(array_merge([
            'provider_service_id' => self::PROVIDER_ID,
            'name' => self::PROVIDER_NAME,
            'minimum_quantity_raw' => '10',
            'maximum_quantity_raw' => '20000',
            'rate_raw' => self::RATE,
        ], $providerOverrides));

        $mapping = FulfillmentMapping::factory()->create([
            'service_variant_id' => $variant->id,
            'provider_service_id' => $provider->provider_service_id,
            'is_enabled' => false,
        ]);

        return [$variant, $provider, $mapping];
    }

    private function editUrl(ServiceVariant $variant): string
    {
        return '/admin/service-variants/'.$variant->id.'/edit';
    }

    // ==================================== 1. Owner:compatible disabled 全欄位

    public function test_an_owner_sees_the_full_card_for_a_compatible_disabled_mapping(): void
    {
        [$variant] = $this->compatibleDisabledMapping();

        $response = $this->actingAs($this->owner())->get($this->editUrl($variant));

        $response->assertOk();
        // 狀態與「已儲存值」聲明。
        $response->assertSee('履約對照');
        $response->assertSee('目前已儲存值');
        $response->assertSee('已停用');
        $response->assertSee('不等於自動派單');
        // 本站側。
        $response->assertSee('card-sku-01');
        $response->assertSee('NT$ 0.5');
        $response->assertSee('實際可購');
        $response->assertSee('100–10000');
        // default 1000 × 0.5 = 500(既有整數 Money 算法)。
        $response->assertSee('NT$ 500');
        // provider 側(Owner-only)。
        $response->assertSee(self::PROVIDER_ID);
        $response->assertSee(self::PROVIDER_NAME);
        $response->assertSee(self::RATE);
        // TWD 警語。
        $response->assertSee('幣別為 TWD');
        $response->assertSee('不是本站售價');
        $response->assertSee('暫不計算成本/毛利');
        // 相容判定。
        $response->assertSee('數量相容');
    }

    // ==================================== 2. max 不足

    public function test_a_provider_maximum_too_low_shows_the_warning_with_both_maxima(): void
    {
        [$variant] = $this->compatibleDisabledMapping(['maximum_quantity_raw' => '9999']);

        $response = $this->actingAs($this->owner())->get($this->editUrl($variant));

        $response->assertOk();
        $response->assertSee('供應商最高量低於本站實際最高可購量');
        // 雙方已儲存上下限並列。
        $response->assertSee('9999');
        $response->assertSee('10000');
        // ⛔ 不得顯示相容成功。
        $response->assertDontSee('✔ 數量相容');
    }

    // ==================================== 3. 空／警告狀態不 throw

    public function test_an_absent_mapping_shows_the_safe_empty_state(): void
    {
        $variant = ServiceVariant::factory()->create();

        $response = $this->actingAs($this->owner())->get($this->editUrl($variant));

        $response->assertOk();
        $response->assertSee('尚未設定履約對應');
        $response->assertSee('設定對應後才有相容性判定');
    }

    public function test_a_missing_provider_row_shows_the_stale_warning(): void
    {
        $variant = ServiceVariant::factory()->create();
        FulfillmentMapping::factory()->create([
            'service_variant_id' => $variant->id,
            'provider_service_id' => 'GONE-999',
            'is_enabled' => false,
        ]);

        $response = $this->actingAs($this->owner())->get($this->editUrl($variant));

        $response->assertOk();
        $response->assertSee('已不在本機目錄');
        $response->assertSee('無法判定');
    }

    public function test_an_unavailable_provider_row_shows_the_unavailable_warning(): void
    {
        [$variant, $provider] = $this->compatibleDisabledMapping();
        $provider->update(['is_available' => false]);

        $response = $this->actingAs($this->owner())->get($this->editUrl($variant));

        $response->assertOk();
        $response->assertSee('已標記不可用');
    }

    // ==================================== 4. 權限與 create 頁

    public function test_an_editor_never_sees_provider_markers(): void
    {
        [$variant] = $this->compatibleDisabledMapping();

        $response = $this->actingAs($this->editor())->get($this->editUrl($variant));

        $response->assertOk();
        // ⛔ 供應商代碼／名稱／raw rate 是商業敏感資訊,editor 一項都不能看到。
        $response->assertDontSee(self::PROVIDER_ID);
        $response->assertDontSee(self::PROVIDER_NAME);
        $response->assertDontSee(self::RATE);
        $response->assertDontSee('履約對照');
    }

    public function test_a_guest_is_redirected(): void
    {
        [$variant] = $this->compatibleDisabledMapping();

        $this->get($this->editUrl($variant))->assertRedirect();
    }

    public function test_the_create_page_has_no_card(): void
    {
        $response = $this->actingAs($this->owner())->get('/admin/service-variants/create');

        $response->assertOk();
        $response->assertDontSee('履約對照');
    }

    // ==================================== 5. escape 與 side effects

    public function test_hostile_provider_text_is_escaped(): void
    {
        [$variant, $provider] = $this->compatibleDisabledMapping();
        // 繞過任何表單層,直接寫入敵意文字(值層仍虛構)。
        DB::table('provider_services')->where('id', $provider->id)->update([
            'name' => '<script>alert("card-xss")</script>',
            'category' => '<img src=x onerror=alert("card-cat")>',
            'service_type' => '<b>bold-type</b>',
        ]);

        $response = $this->actingAs($this->owner())->get($this->editUrl($variant));

        $response->assertOk();
        // raw HTML 0;escaped 版本存在。
        $response->assertDontSee('<script>alert("card-xss")</script>', false);
        $response->assertDontSee('<img src=x onerror=alert("card-cat")>', false);
        $response->assertDontSee('<b>bold-type</b>', false);
        $response->assertSee('<script>alert("card-xss")</script>');
    }

    /** ⛔ GET render 不寫 DB;marker 不進 log。 */
    public function test_the_render_writes_nothing_and_leaks_nothing_to_logs(): void
    {
        [$variant] = $this->compatibleDisabledMapping();
        // ⛔ listener 之前先建立 user:users/audit 的 insert 是測試 setup,不是 render。
        $owner = $this->owner();

        $writes = 0;
        $sqls = [];
        $watching = true;
        DB::listen(function ($query) use (&$writes, &$sqls, &$watching) {
            $sql = strtolower(trim($query->sql));
            if ($watching && (str_starts_with($sql, 'insert') || str_starts_with($sql, 'update') || str_starts_with($sql, 'delete'))) {
                // sessions 是 framework 行為,不是卡片的寫入。
                if (! str_contains($sql, 'sessions')) {
                    $writes++;
                    $sqls[] = $sql;
                }
            }
        });

        $this->actingAs($owner)->get($this->editUrl($variant))->assertOk();
        $watching = false;

        $this->assertSame(0, $writes, '⛔ 履約卡 render 不得寫入資料庫: '.implode(' || ', $sqls));

        $log = storage_path('logs/laravel.log');
        if (File::exists($log)) {
            $tail = mb_substr((string) File::get($log), -20000);
            $this->assertStringNotContainsString(self::PROVIDER_ID, $tail);
            $this->assertStringNotContainsString(self::RATE, $tail);
        }
    }
}
