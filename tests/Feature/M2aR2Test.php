<?php

namespace Tests\Feature;

use App\Filament\Pages\ManageSiteSettings;
use App\Filament\Resources\Platforms\Pages\CreatePlatform;
use App\Filament\Resources\Platforms\Pages\EditPlatform;
use App\Filament\Resources\ServiceContentSections\Schemas\ServiceContentSectionForm;
use App\Filament\Resources\Services\Pages\CreateService;
use App\Filament\Resources\Services\Pages\EditService;
use App\Filament\Resources\Services\RelationManagers\ContentSectionsRelationManager;
use App\Filament\Resources\Services\RelationManagers\FaqsRelationManager;
use App\Filament\Resources\Services\RelationManagers\VariantsRelationManager;
use App\Filament\Resources\ServiceVariants\Pages\CreateServiceVariant;
use App\Filament\Resources\ServiceVariants\Schemas\ServiceVariantForm;
use App\Filament\Support\ImageField;
use App\Models\Faq;
use App\Models\Platform;
use App\Models\Service;
use App\Models\ServiceContentSection;
use App\Models\ServiceVariant;
use App\Models\SiteSetting;
use App\Models\User;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Regression cover for the R2 gaps GPT raised against the M2A admin.
 *
 * These drive the real Filament forms through Livewire rather than writing to
 * the models directly: the point of every item below is that the *admin screen*
 * behaves correctly, which model-level assertions cannot demonstrate.
 */
