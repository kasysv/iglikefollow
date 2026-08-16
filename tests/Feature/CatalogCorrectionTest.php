<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\ServiceVariant;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Taking broken products off sale, and keeping the seeder out of live data.
 *
 * Two problems sit behind these tests. A variant published before the payable
 * amount rules existed could not be unpublished, because the save that removed
 * it from sale was rejected for the very defect being removed — the fix was
 * blocked by the fault. And re-running the seeder overwrote every variant it
 * knew about, so a deliberate price, quantity step or draft status could be
 * silently reverted by a routine command.
 */
class CatalogCorrectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
        Http::preventStrayRequests();
    }

    private function variant(string $sku): ServiceVariant
    {
        return ServiceVariant::query()->where('sku', $sku)->firstOrFail();
    }

    /** Force a broken combination past the observer, as pre-existing data would be. */
    private function breakVariantInDatabase(ServiceVariant $variant): void
    {
        DB::table('service_variants')->where('id', $variant->id)->update([
            'unit_price' => '0.7000',
            'min_quantity' => 10,
            'max_quantity' => 10000,
            'step_quantity' => 1,   // 買 11 個 = 7.7 元，收不到
            'default_quantity' => 100,
            'status' => 'published',
        ]);
    }

    // ============================================ 1. 失效商品必須可以下架

    public function test_a_published_variant_with_an_unpayable_quantity_can_be_taken_down(): void
    {
        $variant = $this->variant('ig-followers-standard');
        $this->breakVariantInDatabase($variant);

        // ⛔ 下架正是修正動作，不能因為原本就失效而卡死。
        $variant->refresh()->forceFill(['status' => 'draft'])->save();

        $this->assertSame('draft', $variant->fresh()->status);
    }

    public function test_the_same_broken_variant_cannot_go_back_to_published(): void
    {
        $variant = $this->variant('ig-followers-standard');
        $this->breakVariantInDatabase($variant);

        $variant->refresh()->forceFill(['status' => 'draft'])->save();

        $this->expectException(ValidationException::class);

        $variant->fresh()->forceFill(['status' => 'published'])->save();
    }

    public function test_structural_rules_still_apply_to_drafts(): void
    {
        // ⛔ 放寬的只有「可售性」；結構錯誤在草稿一樣不接受。
        $variant = $this->variant('ig-followers-standard');

        $this->expectException(ValidationException::class);

        $variant->forceFill([
            'status' => 'draft',
            'min_quantity' => 5000,
            'max_quantity' => 100,
        ])->save();
    }

    public function test_a_draft_variant_is_not_purchasable(): void
    {
        $variant = $this->variant('ig-followers-standard');
        $variant->forceFill(['status' => 'draft'])->save();

        // 草稿不得因為觀察者放寬而變得買得到。
        $this->post('/checkout/start', [
            'variant' => $variant->id,
            'quantity' => $variant->default_quantity,
        ])->assertRedirect();

        $this->assertSame(0, Order::count());
    }

    public function test_a_draft_variant_is_absent_from_the_public_page(): void
    {
        $variant = $this->variant('ig-followers-standard');
        $variant->forceFill(['status' => 'draft'])->save();

        $this->get('/services/instagram/followers')
            ->assertOk()
            ->assertDontSee($variant->label);
    }

    // ============================================ 2. 修正後的 step 全範圍有效

    public static function correctedStepProvider(): array
    {
        return [
            'female followers' => ['ig-followers-standard-female', '0.8000', 100, 5],
            'chinese followers' => ['ig-followers-standard-chinese', '0.7000', 10, 10],
            'female post likes' => ['ig-post-likes-standard-female', '0.8000', 100, 5],
            'chinese post likes' => ['ig-post-likes-standard-chinese', '0.7000', 10, 10],
        ];
    }

    #[DataProvider('correctedStepProvider')]
    public function test_the_corrected_step_makes_every_quantity_payable(
        string $sku, string $rate, int $min, int $step
    ): void {
        $variant = new ServiceVariant;
        $variant->setRawAttributes([
            'unit_price' => $rate,
            'min_quantity' => $min,
            'max_quantity' => 10000,
            'step_quantity' => $step,
        ], true);

        // 整個範圍都算得出整數台幣。
        $this->assertNull($variant->firstNonIntegerQuantity(), "{$sku} 仍有無法付款的數量");
        $this->assertNotNull($variant->firstPurchasableQuantity());
        $this->assertTrue($variant->quantityIsValid($variant->firstPurchasableQuantity()));
    }

    public function test_quantities_off_the_corrected_step_are_still_refused(): void
    {
        $variant = new ServiceVariant;
        $variant->setRawAttributes([
            'unit_price' => '0.7000',
            'min_quantity' => 10,
            'max_quantity' => 10000,
            'step_quantity' => 10,
        ], true);

        // ⛔ 放寬 step 不代表放寬伺服器端檢查。
        $this->assertFalse($variant->quantityIsValid(11));
        $this->assertFalse($variant->quantityIsValid(15));
        $this->assertTrue($variant->quantityIsValid(20));
        $this->assertSame(14, $variant->amountFor(20));
    }

    // ============================================ 3. Seeder 不得覆寫後台資料

    public function test_reseeding_does_not_overwrite_an_admin_edited_variant(): void
    {
        // fixture 有這個 SKU，正是 seeder 以前會覆寫的對象。
        $variant = $this->variant('ig-followers-standard');

        $variant->forceFill([
            'step_quantity' => 200,
            'default_quantity' => 1000,
            'status' => 'draft',
            'sort_order' => 99,
        ])->save();

        // ⛔ 比對 raw row 而非模型：這才是實際落盤的內容。
        $before = (array) DB::table('service_variants')->where('id', $variant->id)->first();

        $this->seed(CatalogSeeder::class);

        $after = (array) DB::table('service_variants')->where('id', $variant->id)->first();

        // ⛔ 重跑 seeder 不得改動任何商業欄位、狀態或上架時間。
        $this->assertSame($before, $after);
    }

    public function test_reseeding_does_not_republish_a_draft(): void
    {
        $variant = $this->variant('ig-followers-standard');
        $variant->forceFill(['status' => 'draft'])->save();

        $this->seed(CatalogSeeder::class);

        $this->assertSame('draft', $variant->fresh()->status);
    }

    public function test_reseeding_creates_no_duplicates(): void
    {
        $before = ServiceVariant::withTrashed()->count();

        $this->seed(CatalogSeeder::class);
        $this->seed(CatalogSeeder::class);

        $this->assertSame($before, ServiceVariant::withTrashed()->count());
    }

    public function test_a_fresh_seed_leaves_unconfirmed_products_as_drafts(): void
    {
        // ⛔ 價格組合未確認的商品不得在全新環境自動上架。
        $this->assertSame('draft', $this->variant('fb-comments-standard')->status);
        $this->assertSame('draft', $this->variant('ig-auto-likes-standard')->status);
    }

    /**
     * 從未發布過的草稿不得帶著首次發布時間。
     *
     * ⛔ 那個時間戳不只是紀錄：`PublishObserver` 用它永久鎖住 slug，
     * 所以謊稱一個從未上架的草稿曾經發布，會讓它的網址再也改不了。
     * 首次發布時間只能由真正的發布動作產生。
     */
    public function test_a_fresh_draft_has_never_been_published(): void
    {
        foreach (['fb-comments-standard', 'ig-auto-likes-standard'] as $sku) {
            $variant = $this->variant($sku);

            $this->assertSame('draft', $variant->status);
            $this->assertNull(
                $variant->first_published_at,
                "{$sku} 從未發布，卻有首次發布時間"
            );
            // 直接查 raw row，⛔ 確認不是 cast 造成的假象。
            $this->assertNull(
                DB::table('service_variants')->where('sku', $sku)->value('first_published_at')
            );
        }
    }

    public function test_a_fresh_seed_still_publishes_the_confirmed_products(): void
    {
        foreach ([
            'ig-followers-standard',
            'ig-followers-real',
            'ig-post-likes-standard',
        ] as $sku) {
            $variant = $this->variant($sku);

            $this->assertSame('published', $variant->status, "{$sku} 狀態漂移");
            // 已發布就必須有首次發布時間，否則 slug 鎖與稽核都失去依據。
            $this->assertNotNull($variant->first_published_at, "{$sku} 缺少首次發布時間");
        }
    }

    public function test_publishing_a_seeded_draft_stamps_it_then(): void
    {
        $variant = $this->variant('fb-comments-standard');
        $this->assertNull($variant->first_published_at);

        // 讓它變成可售，才能通過發布時的可售性檢查。
        $variant->forceFill([
            'unit_price' => '25.0000',
            'status' => 'published',
        ])->save();

        // 首次發布時間由真正的發布動作產生，⛔ 不是 seeder 預先蓋的。
        $this->assertNotNull($variant->fresh()->first_published_at);
    }

    public function test_reseeding_does_not_stamp_an_existing_draft(): void
    {
        $variant = $this->variant('ig-followers-standard');
        $variant->forceFill(['status' => 'draft'])->save();

        DB::table('service_variants')->where('id', $variant->id)
            ->update(['first_published_at' => null]);

        $this->seed(CatalogSeeder::class);

        $this->assertNull(
            DB::table('service_variants')->where('id', $variant->id)->value('first_published_at')
        );
    }

    // ============================================ 4. 下架不得讓服務頁消失

    public function test_the_facebook_comments_page_survives_its_variant_being_drafted(): void
    {
        $variant = $this->variant('fb-comments-standard');
        $variant->forceFill(['status' => 'draft'])->save();

        // 服務本身仍已發布，⛔ 不得因為沒有可售方案就 404。
        $this->get('/services/facebook/comments')->assertOk();
    }

    public function test_other_facebook_services_keep_their_published_variants(): void
    {
        $this->variant('fb-comments-standard')->forceFill(['status' => 'draft'])->save();

        $this->get('/services/facebook')
            ->assertOk()
            ->assertSee('Facebook');

        // 其他 Facebook 方案不受影響。
        $this->assertGreaterThan(0, ServiceVariant::query()
            ->published()
            ->whereHas('service.platform', fn ($q) => $q->where('slug', 'facebook'))
            ->count());
    }
}
