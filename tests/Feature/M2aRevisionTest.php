<?php

namespace Tests\Feature;

use App\Filament\Resources\Services\RelationManagers\ContentSectionsRelationManager;
use App\Filament\Resources\Services\RelationManagers\FaqsRelationManager;
use App\Filament\Resources\Services\RelationManagers\VariantsRelationManager;
use App\Filament\Resources\Services\ServiceResource;
use App\Models\AdminAuditLog;
use App\Models\Platform;
use App\Models\Service;
use App\Models\ServiceVariant;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Regression cover for the gaps GPT raised against the first M2A build.
 */
class M2aRevisionTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        return User::factory()->create(['role' => 'owner', 'is_active' => true]);
    }

    // ---------------------------------------------------------------- 1. 草稿壞連結

    public function test_navigation_never_links_to_an_unreachable_draft_platform(): void
    {
        Platform::factory()->published()->create(['slug' => 'instagram', 'name' => 'Instagram']);
        Platform::factory()->create(['slug' => 'threads', 'name' => 'Threads', 'status' => 'draft']);

        $html = $this->get('/')->assertOk()->getContent();

        // 之前導覽會列出草稿平台，點下去卻是 404。
        $this->assertStringNotContainsString('/services/threads', $html);
        $this->assertStringContainsString('/services/instagram', $html);
        $this->get('/services/threads')->assertNotFound();
    }

    public function test_every_platform_link_on_the_homepage_actually_resolves(): void
    {
        Platform::factory()->published()->create(['slug' => 'instagram', 'name' => 'Instagram']);
        Platform::factory()->create(['slug' => 'threads', 'status' => 'draft']);
        Platform::factory()->create(['slug' => 'youtube', 'status' => 'archived']);

        $html = $this->get('/')->assertOk()->getContent();

        preg_match_all('#/services/([a-z0-9-]+)"#', $html, $matches);

        foreach (array_unique($matches[1]) as $slug) {
            $this->get("/services/{$slug}")->assertOk();
        }
    }

    // ---------------------------------------------------------------- 2. preview noindex

    public function test_preview_is_noindex_even_when_the_site_is_open_to_indexing(): void
    {
        config()->set('app.env', 'production');
        config()->set('seo.allow_indexing', true);
        config()->set('seo.indexable_host', 'localhost');

        $platform = Platform::factory()->published()->create(['slug' => 'instagram', 'name' => 'Instagram']);
        Service::factory()->create([
            'platform_id' => $platform->id,
            'slug' => 'secret',
            'status' => 'draft',
        ]);

        $response = $this->actingAs($this->owner())
            ->get('/services/instagram/secret?preview=1')
            ->assertOk();

        // ⛔ 預覽不得依賴全站 IndexingPolicy。
        $response->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->assertStringContainsString('<meta name="robots" content="noindex, nofollow">', $response->getContent());
    }

    // ---------------------------------------------------------------- 3. first_published_at 與 slug 鎖

    public function test_publishing_through_the_app_stamps_first_published_at(): void
    {
        $platform = Platform::factory()->create(['status' => 'draft']);
        $this->assertNull($platform->first_published_at);

        $platform->update(['status' => 'published']);

        $this->assertNotNull($platform->fresh()->first_published_at);
    }

    public function test_slug_cannot_be_changed_after_first_publish(): void
    {
        $platform = Platform::factory()->create(['slug' => 'original', 'status' => 'draft']);
        $platform->update(['status' => 'published']);

        $platform->update(['slug' => 'changed']);

        // 已上線的網址必須保持不變。
        $this->assertSame('original', $platform->fresh()->slug);
    }

    public function test_slug_is_still_editable_while_a_record_is_a_draft(): void
    {
        $platform = Platform::factory()->create(['slug' => 'draft-slug', 'status' => 'draft']);

        $platform->update(['slug' => 'new-slug']);

        $this->assertSame('new-slug', $platform->fresh()->slug);
    }

    public function test_unpublishing_does_not_release_the_slug_lock(): void
    {
        $service = Service::factory()->create(['slug' => 'locked', 'status' => 'draft']);
        $service->update(['status' => 'published']);
        $service->update(['status' => 'draft']);

        $service->update(['slug' => 'try-to-change']);

        $this->assertSame('locked', $service->fresh()->slug);
    }

    // ---------------------------------------------------------------- 4. 圖片串接與真實上傳

    public function test_uploaded_images_are_stored_and_rendered_on_the_front_end(): void
    {
        Storage::fake('public');

        $file = UploadedFile::fake()->image('hero.jpg', 1200, 675);
        $path = $file->store('uploads', 'public');

        $platform = Platform::factory()->published()->create([
            'slug' => 'instagram',
            'name' => 'Instagram',
            'hero_image_path' => $path,
            'hero_image_alt' => '平台主視覺',
        ]);

        Service::factory()->published()->create([
            'platform_id' => $platform->id,
            'slug' => 'followers',
            'card_image_path' => $path,
            'card_image_alt' => '服務卡片圖',
        ]);

        Storage::disk('public')->assertExists($path);

        $this->get('/services/instagram')
            ->assertOk()
            ->assertSee($path, false)
            ->assertSee('平台主視覺', false)
            ->assertSee('服務卡片圖', false);
    }

    public function test_variant_image_is_rendered_on_the_service_page(): void
    {
        Storage::fake('public');
        $path = UploadedFile::fake()->image('variant.png', 800, 600)->store('uploads', 'public');

        $platform = Platform::factory()->published()->create(['slug' => 'instagram', 'name' => 'Instagram']);
        $service = Service::factory()->published()->create([
            'platform_id' => $platform->id,
            'slug' => 'followers',
        ]);
        ServiceVariant::factory()->published()->create([
            'service_id' => $service->id,
            'image_path' => $path,
            'image_alt' => '服務項目圖片',
        ]);

        $this->get('/services/instagram/followers')
            ->assertOk()
            ->assertSee($path, false)
            ->assertSee('服務項目圖片', false);
    }

    public function test_storage_disk_is_publicly_linked(): void
    {
        // 沒有 public/storage symlink 時，後台上傳的圖片在前台會 404。
        $this->assertFileExists(public_path('storage'));
    }

    // ---------------------------------------------------------------- 5. 服務項目交叉驗證

    public function test_default_quantity_below_minimum_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        ServiceVariant::factory()->create([
            'min_quantity' => 100,
            'max_quantity' => 1000,
            'step_quantity' => 100,
            'default_quantity' => 50,
        ]);
    }

    public function test_default_quantity_above_maximum_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        ServiceVariant::factory()->create([
            'min_quantity' => 100,
            'max_quantity' => 1000,
            'step_quantity' => 100,
            'default_quantity' => 5000,
        ]);
    }

    public function test_default_quantity_off_step_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        ServiceVariant::factory()->create([
            'min_quantity' => 100,
            'max_quantity' => 1000,
            'step_quantity' => 100,
            'default_quantity' => 155,
        ]);
    }

    public function test_minimum_greater_than_maximum_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        ServiceVariant::factory()->create([
            'min_quantity' => 5000,
            'max_quantity' => 100,
            'step_quantity' => 100,
            'default_quantity' => 100,
        ]);
    }

    // ---------------------------------------------------------------- 6. Relation Managers

    public function test_service_edit_screen_exposes_its_related_records(): void
    {
        $relations = ServiceResource::getRelations();

        $this->assertContains(VariantsRelationManager::class, $relations);
        $this->assertContains(ContentSectionsRelationManager::class, $relations);
        $this->assertContains(FaqsRelationManager::class, $relations);
    }

    // ---------------------------------------------------------------- 7. Site Settings 串接

    public function test_site_settings_drive_the_homepage_copy(): void
    {
        SiteSetting::create([
            'company_name' => 'IGLIKEFOLLOW',
            'home_eyebrow' => '自訂小標',
            'home_h1' => '自訂大標題',
            'home_intro' => '自訂介紹文字',
            'primary_cta_label' => '自訂按鈕文字',
            'primary_cta_route' => 'home',
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSee('自訂小標')
            ->assertSee('自訂大標題')
            ->assertSee('自訂介紹文字')
            ->assertSee('自訂按鈕文字');
    }

    public function test_company_image_from_settings_is_rendered(): void
    {
        Storage::fake('public');
        $path = UploadedFile::fake()->image('company.jpg')->store('uploads', 'public');

        SiteSetting::create([
            'company_name' => 'IGLIKEFOLLOW',
            'home_h1' => '標題',
            'company_image_path' => $path,
            'company_image_alt' => '公司形象照',
        ]);

        $this->get('/')->assertOk()->assertSee($path, false)->assertSee('公司形象照', false);
    }

    public function test_cta_route_only_accepts_the_allow_list(): void
    {
        $platform = Platform::factory()->published()->create(['slug' => 'instagram', 'name' => 'Instagram']);
        Service::factory()->published()->create(['platform_id' => $platform->id, 'slug' => 'followers']);

        // 直接寫入資料庫的惡意值不得變成前台連結。
        SiteSetting::create([
            'company_name' => 'IGLIKEFOLLOW',
            'home_h1' => '標題',
            'primary_cta_label' => 'CTA',
            'primary_cta_route' => 'https://evil.example.com',
        ]);

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('evil.example.com', $html);
    }

    public function test_cta_route_platform_points_at_a_real_platform(): void
    {
        $platform = Platform::factory()->published()->create(['slug' => 'instagram', 'name' => 'Instagram']);
        Service::factory()->published()->create(['platform_id' => $platform->id, 'slug' => 'followers']);

        SiteSetting::create([
            'company_name' => 'IGLIKEFOLLOW',
            'home_h1' => '標題',
            'primary_cta_label' => 'CTA',
            'primary_cta_route' => 'platform',
        ]);

        $this->get('/')->assertOk()->assertSee('/services/instagram', false);
    }

    // ---------------------------------------------------------------- 8. last owner 保護

    public function test_the_last_active_owner_cannot_be_demoted(): void
    {
        $owner = $this->owner();

        $this->expectException(ValidationException::class);
        $owner->update(['role' => 'editor']);
    }

    public function test_the_last_active_owner_cannot_be_deactivated(): void
    {
        $owner = $this->owner();

        $this->expectException(ValidationException::class);
        $owner->update(['is_active' => false]);
    }

    public function test_the_last_active_owner_cannot_be_deleted(): void
    {
        $owner = $this->owner();

        $this->expectException(ValidationException::class);
        $owner->delete();
    }

    public function test_an_owner_can_be_demoted_when_another_owner_remains(): void
    {
        $first = $this->owner();
        $this->owner();

        $first->update(['role' => 'editor']);

        $this->assertSame('editor', $first->fresh()->role);
    }

    public function test_policy_refuses_to_delete_the_last_owner(): void
    {
        $owner = $this->owner();
        $other = User::factory()->create(['role' => 'owner', 'is_active' => true]);

        // $other 存在時 $owner 不是最後一位，可刪；反之則否。
        $this->assertTrue($other->can('delete', $owner));

        $owner->forceFill(['role' => 'editor'])->saveQuietly();

        $this->assertFalse($other->can('delete', $other->fresh()));
    }

    // ---------------------------------------------------------------- 9. audit coverage

    public function test_site_setting_changes_are_audited(): void
    {
        $setting = SiteSetting::create([
            'company_name' => 'IGLIKEFOLLOW',
            'home_h1' => '標題',
        ]);

        $this->assertDatabaseHas('admin_audit_logs', [
            'auditable_type' => SiteSetting::class,
            'auditable_id' => $setting->id,
            'action' => 'created',
        ]);

        $setting->update(['company_name' => '改名']);

        $this->assertDatabaseHas('admin_audit_logs', [
            'auditable_type' => SiteSetting::class,
            'auditable_id' => $setting->id,
            'action' => 'updated',
        ]);
    }

    public function test_user_role_changes_are_audited_without_leaking_the_password(): void
    {
        $this->owner();
        $editor = User::factory()->create(['role' => 'editor', 'is_active' => true]);

        $editor->update(['role' => 'owner']);

        $log = AdminAuditLog::query()
            ->where('auditable_type', User::class)
            ->where('auditable_id', $editor->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($log);

        $payload = json_encode([$log->before, $log->after], JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString($editor->getAuthPassword(), (string) $payload);
    }

    // ---------------------------------------------------------------- 10. 時區

    public function test_application_uses_taipei_time(): void
    {
        $this->assertSame('Asia/Taipei', config('app.timezone'));
        $this->assertSame('+08:00', now()->format('P'));
    }
}