class M2aR2Test extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        return User::factory()->create(['role' => 'owner', 'is_active' => true]);
    }

    private function actingAsOwner(): User
    {
        $owner = $this->owner();
        $this->actingAs($owner);

        return $owner;
    }

    /** @return array{0: Platform, 1: Service, 2: Service} */
    private function twoServices(): array
    {
        $platform = Platform::factory()->published()->create(['slug' => 'instagram', 'name' => 'Instagram']);
        $a = Service::factory()->create(['platform_id' => $platform->id, 'slug' => 'followers', 'name' => 'A 服務']);
        $b = Service::factory()->create(['platform_id' => $platform->id, 'slug' => 'post-likes', 'name' => 'B 服務']);

        return [$platform, $a, $b];
    }

    private function relationManager(string $class, Service $owner)
    {
        return Livewire::test($class, [
            'ownerRecord' => $owner,
            'pageClass' => EditService::class,
        ]);
    }

    // ================================================================ 1. 媒體欄位表單驗收

    public function test_alt_text_is_required_when_an_image_is_uploaded(): void
    {
        $this->actingAsOwner();

        Livewire::test(CreatePlatform::class)
            ->fillForm([
                'name' => '平台', 'slug' => 'with-image', 'status' => 'draft', 'sort_order' => 0,
                'hero_image_path' => [UploadedFile::fake()->image('hero.jpg', 1200, 675)],
            ])
            ->call('create')
            // ⛔ 有圖沒有 alt 不得存檔：螢幕閱讀器與圖片搜尋都需要這段文字。
            ->assertHasFormErrors(['hero_image_alt']);

        $this->assertDatabaseMissing('platforms', ['slug' => 'with-image']);
    }

    public function test_alt_text_is_optional_when_there_is_no_image(): void
    {
        $this->actingAsOwner();

        Livewire::test(CreatePlatform::class)
            ->fillForm(['name' => '平台', 'slug' => 'no-image', 'status' => 'draft', 'sort_order' => 0])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('platforms', ['slug' => 'no-image']);
    }

    public function test_a_valid_image_with_alt_text_is_accepted_and_stored(): void
    {
        Storage::fake('public');
        $this->actingAsOwner();

        Livewire::test(CreatePlatform::class)
            ->fillForm([
                'name' => '平台', 'slug' => 'good-image', 'status' => 'draft', 'sort_order' => 0,
                'hero_image_path' => [UploadedFile::fake()->image('hero.jpg', 1200, 675)],
                'hero_image_alt' => '平台主視覺',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $platform = Platform::where('slug', 'good-image')->firstOrFail();

        $this->assertNotEmpty($platform->hero_image_path);
        Storage::disk('public')->assertExists($platform->hero_image_path);
        $this->assertSame('平台主視覺', $platform->hero_image_alt);
    }

    public function test_uploaded_images_do_not_keep_their_original_filename(): void
    {
        Storage::fake('public');
        $this->actingAsOwner();

        Livewire::test(CreatePlatform::class)
            ->fillForm([
                'name' => '平台', 'slug' => 'random-name', 'status' => 'draft', 'sort_order' => 0,
                'hero_image_path' => [UploadedFile::fake()->image('my-secret-file.jpg', 800, 450)],
                'hero_image_alt' => 'alt',
            ])
            ->call('create');

        $path = Platform::where('slug', 'random-name')->value('hero_image_path');

        $this->assertStringNotContainsString('my-secret-file', (string) $path);
    }

    public function test_svg_uploads_are_rejected_by_the_form(): void
    {
        $this->actingAsOwner();

        Livewire::test(CreatePlatform::class)
            ->fillForm([
                'name' => '平台', 'slug' => 'svg-attempt', 'status' => 'draft', 'sort_order' => 0,
                // SVG 可夾帶 script，⛔ 不接受。
                'hero_image_path' => [UploadedFile::fake()->create('payload.svg', 8, 'image/svg+xml')],
                'hero_image_alt' => 'alt',
            ])
            ->call('create')
            ->assertHasFormErrors(['hero_image_path']);

        $this->assertDatabaseMissing('platforms', ['slug' => 'svg-attempt']);
    }

    public function test_images_larger_than_five_megabytes_are_rejected_by_the_form(): void
    {
        $this->actingAsOwner();

        $tooBig = UploadedFile::fake()->create('huge.jpg', ImageField::MAX_KB + 512, 'image/jpeg');

        Livewire::test(CreatePlatform::class)
            ->fillForm([
                'name' => '平台', 'slug' => 'too-big', 'status' => 'draft', 'sort_order' => 0,
                'hero_image_path' => [$tooBig],
                'hero_image_alt' => 'alt',
            ])
            ->call('create')
            ->assertHasFormErrors(['hero_image_path']);

        $this->assertDatabaseMissing('platforms', ['slug' => 'too-big']);
    }

    public function test_editing_other_fields_does_not_delete_an_existing_image(): void
    {
        Storage::fake('public');
        $this->actingAsOwner();

        $path = UploadedFile::fake()->image('kept.jpg')->store('uploads', 'public');
        $platform = Platform::factory()->create([
            'slug' => 'keeps-image', 'name' => '原名', 'status' => 'draft',
            'hero_image_path' => $path, 'hero_image_alt' => '既有圖片',
        ]);

        Livewire::test(EditPlatform::class, ['record' => $platform->getRouteKey()])
            ->fillForm(['name' => '改過的名字'])
            ->call('save')
            ->assertHasNoFormErrors();

        $platform->refresh();

        // ⛔ 只改名字不該把圖片弄丟。
        $this->assertSame('改過的名字', $platform->name);
        $this->assertSame($path, $platform->hero_image_path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_only_raster_image_mime_types_are_accepted(): void
    {
        $this->assertSame(['image/jpeg', 'image/png', 'image/webp'], ImageField::ACCEPTED);
        $this->assertNotContains('image/svg+xml', ImageField::ACCEPTED);
        $this->assertSame(5120, ImageField::MAX_KB);
    }

    // ================================================================ 2. 巢狀資料歸屬鎖定

    public function test_variant_relation_manager_hides_the_owning_service_selector(): void
    {
        $fields = $this->componentNames(fn (Schema $s) => ServiceVariantForm::configure($s, withOwner: false));

        // 看得到就選得到，⛔ 服務底下的款式不該能改掛別的服務。
        $this->assertNotContains('service_id', $fields);
        $this->assertContains('label', $fields);
        // 共用表單仍保有數量交叉驗證欄位。
        $this->assertContains('default_quantity', $fields);
    }

    public function test_standalone_variant_form_still_offers_the_service_selector(): void
    {
        $fields = $this->componentNames(fn (Schema $s) => ServiceVariantForm::configure($s));

        $this->assertContains('service_id', $fields);
    }

    public function test_content_section_relation_manager_hides_the_owning_service_selector(): void
    {
        $hidden = $this->componentNames(fn (Schema $s) => ServiceContentSectionForm::configure($s, withOwner: false));
        $shown = $this->componentNames(fn (Schema $s) => ServiceContentSectionForm::configure($s));

        $this->assertNotContains('service_id', $hidden);
        $this->assertContains('service_id', $shown);
        $this->assertContains('heading', $hidden);
    }

    public function test_a_variant_created_in_a_relation_manager_belongs_to_that_service(): void
    {
        $this->actingAsOwner();
        [, $a, $b] = $this->twoServices();

        $this->relationManager(VariantsRelationManager::class, $a)
            ->callTableAction('create', data: [
                // 即使表單被塞入別的服務，⛔ 也必須落在目前這個服務底下。
                'service_id' => $b->id,
                'label' => '一般粉絲', 'unit_price' => 0.59, 'quantity_unit' => '個',
                'min_quantity' => 100, 'max_quantity' => 10000, 'step_quantity' => 100,
                'default_quantity' => 1000, 'currency' => 'TWD',
                'status' => 'draft', 'sort_order' => 0,
            ])
            ->assertHasNoTableActionErrors();

        $variant = ServiceVariant::where('label', '一般粉絲')->firstOrFail();

        $this->assertSame($a->id, $variant->service_id);
        $this->assertNotSame($b->id, $variant->service_id);
    }

    public function test_a_content_section_created_in_a_relation_manager_belongs_to_that_service(): void
    {
        $this->actingAsOwner();
        [, $a, $b] = $this->twoServices();

        $this->relationManager(ContentSectionsRelationManager::class, $a)
            ->callTableAction('create', data: [
                'service_id' => $b->id,
                'heading' => '購買前須知', 'body' => '內容',
                'status' => 'draft', 'sort_order' => 0,
            ])
            ->assertHasNoTableActionErrors();

        $section = ServiceContentSection::where('heading', '購買前須知')->firstOrFail();

        $this->assertSame($a->id, $section->service_id);
    }

    public function test_an_faq_created_in_a_relation_manager_is_scoped_to_that_service(): void
    {
        $this->actingAsOwner();
        [, $a, $b] = $this->twoServices();

        $this->relationManager(FaqsRelationManager::class, $a)
            ->callTableAction('create', data: [
                'service_id' => $b->id,
                'scope' => 'global',
                'question' => '多久會送達？', 'answer' => '通常 24 小時內。',
                'status' => 'draft', 'sort_order' => 0,
            ])
            ->assertHasNoTableActionErrors();

        $faq = Faq::where('question', '多久會送達？')->firstOrFail();

        $this->assertSame($a->id, $faq->service_id);
        // scope 與 FK 必須一致，⛔ 否則這則 FAQ 會出現在全站每一頁。
        $this->assertSame('service', $faq->scope);
        $this->assertNull($faq->platform_id);
    }

    public function test_editing_a_variant_in_a_relation_manager_cannot_move_it_to_another_service(): void
    {
        $this->actingAsOwner();
        [, $a, $b] = $this->twoServices();

        $variant = ServiceVariant::factory()->create(['service_id' => $a->id, 'label' => '既有款式']);

        $this->relationManager(VariantsRelationManager::class, $a)
            ->callTableAction('edit', $variant, data: [
                'service_id' => $b->id,
                'label' => '改過的款式', 'unit_price' => 1, 'quantity_unit' => '個',
                'min_quantity' => 100, 'max_quantity' => 10000, 'step_quantity' => 100,
                'default_quantity' => 1000, 'currency' => 'TWD',
                'status' => 'draft', 'sort_order' => 0,
            ])
            ->assertHasNoTableActionErrors();

        $variant->refresh();

        $this->assertSame('改過的款式', $variant->label);
        $this->assertSame($a->id, $variant->service_id);
    }

    public function test_relation_managers_do_not_expose_associate_actions(): void
    {
        $this->actingAsOwner();
        [, $a] = $this->twoServices();

        // Associate／Dissociate 會讓既有紀錄被轉掛，⛔ 不提供。
        $this->relationManager(VariantsRelationManager::class, $a)
            ->assertTableActionDoesNotExist('associate')
            ->assertTableActionDoesNotExist('dissociate');
    }

    // ================================================================ 2b. 款式數量交叉驗證（表單層）

    public function test_a_valid_variant_can_actually_be_saved_through_the_standalone_form(): void
    {
        $this->actingAsOwner();
        [, $service] = $this->twoServices();

        // 迴歸：字串規則 'lte:max_quantity' 找不到 data.* 底下的欄位，
        // ⛔ 曾讓完全合法的數量組合也存不進去，等於後台無法新增款式。
        Livewire::test(CreateServiceVariant::class)
            ->fillForm([
                'service_id' => $service->id,
                'label' => '一般粉絲', 'unit_price' => 0.59, 'quantity_unit' => '個',
                'min_quantity' => 100, 'max_quantity' => 10000, 'step_quantity' => 100,
                'default_quantity' => 1000, 'currency' => 'TWD',
                'status' => 'draft', 'sort_order' => 0,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('service_variants', ['label' => '一般粉絲', 'service_id' => $service->id]);
    }

    public function test_the_form_still_rejects_a_minimum_above_the_maximum(): void
    {
        $this->actingAsOwner();
        [, $service] = $this->twoServices();

        Livewire::test(CreateServiceVariant::class)
            ->fillForm([
                'service_id' => $service->id,
                'label' => '壞款式', 'unit_price' => 1, 'quantity_unit' => '個',
                'min_quantity' => 5000, 'max_quantity' => 100, 'step_quantity' => 100,
                'default_quantity' => 100, 'currency' => 'TWD',
                'status' => 'draft', 'sort_order' => 0,
            ])
            ->call('create')
            ->assertHasFormErrors(['min_quantity']);
    }

    public function test_the_form_still_rejects_a_default_quantity_outside_the_range(): void
    {
        $this->actingAsOwner();
        [, $service] = $this->twoServices();

        Livewire::test(CreateServiceVariant::class)
            ->fillForm([
                'service_id' => $service->id,
                'label' => '壞預設', 'unit_price' => 1, 'quantity_unit' => '個',
                'min_quantity' => 100, 'max_quantity' => 1000, 'step_quantity' => 100,
                // 客人一進頁面就會是無效狀態。
                'default_quantity' => 50, 'currency' => 'TWD',
                'status' => 'draft', 'sort_order' => 0,
            ])
            ->call('create')
            ->assertHasFormErrors(['default_quantity']);
    }

    public function test_the_form_still_rejects_a_default_quantity_off_the_step(): void
    {
        $this->actingAsOwner();
        [, $service] = $this->twoServices();

        Livewire::test(CreateServiceVariant::class)
            ->fillForm([
                'service_id' => $service->id,
                'label' => '不整除', 'unit_price' => 1, 'quantity_unit' => '個',
                'min_quantity' => 100, 'max_quantity' => 1000, 'step_quantity' => 100,
                'default_quantity' => 155, 'currency' => 'TWD',
                'status' => 'draft', 'sort_order' => 0,
            ])
            ->call('create')
            ->assertHasFormErrors(['default_quantity']);
    }

    public function test_cross_field_quantity_validation_also_applies_inside_a_relation_manager(): void
    {
        $this->actingAsOwner();
        [, $service] = $this->twoServices();

        $this->relationManager(VariantsRelationManager::class, $service)
            ->callTableAction('create', data: [
                'label' => '壞款式', 'unit_price' => 1, 'quantity_unit' => '個',
                'min_quantity' => 5000, 'max_quantity' => 100, 'step_quantity' => 100,
                'default_quantity' => 100, 'currency' => 'TWD',
                'status' => 'draft', 'sort_order' => 0,
            ])
            ->assertHasTableActionErrors(['min_quantity']);
    }

    // ================================================================ 3. slug 重複的友善錯誤

    public function test_duplicate_platform_slug_fails_on_the_form_not_in_the_database(): void
    {
        $this->actingAsOwner();
        Platform::factory()->create(['slug' => 'instagram', 'name' => 'Instagram']);

        // 之前這裡會丟出 UNIQUE constraint 例外，使用者只看到 500。
        Livewire::test(CreatePlatform::class)
            ->fillForm(['name' => '重複', 'slug' => 'instagram', 'status' => 'draft', 'sort_order' => 0])
            ->call('create')
            ->assertHasFormErrors(['slug']);

        $this->assertSame(1, Platform::where('slug', 'instagram')->count());
    }

    public function test_duplicate_platform_slug_error_is_written_in_chinese(): void
    {
        $this->actingAsOwner();
        Platform::factory()->create(['slug' => 'instagram']);

        $component = Livewire::test(CreatePlatform::class)
            ->fillForm(['name' => '重複', 'slug' => 'instagram', 'status' => 'draft', 'sort_order' => 0])
            ->call('create');

        $messages = collect($component->errors()->messages())->flatten()->implode(' ');

        $this->assertStringContainsString('已經有其他平台在用', $messages);
    }

    public function test_editing_a_platform_without_changing_its_slug_is_allowed(): void
    {
        $this->actingAsOwner();
        $platform = Platform::factory()->create(['slug' => 'instagram', 'name' => '舊名', 'status' => 'draft']);

        // ⛔ 唯一驗證不得把紀錄自己算成衝突。
        Livewire::test(EditPlatform::class, ['record' => $platform->getRouteKey()])
            ->fillForm(['name' => '新名'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('新名', $platform->fresh()->name);
    }

    public function test_duplicate_service_slug_within_the_same_platform_fails_on_the_form(): void
    {
        $this->actingAsOwner();
        $platform = Platform::factory()->create(['slug' => 'instagram']);
        Service::factory()->create(['platform_id' => $platform->id, 'slug' => 'followers']);

        Livewire::test(CreateService::class)
            ->fillForm([
                'platform_id' => $platform->id, 'name' => '重複服務', 'slug' => 'followers',
                'input_kind' => 'account', 'input_label' => '帳號',
                'status' => 'draft', 'sort_order' => 0,
            ])
            ->call('create')
            ->assertHasFormErrors(['slug']);
    }

    public function test_the_same_service_slug_is_allowed_on_a_different_platform(): void
    {
        $this->actingAsOwner();
        $instagram = Platform::factory()->create(['slug' => 'instagram']);
        $facebook = Platform::factory()->create(['slug' => 'facebook']);
        Service::factory()->create(['platform_id' => $instagram->id, 'slug' => 'followers']);

        // 唯一範圍是 (platform_id, slug)，⛔ IG 與 FB 必須能各有一個 followers。
        Livewire::test(CreateService::class)
            ->fillForm([
                'platform_id' => $facebook->id, 'name' => 'FB 粉絲', 'slug' => 'followers',
                'input_kind' => 'page_url', 'input_label' => '粉專網址',
                'status' => 'draft', 'sort_order' => 0,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(2, Service::where('slug', 'followers')->count());
    }

    public function test_an_invalid_slug_format_is_rejected_with_a_chinese_message(): void
    {
        $this->actingAsOwner();

        $component = Livewire::test(CreatePlatform::class)
            ->fillForm(['name' => '平台', 'slug' => 'Not Valid Slug!', 'status' => 'draft', 'sort_order' => 0])
            ->call('create')
            ->assertHasFormErrors(['slug']);

        $messages = collect($component->errors()->messages())->flatten()->implode(' ');

        $this->assertStringContainsString('只能用小寫英文', $messages);
    }

    public function test_a_published_records_slug_is_still_locked(): void
    {
        $platform = Platform::factory()->create(['slug' => 'locked', 'status' => 'draft']);
        $platform->update(['status' => 'published']);

        $platform->update(['slug' => 'attempted-change']);

        // R1 的鎖定規則必須維持不變。
        $this->assertSame('locked', $platform->fresh()->slug);
    }

    // ================================================================ 4. Site Settings 中文介面與穩定 CTA

    public function test_company_name_drives_the_front_end_brand_text(): void
    {
        SiteSetting::create([
            'company_name' => '測試公司名稱',
            'home_h1' => '標題',
        ]);

        $html = $this->get('/')->assertOk()->getContent();

        // Logo 是圖片，公司名稱必須以可存取名稱保留。
        $this->assertStringContainsString('測試公司名稱 首頁', $html);
        $this->assertStringContainsString('alt="測試公司名稱"', $html);
    }

    public function test_brand_text_falls_back_when_no_settings_row_exists(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('IGLIKEFOLLOW', $html);
    }

    public function test_cta_points_at_the_explicitly_chosen_platform(): void
    {
        $instagram = Platform::factory()->published()->create(['slug' => 'instagram', 'name' => 'Instagram', 'sort_order' => 5]);
        // 排序更前面的平台不該搶走 CTA。
        Platform::factory()->published()->create(['slug' => 'facebook', 'name' => 'Facebook', 'sort_order' => 0]);

        SiteSetting::create([
            'company_name' => 'IGLIKEFOLLOW', 'home_h1' => '標題',
            'primary_cta_label' => 'CTA', 'primary_cta_route' => 'platform',
            'primary_cta_platform_id' => $instagram->id,
        ]);

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString(route('platform', 'instagram').'"', $html);
    }

    public function test_cta_target_does_not_drift_when_sort_order_changes(): void
    {
        $instagram = Platform::factory()->published()->create(['slug' => 'instagram', 'sort_order' => 0]);
        $facebook = Platform::factory()->published()->create(['slug' => 'facebook', 'sort_order' => 1]);

        SiteSetting::create([
            'company_name' => 'IGLIKEFOLLOW', 'home_h1' => '標題',
            'primary_cta_label' => 'CTA', 'primary_cta_route' => 'platform',
            'primary_cta_platform_id' => $instagram->id,
        ]);

        // 把 Facebook 排到最前面：CTA 仍必須指向原本指定的 Instagram。
        $facebook->update(['sort_order' => -1]);

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString(route('platform', 'instagram').'"', $html);
    }

    public function test_cta_points_at_the_explicitly_chosen_service(): void
    {
        $platform = Platform::factory()->published()->create(['slug' => 'instagram']);
        $service = Service::factory()->published()->create(['platform_id' => $platform->id, 'slug' => 'followers']);

        SiteSetting::create([
            'company_name' => 'IGLIKEFOLLOW', 'home_h1' => '標題',
            'primary_cta_label' => 'CTA', 'primary_cta_route' => 'service',
            'primary_cta_service_id' => $service->id,
        ]);

        $this->get('/')->assertOk()->assertSee(route('service', ['instagram', 'followers']), false);
    }

    public function test_cta_falls_back_to_the_platform_picker_when_its_target_is_unpublished(): void
    {
        $platform = Platform::factory()->published()->create(['slug' => 'instagram']);
        $service = Service::factory()->published()->create(['platform_id' => $platform->id, 'slug' => 'followers']);

        $settings = SiteSetting::create([
            'company_name' => 'IGLIKEFOLLOW', 'home_h1' => '標題',
            'primary_cta_label' => 'CTA', 'primary_cta_route' => 'service',
            'primary_cta_service_id' => $service->id,
        ]);

        $service->update(['status' => 'draft']);

        // ⛔ 下架的目標不得留下 404 連結。
        $this->assertSame(route('home').'#platforms', $settings->fresh()->ctaUrl());
    }

    public function test_cta_falls_back_when_its_target_was_deleted(): void
    {
        $platform = Platform::factory()->published()->create(['slug' => 'instagram']);

        $settings = SiteSetting::create([
            'company_name' => 'IGLIKEFOLLOW', 'home_h1' => '標題',
            'primary_cta_label' => 'CTA', 'primary_cta_route' => 'platform',
            'primary_cta_platform_id' => $platform->id,
        ]);

        $platform->forceDelete();

        $this->assertSame(route('home').'#platforms', $settings->fresh()->ctaUrl());
    }

    public function test_cta_never_becomes_an_external_link(): void
    {
        $settings = SiteSetting::create([
            'company_name' => 'IGLIKEFOLLOW', 'home_h1' => '標題',
            'primary_cta_label' => 'CTA',
            // 直接寫進資料庫的惡意值。
            'primary_cta_route' => 'https://evil.example.com',
        ]);

        $this->assertSame(route('home').'#platforms', $settings->ctaUrl());
        $this->assertStringNotContainsString('evil.example.com', $this->get('/')->getContent());
    }

    public function test_site_settings_can_be_saved_through_the_admin_page(): void
    {
        $this->actingAsOwner();
        $platform = Platform::factory()->published()->create(['slug' => 'instagram', 'name' => 'Instagram']);

        Livewire::test(ManageSiteSettings::class)
            ->fillForm([
                'company_name' => 'IGLIKEFOLLOW',
                'home_h1' => '多平台社群服務',
                'home_intro' => '介紹文字',
                'primary_cta_label' => '選擇平台服務',
                'primary_cta_route' => 'platform',
                'primary_cta_platform_id' => $platform->id,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('site_settings', [
            'company_name' => 'IGLIKEFOLLOW',
            'primary_cta_route' => 'platform',
            'primary_cta_platform_id' => $platform->id,
        ]);
    }

    public function test_choosing_a_platform_cta_without_a_target_is_rejected(): void
    {
        $this->actingAsOwner();

        Livewire::test(ManageSiteSettings::class)
            ->fillForm([
                'company_name' => 'IGLIKEFOLLOW', 'home_h1' => '標題',
                'primary_cta_route' => 'platform',
                'primary_cta_platform_id' => null,
            ])
            ->call('save')
            ->assertHasFormErrors(['primary_cta_platform_id']);
    }

    public function test_site_settings_page_is_owner_only(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'editor', 'is_active' => true]));

        $this->assertFalse(ManageSiteSettings::canAccess());
    }

    // ================================================================ 5. 後台草稿預覽入口

    public function test_platform_edit_screen_offers_a_preview_action(): void
    {
        $this->actingAsOwner();
        $platform = Platform::factory()->create(['slug' => 'instagram', 'status' => 'draft']);

        Livewire::test(EditPlatform::class, ['record' => $platform->getRouteKey()])
            ->assertActionExists('preview')
            ->assertActionHasUrl('preview', route('platform', 'instagram').'?preview=1');
    }

    public function test_service_edit_screen_offers_a_preview_action(): void
    {
        $this->actingAsOwner();
        $platform = Platform::factory()->create(['slug' => 'instagram']);
        $service = Service::factory()->create(['platform_id' => $platform->id, 'slug' => 'followers', 'status' => 'draft']);

        Livewire::test(EditService::class, ['record' => $service->getRouteKey()])
            ->assertActionExists('preview')
            ->assertActionHasUrl('preview', route('service', ['instagram', 'followers']).'?preview=1');
    }

    public function test_the_preview_action_opens_in_a_new_tab(): void
    {
        $this->actingAsOwner();
        $platform = Platform::factory()->create(['slug' => 'instagram', 'status' => 'draft']);

        Livewire::test(EditPlatform::class, ['record' => $platform->getRouteKey()])
            ->assertActionShouldOpenUrlInNewTab('preview');
    }

    public function test_an_admin_can_open_the_draft_preview_that_the_action_links_to(): void
    {
        $this->actingAsOwner();
        $platform = Platform::factory()->create(['slug' => 'instagram', 'name' => 'Instagram', 'status' => 'draft']);

        $response = $this->get(route('platform', $platform->slug).'?preview=1')->assertOk();

        // 預覽永遠 noindex，header 與 HTML meta 都要有。
        $response->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->assertStringContainsString('<meta name="robots" content="noindex, nofollow">', $response->getContent());
    }

    public function test_a_guest_following_the_same_preview_url_still_gets_a_404(): void
    {
        $platform = Platform::factory()->create(['slug' => 'instagram', 'status' => 'draft']);

        // ⛔ ?preview=1 在訪客手上必須無效。
        $this->get(route('platform', $platform->slug).'?preview=1')->assertNotFound();
    }

    // ================================================================ 6. 完整後台流程

    public function test_a_full_admin_flow_from_creation_to_a_live_front_end_page(): void
    {
        $this->actingAsOwner();
        Storage::fake('public');

        $platform = Platform::factory()->published()->create(['slug' => 'instagram', 'name' => 'Instagram']);

        // 1. 建立服務（草稿）。
        Livewire::test(CreateService::class)
            ->fillForm([
                'platform_id' => $platform->id,
                'name' => 'Instagram 粉絲',
                'slug' => 'followers',
                'summary' => '增加帳號整體粉絲數。',
                'input_kind' => 'account',
                'input_label' => 'Instagram 帳號',
                'status' => 'draft',
                'sort_order' => 0,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $service = Service::where('slug', 'followers')->firstOrFail();

        // 草稿階段前台看不到。
        $this->get('/services/instagram/followers')->assertNotFound();

        // 2. 在服務底下建立款式、內容段落與 FAQ。
        $this->relationManager(VariantsRelationManager::class, $service)
            ->callTableAction('create', data: [
                'label' => '一般粉絲', 'unit_price' => 0.59, 'quantity_unit' => '個',
                'min_quantity' => 100, 'max_quantity' => 10000, 'step_quantity' => 100,
                'default_quantity' => 1000, 'currency' => 'TWD',
                'status' => 'published', 'sort_order' => 0,
            ])
            ->assertHasNoTableActionErrors();

        $this->relationManager(ContentSectionsRelationManager::class, $service)
            ->callTableAction('create', data: [
                'heading' => '購買前須知', 'body' => '請確認帳號為公開狀態。',
                'status' => 'published', 'sort_order' => 0,
            ])
            ->assertHasNoTableActionErrors();

        $this->relationManager(FaqsRelationManager::class, $service)
            ->callTableAction('create', data: [
                'question' => '多久會開始？', 'answer' => '確認付款後開始。',
                'status' => 'published', 'sort_order' => 0,
            ])
            ->assertHasNoTableActionErrors();

        // 3. 發布服務。
        Livewire::test(EditService::class, ['record' => $service->getRouteKey()])
            ->fillForm(['status' => 'published'])
            ->call('save')
            ->assertHasNoFormErrors();

        // 4. 前台初始 HTML 就要看得到，⛔ 不依賴 JavaScript 補畫。
        $html = $this->get('/services/instagram/followers')->assertOk()->getContent();

        $this->assertStringContainsString('Instagram 粉絲', $html);
        $this->assertStringContainsString('一般粉絲', $html);
        $this->assertStringContainsString('購買前須知', $html);
        $this->assertStringContainsString('多久會開始？', $html);

        // 全程不得產生任何訂單。
        $this->assertSame(0, ServiceVariant::whereNull('service_id')->count());
        $this->assertDatabaseCount('service_variants', 1);
    }

    public function test_the_published_service_is_reachable_from_the_platform_hub(): void
    {
        $this->actingAsOwner();

        $platform = Platform::factory()->published()->create(['slug' => 'instagram', 'name' => 'Instagram']);
        $service = Service::factory()->create(['platform_id' => $platform->id, 'slug' => 'followers', 'status' => 'draft']);

        $this->get('/services/instagram')->assertOk()->assertDontSee('/services/instagram/followers', false);

        $service->update(['status' => 'published']);

        $this->get('/services/instagram')->assertOk()->assertSee('/services/instagram/followers', false);
    }

    /**
     * Field names present in a form definition.
     *
     * @param  callable(Schema): Schema  $build
     * @return list<string>
     */
    private function componentNames(callable $build): array
    {
        $schema = $build(Schema::make(Livewire::new(EditService::class)));

        $names = [];

        $walk = function ($components) use (&$walk, &$names): void {
            foreach ($components as $component) {
                if (method_exists($component, 'getName')) {
                    $names[] = $component->getName();
                }

                if (method_exists($component, 'getDefaultChildComponents')) {
                    $walk($component->getDefaultChildComponents());
                }
            }
        };

        $walk($schema->getComponents());

        return $names;
    }
}
