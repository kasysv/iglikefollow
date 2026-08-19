<?php

namespace Tests\Feature;

use App\Filament\Pages\ManageSiteSettings;
use App\Filament\Resources\Faqs\Pages\CreateFaq;
use App\Filament\Resources\Platforms\Pages\CreatePlatform;
use App\Filament\Resources\Platforms\Pages\EditPlatform;
use App\Filament\Resources\ServiceContentSections\Pages\CreateServiceContentSection;
use App\Filament\Resources\Services\Pages\CreateService;
use App\Filament\Resources\Services\Pages\EditService;
use App\Filament\Resources\Services\RelationManagers\VariantsRelationManager;
use App\Filament\Resources\ServiceVariants\Pages\CreateServiceVariant;
use App\Filament\Support\ImageField;
use App\Models\Faq;
use App\Models\Platform;
use App\Models\Service;
use App\Models\ServiceContentSection;
use App\Models\ServiceVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Testing\File as TestingFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * R3 closure cover: publish permission, URL invariants, and upload safety.
 *
 * Every permission test drives a real Filament form with a forged payload,
 * because the previous cover only asserted that the policy returned false —
 * which says nothing about what the server does when a crafted Livewire
 * request arrives carrying a value the UI never offered.
 */
class M2aR3Test extends TestCase
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

    private function actingAsEditor(): User
    {
        $editor = $this->editor();
        $this->actingAs($editor);

        return $editor;
    }

    private function actingAsOwner(): User
    {
        $owner = $this->owner();
        $this->actingAs($owner);

        return $owner;
    }

    private function publishedService(Platform $platform, string $slug = 'followers'): Service
    {
        $service = Service::factory()->create([
            'platform_id' => $platform->id, 'slug' => $slug, 'status' => 'draft',
            // M2-C(D-103):公開頁需要 product slug。
            'product_slug' => 'test-'.$slug,
        ]);
        $service->update(['status' => 'published']);

        return $service->fresh();
    }

    // ============================================ 1. Editor 可建立草稿，狀態強制 draft

    public function test_an_editor_can_create_a_platform_draft_with_its_initial_slug(): void
    {
        $this->actingAsEditor();

        Livewire::test(CreatePlatform::class)
            ->fillForm(['name' => '新平台', 'slug' => 'threads', 'sort_order' => 0])
            ->call('create')
            ->assertHasNoFormErrors();

        $platform = Platform::where('slug', 'threads')->firstOrFail();

        // 初始 slug 可以保存，但狀態必須是草稿。
        $this->assertSame('threads', $platform->slug);
        $this->assertSame('draft', $platform->status);
        $this->assertNull($platform->first_published_at);
    }

    public function test_an_editor_can_create_a_service_draft_under_a_chosen_platform(): void
    {
        $this->actingAsEditor();
        $platform = Platform::factory()->published()->create(['slug' => 'instagram']);

        Livewire::test(CreateService::class)
            ->fillForm([
                'platform_id' => $platform->id, 'name' => '新服務', 'slug' => 'comments',
                'input_kind' => 'post_url', 'input_label' => '貼文網址', 'sort_order' => 0,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $service = Service::where('slug', 'comments')->firstOrFail();

        // 建立時可指定初始平台。
        $this->assertSame($platform->id, $service->platform_id);
        $this->assertSame('draft', $service->status);
    }

    // ============================================ 2. Editor 偽造 payload 不得發布

    public function test_an_editor_cannot_publish_a_platform_with_a_forged_payload(): void
    {
        $this->actingAsEditor();

        Livewire::test(CreatePlatform::class)
            // disabled() 只是前端狀態，⛔ 後端必須自己擋。
            ->fillForm(['name' => 'X', 'slug' => 'forged', 'status' => 'published', 'sort_order' => 0])
            ->call('create');

        $this->assertSame('draft', Platform::where('slug', 'forged')->value('status'));
        $this->assertDatabaseMissing('platforms', ['slug' => 'forged', 'status' => 'published']);
    }

    public function test_an_editor_cannot_publish_a_service_with_a_forged_payload(): void
    {
        $this->actingAsEditor();
        $platform = Platform::factory()->published()->create(['slug' => 'instagram']);

        Livewire::test(CreateService::class)
            ->fillForm([
                'platform_id' => $platform->id, 'name' => 'X', 'slug' => 'forged',
                'input_kind' => 'account', 'input_label' => '帳號',
                'status' => 'published', 'sort_order' => 0,
            ])
            ->call('create');

        $this->assertSame('draft', Service::where('slug', 'forged')->value('status'));
    }

    public function test_an_editor_cannot_publish_a_variant_with_a_forged_payload(): void
    {
        $this->actingAsEditor();
        $platform = Platform::factory()->published()->create(['slug' => 'instagram']);
        $service = Service::factory()->create(['platform_id' => $platform->id, 'slug' => 'followers']);

        Livewire::test(CreateServiceVariant::class)
            ->fillForm([
                'service_id' => $service->id, 'label' => '偽造服務項目',
                'unit_price' => 1, 'quantity_unit' => '個',
                'min_quantity' => 100, 'max_quantity' => 1000, 'step_quantity' => 100,
                'default_quantity' => 100, 'currency' => 'TWD',
                'status' => 'published', 'sort_order' => 0,
            ])
            ->call('create');

        $this->assertSame('draft', ServiceVariant::where('label', '偽造服務項目')->value('status'));
    }

    public function test_an_editor_cannot_publish_a_content_section_with_a_forged_payload(): void
    {
        $this->actingAsEditor();
        $platform = Platform::factory()->published()->create(['slug' => 'instagram']);
        $service = Service::factory()->create(['platform_id' => $platform->id, 'slug' => 'followers']);

        Livewire::test(CreateServiceContentSection::class)
            ->fillForm([
                'service_id' => $service->id, 'heading' => '偽造段落', 'body' => '內容',
                'status' => 'published', 'sort_order' => 0,
            ])
            ->call('create');

        $this->assertSame('draft', ServiceContentSection::where('heading', '偽造段落')->value('status'));
    }

    public function test_an_editor_cannot_publish_an_faq_with_a_forged_payload(): void
    {
        $this->actingAsEditor();

        Livewire::test(CreateFaq::class)
            ->fillForm([
                'scope' => 'global', 'question' => '偽造問題', 'answer' => '回答',
                'status' => 'published', 'sort_order' => 0,
            ])
            ->call('create');

        $this->assertSame('draft', Faq::where('question', '偽造問題')->value('status'));
    }

    public function test_an_editor_cannot_publish_through_a_relation_manager(): void
    {
        $this->actingAsEditor();
        $platform = Platform::factory()->published()->create(['slug' => 'instagram']);
        $service = Service::factory()->create(['platform_id' => $platform->id, 'slug' => 'followers']);

        Livewire::test(VariantsRelationManager::class, [
            'ownerRecord' => $service,
            'pageClass' => EditService::class,
        ])->callTableAction('create', data: [
            'label' => '分頁偽造', 'unit_price' => 1, 'quantity_unit' => '個',
            'min_quantity' => 100, 'max_quantity' => 1000, 'step_quantity' => 100,
            'default_quantity' => 100, 'currency' => 'TWD',
            'status' => 'published', 'sort_order' => 0,
        ]);

        $this->assertSame('draft', ServiceVariant::where('label', '分頁偽造')->value('status'));
    }

    public function test_an_editor_cannot_unpublish_existing_published_content(): void
    {
        $platform = Platform::factory()->create(['slug' => 'instagram', 'status' => 'draft']);
        $platform->update(['status' => 'published']);

        $this->actingAsEditor();

        Livewire::test(EditPlatform::class, ['record' => $platform->getRouteKey()])
            ->fillForm(['status' => 'draft'])
            ->call('save');

        // ⛔ 下架同樣是 owner 專屬動作。
        $this->assertSame('published', $platform->fresh()->status);
    }

    public function test_an_editor_cannot_archive_content_with_a_forged_payload(): void
    {
        $platform = Platform::factory()->create(['slug' => 'instagram', 'status' => 'draft']);

        $this->actingAsEditor();

        Livewire::test(EditPlatform::class, ['record' => $platform->getRouteKey()])
            ->fillForm(['status' => 'archived'])
            ->call('save');

        $this->assertSame('draft', $platform->fresh()->status);
    }

    // ============================================ 3. Editor 不得改既有 slug 或搬移服務

    public function test_an_editor_cannot_change_an_existing_draft_slug(): void
    {
        $platform = Platform::factory()->create(['slug' => 'original', 'status' => 'draft']);

        $this->actingAsEditor();

        Livewire::test(EditPlatform::class, ['record' => $platform->getRouteKey()])
            ->fillForm(['slug' => 'editor-changed', 'name' => '改過名字'])
            ->call('save');

        $platform->refresh();

        $this->assertSame('original', $platform->slug);
        // 其餘合法欄位仍應正常存檔。
        $this->assertSame('改過名字', $platform->name);
    }

    public function test_an_editor_cannot_move_a_service_to_another_platform(): void
    {
        $instagram = Platform::factory()->published()->create(['slug' => 'instagram']);
        $facebook = Platform::factory()->published()->create(['slug' => 'facebook']);
        $service = Service::factory()->create([
            'platform_id' => $instagram->id, 'slug' => 'followers', 'status' => 'draft',
        ]);

        $this->actingAsEditor();

        Livewire::test(EditService::class, ['record' => $service->getRouteKey()])
            ->fillForm(['platform_id' => $facebook->id])
            ->call('save');

        $this->assertSame($instagram->id, $service->fresh()->platform_id);
    }

    // ============================================ 4. Owner 可搬移從未發布的 draft

    public function test_an_owner_can_move_a_service_that_was_never_published(): void
    {
        $instagram = Platform::factory()->published()->create(['slug' => 'instagram']);
        $facebook = Platform::factory()->published()->create(['slug' => 'facebook']);
        $service = Service::factory()->create([
            'platform_id' => $instagram->id, 'slug' => 'followers', 'status' => 'draft',
        ]);

        $this->actingAsOwner();

        Livewire::test(EditService::class, ['record' => $service->getRouteKey()])
            ->fillForm(['platform_id' => $facebook->id])
            ->call('save')
            ->assertHasNoFormErrors();

        // 從未發布過就沒有公開 URL 可破壞，owner 可以調整。
        $this->assertSame($facebook->id, $service->fresh()->platform_id);
    }

    public function test_an_owner_can_still_change_the_slug_of_a_never_published_draft(): void
    {
        $platform = Platform::factory()->create(['slug' => 'before', 'status' => 'draft']);

        $this->actingAsOwner();

        Livewire::test(EditPlatform::class, ['record' => $platform->getRouteKey()])
            ->fillForm(['slug' => 'after'])
            ->call('save');

        $this->assertSame('after', $platform->fresh()->slug);
    }

    // ============================================ 5. 曾發布的 Service URL 永久鎖定

    public function test_an_owner_cannot_move_a_service_that_has_ever_been_published(): void
    {
        $instagram = Platform::factory()->published()->create(['slug' => 'instagram']);
        $facebook = Platform::factory()->published()->create(['slug' => 'facebook']);
        $service = $this->publishedService($instagram);

        $this->actingAsOwner();

        Livewire::test(EditService::class, ['record' => $service->getRouteKey()])
            ->fillForm(['platform_id' => $facebook->id])
            ->call('save');

        // /services/{platform}/{service} 是公開 URL，⛔ 沒有 301 就不得改變。
        $this->assertSame($instagram->id, $service->fresh()->platform_id);
    }

    public function test_a_plain_model_update_cannot_move_a_published_service(): void
    {
        $instagram = Platform::factory()->published()->create(['slug' => 'instagram']);
        $facebook = Platform::factory()->published()->create(['slug' => 'facebook']);
        $service = $this->publishedService($instagram);

        // 不經後台的直接更新同樣必須被擋下。
        $service->update(['platform_id' => $facebook->id]);

        $this->assertSame($instagram->id, $service->fresh()->platform_id);
    }

    public function test_the_original_public_url_still_resolves_after_a_move_attempt(): void
    {
        $instagram = Platform::factory()->published()->create(['slug' => 'instagram', 'name' => 'Instagram']);
        $facebook = Platform::factory()->published()->create(['slug' => 'facebook', 'name' => 'Facebook']);
        $service = $this->publishedService($instagram);

        $this->actingAsOwner();
        Livewire::test(EditService::class, ['record' => $service->getRouteKey()])
            ->fillForm(['platform_id' => $facebook->id])
            ->call('save');

        // canonical 商品頁仍然 200(平台未被改走),新平台組合不得存在。
        $this->get('/product/test-followers/')->assertOk();
        $this->get('/services/facebook/followers')->assertNotFound();
    }

    public function test_a_published_services_slug_stays_locked_for_owners_too(): void
    {
        $platform = Platform::factory()->published()->create(['slug' => 'instagram']);
        $service = $this->publishedService($platform);

        $this->actingAsOwner();
        $service->update(['slug' => 'renamed']);

        $this->assertSame('followers', $service->fresh()->slug);
        $this->get('/product/test-followers/')->assertOk();
    }

    // ============================================ 6. Owner 既有流程不退步

    public function test_an_owner_can_still_publish_and_unpublish(): void
    {
        $platform = Platform::factory()->create(['slug' => 'instagram', 'status' => 'draft']);

        $this->actingAsOwner();

        Livewire::test(EditPlatform::class, ['record' => $platform->getRouteKey()])
            ->fillForm(['status' => 'published'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('published', $platform->fresh()->status);
        $this->assertNotNull($platform->fresh()->first_published_at);

        Livewire::test(EditPlatform::class, ['record' => $platform->getRouteKey()])
            ->fillForm(['status' => 'draft'])
            ->call('save');

        $this->assertSame('draft', $platform->fresh()->status);
    }

    public function test_an_owner_can_archive_content(): void
    {
        $platform = Platform::factory()->published()->create(['slug' => 'instagram']);

        $this->actingAsOwner();

        Livewire::test(EditPlatform::class, ['record' => $platform->getRouteKey()])
            ->fillForm(['status' => 'archived'])
            ->call('save');

        $this->assertSame('archived', $platform->fresh()->status);
    }

    public function test_seeding_and_console_work_is_not_affected_by_the_guard(): void
    {
        // 沒有登入使用者（seeder／artisan）時不套用後台權限。
        $platform = Platform::factory()->create(['slug' => 'seeded', 'status' => 'published']);

        $this->assertSame('published', $platform->fresh()->status);
    }

    // ============================================ 圖片副檔名不得相信原始檔名

    public function test_the_stored_extension_comes_from_detected_content_not_the_filename(): void
    {
        Storage::fake('public');
        $this->actingAsOwner();

        // 真實 JPEG 內容，卻使用 PHP family 副檔名。
        $payload = $this->realJpegNamed('evil.pht');

        Livewire::test(CreatePlatform::class)
            ->fillForm([
                'name' => '平台', 'slug' => 'disguised', 'sort_order' => 0,
                'hero_image_path' => [$payload],
                'hero_image_alt' => 'alt',
            ])
            ->call('create');

        $path = Platform::where('slug', 'disguised')->value('hero_image_path');

        $this->assertNotNull($path, '圖片應通過內容 MIME 驗證並落盤');
        // ⛔ 落盤只能是 .jpg，且不得殘留原始 basename 或原始副檔名。
        $this->assertStringEndsWith('.jpg', $path);
        $this->assertStringNotContainsString('.pht', $path);
        $this->assertStringNotContainsString('evil', $path);
    }

    public function test_a_png_keeps_its_own_extension(): void
    {
        Storage::fake('public');
        $this->actingAsOwner();

        Livewire::test(CreatePlatform::class)
            ->fillForm([
                'name' => '平台', 'slug' => 'png-upload', 'sort_order' => 0,
                'hero_image_path' => [UploadedFile::fake()->image('shot.png', 100, 100)],
                'hero_image_alt' => 'alt',
            ])
            ->call('create');

        $this->assertStringEndsWith('.png', (string) Platform::where('slug', 'png-upload')->value('hero_image_path'));
    }

    public function test_no_php_family_extension_can_reach_the_public_disk(): void
    {
        Storage::fake('public');
        $this->actingAsOwner();

        foreach (['a.php', 'b.phtml', 'c.pht', 'd.phar'] as $index => $name) {
            $payload = $this->realJpegNamed($name);

            Livewire::test(CreatePlatform::class)
                ->fillForm([
                    'name' => '平台', 'slug' => 'php-attempt-'.$index, 'sort_order' => 0,
                    'hero_image_path' => [$payload],
                    'hero_image_alt' => 'alt',
                ])
                ->call('create');
        }

        foreach (Storage::disk('public')->allFiles() as $file) {
            $this->assertMatchesRegularExpression(
                '/\.(jpg|png|webp)$/',
                $file,
                "落盤檔案 {$file} 使用了不允許的副檔名"
            );
        }
    }

    public function test_a_file_that_is_not_a_real_image_is_rejected_outright(): void
    {
        Storage::fake('public');
        $this->actingAsOwner();

        // 內容不是圖片時，MIME 驗證階段就該擋下，根本不會走到副檔名映射。
        Livewire::test(CreatePlatform::class)
            ->fillForm([
                'name' => '平台', 'slug' => 'not-an-image', 'sort_order' => 0,
                'hero_image_path' => [UploadedFile::fake()->create('script.pht', 8, 'text/x-php')],
                'hero_image_alt' => 'alt',
            ])
            ->call('create')
            ->assertHasFormErrors(['hero_image_path']);

        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_the_extension_map_only_covers_the_allowed_types(): void
    {
        $this->assertSame(
            ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'],
            ImageField::EXTENSIONS
        );

        $this->assertSame(array_keys(ImageField::EXTENSIONS), ImageField::ACCEPTED);
    }

    public function test_an_unmapped_mime_type_is_refused_rather_than_guessed(): void
    {
        $this->expectException(\RuntimeException::class);

        // 驗證被繞過時，⛔ 寧可拒絕也不猜副檔名。
        ImageField::extensionFor(UploadedFile::fake()->create('x.svg', 4, 'image/svg+xml'));
    }

    // ============================================ 兩個一致性修正

    public function test_the_cta_service_list_excludes_services_on_unpublished_platforms(): void
    {
        $this->actingAsOwner();

        $live = Platform::factory()->published()->create(['slug' => 'instagram', 'name' => 'Instagram']);
        $hidden = Platform::factory()->create(['slug' => 'threads', 'name' => 'Threads', 'status' => 'draft']);

        $visible = Service::factory()->published()->create(['platform_id' => $live->id, 'slug' => 'followers', 'name' => '可選服務']);
        $unreachable = Service::factory()->published()->create(['platform_id' => $hidden->id, 'slug' => 'followers', 'name' => '不可選服務']);

        $options = Service::query()->where('status', 'published')
            ->whereHas('platform', fn ($q) => $q->where('status', 'published'))
            ->pluck('id')->all();

        // 平台未發布時該服務頁不可公開存取，列出來只會必然回退首頁。
        $this->assertContains($visible->id, $options);
        $this->assertNotContains($unreachable->id, $options);
    }

    public function test_the_company_name_helper_text_matches_its_required_rule(): void
    {
        $this->actingAsOwner();

        Livewire::test(ManageSiteSettings::class)
            ->fillForm(['company_name' => '', 'home_h1' => '標題'])
            ->call('save')
            // 欄位是必填，說明文字就不能再寫「留空會自動使用預設值」。
            ->assertHasFormErrors(['company_name']);

        // 以實際渲染出來的畫面為準：必填欄位的說明不得暗示可以留空。
        $html = Livewire::test(ManageSiteSettings::class)->html();

        $this->assertStringContainsString('必填。例如：IGLIKEFOLLOW。', $html);
        $this->assertStringNotContainsString('留空會自動使用 IGLIKEFOLLOW', $html);
    }

    /**
     * A file whose *contents* are a real JPEG but whose name says otherwise.
     *
     * UploadedFile::fake()->image() picks its format from the extension, so it
     * cannot express this case on its own: the whole point is a file that
     * passes content-based MIME validation while carrying a dangerous name.
     * mimeTypeToReport stands in for the server-side detection a real upload
     * gets — the test double otherwise infers the type from the extension,
     * which is precisely the behaviour under test.
     */
    private function realJpegNamed(string $name): TestingFile
    {
        $image = imagecreatetruecolor(60, 60);
        $handle = tmpfile();
        imagejpeg($image, stream_get_meta_data($handle)['uri']);
        imagedestroy($image);

        $file = new TestingFile($name, $handle);
        $file->sizeToReport = fstat($handle)['size'];
        $file->mimeTypeToReport = 'image/jpeg';

        return $file;
    }
}
