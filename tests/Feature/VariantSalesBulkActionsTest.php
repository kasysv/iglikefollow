<?php

namespace Tests\Feature;

use App\Filament\Resources\Services\Pages\EditService;
use App\Filament\Resources\Services\RelationManagers\VariantsRelationManager;
use App\Models\AdminAuditLog;
use App\Models\Service;
use App\Models\ServiceVariant;
use App\Models\User;
use App\Support\CanonicalUrl;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * 商品頁「服務項目」的一鍵停售／一鍵開賣。
 *
 * ⭐ Owner 選擇在**服務項目（款式）**層級停售：商品頁本身不下架，
 * URL／canonical／sitemap 一律不變。
 */
class VariantSalesBulkActionsTest extends TestCase
{
    use RefreshDatabase;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
        Artisan::call('m2c:apply-copy');

        $this->service = Service::query()
            ->where('product_slug', 'ig買粉絲')
            ->firstOrFail();
    }

    private function owner(): User
    {
        return User::factory()->create(['role' => 'owner', 'is_active' => true]);
    }

    private function editor(): User
    {
        return User::factory()->create(['role' => 'editor', 'is_active' => true]);
    }

    private function table()
    {
        return Livewire::test(VariantsRelationManager::class, [
            'ownerRecord' => $this->service,
            'pageClass' => EditService::class,
        ]);
    }

    private function variant(string $status, array $overrides = []): ServiceVariant
    {
        return ServiceVariant::factory()->create(array_merge([
            'service_id' => $this->service->id,
            'status' => $status,
            'first_published_at' => $status === 'draft' ? null : now(),
        ], $overrides));
    }

    // ============================================ 1. 停售

    public function test_stop_moves_published_items_to_archived_and_leaves_others_alone(): void
    {
        $this->actingAs($this->owner());

        $published = $this->variant('published');
        $draft = $this->variant('draft');
        $archived = $this->variant('archived');

        $this->table()
            ->callTableBulkAction('stopSelling', [$published, $draft, $archived])
            ->assertNotified();

        $this->assertSame('archived', $published->fresh()->status);
        // ⛔ 不是「已發布」的一律不動。
        $this->assertSame('draft', $draft->fresh()->status);
        $this->assertSame('archived', $archived->fresh()->status);
    }

    // ============================================ 2. 開賣

    public function test_resume_moves_archived_items_back_to_published(): void
    {
        $this->actingAs($this->owner());

        $archived = $this->variant('archived');
        $published = $this->variant('published');

        $this->table()
            ->callTableBulkAction('resumeSelling', [$archived, $published])
            ->assertNotified();

        $this->assertSame('published', $archived->fresh()->status);
        $this->assertSame('published', $published->fresh()->status);
    }

    /**
     * ⛔⛔ 草稿不得被一鍵開賣。
     *
     * 草稿可能是還沒設定完的新項目，批次勾選時很容易一起被選到。
     */
    public function test_resume_never_publishes_a_draft(): void
    {
        $this->actingAs($this->owner());

        $draft = $this->variant('draft');

        $this->table()->callTableBulkAction('resumeSelling', [$draft]);

        $this->assertSame('draft', $draft->fresh()->status);
    }

    /**
     * ⭐ 一筆不可售時，只有那一筆失敗，其他照常開賣。
     *
     * 單價 0 的項目在「已下架」時可以存在（可售規則只在發布時檢查），
     * 但重新開賣會被既有的 VariantIntegrityObserver 擋下。
     */
    public function test_one_unsellable_item_does_not_block_the_rest(): void
    {
        $this->actingAs($this->owner());

        $good = $this->variant('archived');
        $bad = $this->variant('archived', ['label' => '壞資料項目', 'unit_price' => 0]);

        $this->table()
            ->callTableBulkAction('resumeSelling', [$good, $bad])
            ->assertNotified();

        $this->assertSame('published', $good->fresh()->status);
        $this->assertSame('archived', $bad->fresh()->status, '不可售的項目必須維持已下架。');
    }

    // ============================================ 3. 權限

    public function test_an_editor_does_not_see_the_buttons(): void
    {
        $this->actingAs($this->editor());

        $this->table()
            ->assertTableBulkActionHidden('stopSelling')
            ->assertTableBulkActionHidden('resumeSelling');
    }

    public function test_an_owner_sees_the_buttons(): void
    {
        $this->actingAs($this->owner());

        $this->table()
            ->assertTableBulkActionVisible('stopSelling')
            ->assertTableBulkActionVisible('resumeSelling');
    }

    /**
     * ⛔⛔ 就算編輯者硬送出 bulk action，狀態也不得改變。
     *
     * 隱藏按鈕只是介面；真正的防線是 PublishObserver（model 層）。
     */
    public function test_an_editor_cannot_change_status_even_by_forcing_the_action(): void
    {
        /*
         * ⛔ 必須在登入編輯者**之前**建立已發布的項目。
         * 我第一版反過來寫，結果項目在建立當下就被 PublishObserver 降成草稿
         * ——那是既有防線正常運作，⛔ 不是這個 action 造成的。
         */
        $published = $this->variant('published');
        $this->assertSame('published', $published->fresh()->status);

        $this->actingAs($this->editor());

        try {
            $this->table()->callTableBulkAction('stopSelling', [$published]);
        } catch (\Throwable) {
            // 被拒絕（例外或 403）都可以接受；重點是下面的狀態。
        }

        $this->assertSame('published', $published->fresh()->status);
    }

    // ============================================ 4. 前台與 SEO

    /**
     * ⭐ 停售後商品頁仍然存在：200、canonical 不變，只是那個項目消失。
     */
    public function test_the_product_page_stays_live_after_stopping_an_item(): void
    {
        $this->actingAs($this->owner());

        $variants = $this->service->variants()->where('status', 'published')->get();
        $this->assertGreaterThan(1, $variants->count());

        $target = $variants->first();

        $this->table()->callTableBulkAction('stopSelling', [$target]);

        auth()->logout();

        $path = '/product/'.$this->service->product_slug.'/';
        $response = $this->get($path)->assertOk();

        $response->assertSee('rel="canonical" href="'.CanonicalUrl::to($path).'"', false);
        $response->assertDontSee('value="'.$target->id.'"', false);
    }

    /** 全部停售時，商品頁仍 200，顯示既有的「方案準備中」。 */
    public function test_stopping_every_item_shows_the_existing_empty_state_not_a_404(): void
    {
        $this->actingAs($this->owner());

        $all = $this->service->variants()->where('status', 'published')->get();

        $this->table()->callTableBulkAction('stopSelling', $all);

        auth()->logout();

        $this->get('/product/'.$this->service->product_slug.'/')
            ->assertOk()
            ->assertSee('方案準備中');
    }

    /** sitemap 不因停售而改變。 */
    public function test_the_sitemap_is_unchanged_by_stopping_items(): void
    {
        $before = $this->get('/sitemap.xml')->getContent();

        $this->actingAs($this->owner());
        $this->table()->callTableBulkAction(
            'stopSelling',
            $this->service->variants()->where('status', 'published')->get()
        );
        auth()->logout();

        $this->assertSame($before, $this->get('/sitemap.xml')->getContent());
    }

    // ============================================ 5. 稽核

    public function test_every_change_is_audited_with_the_owner(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner);

        $published = $this->variant('published');

        $this->table()->callTableBulkAction('stopSelling', [$published]);

        $log = AdminAuditLog::query()
            ->where('auditable_type', ServiceVariant::class)
            ->where('auditable_id', $published->id)
            ->where('action', 'updated')
            ->latest('id')
            ->first();

        $this->assertNotNull($log, '停售必須留下稽核紀錄。');
        $this->assertSame($owner->id, $log->user_id);
    }
}
