<?php

namespace Tests\Feature\Fulfillment;

use App\Actions\Fulfillment\ApplyProviderBoundsToVariant;
use App\Actions\Fulfillment\ApplyProviderServiceCatalogSnapshot;
use App\Filament\Resources\FulfillmentMappings\Pages\CreateFulfillmentMapping;
use App\Filament\Resources\FulfillmentMappings\Pages\EditFulfillmentMapping;
use App\Models\AdminAuditLog;
use App\Models\FulfillmentMapping;
use App\Models\ProviderService;
use App\Models\ServiceVariant;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Applying provider catalog min/max as the variant's quantity defaults — an
 * explicit, non-persisted Owner option on the mapping form.
 *
 * ⛔ Create defaults the option ON, edit defaults it OFF; applying and
 * enabling never travel in one submit; the provider row is re-queried
 * available-only at apply time; and mapping + variant move in one
 * transaction or not at all. Fixtures are fictional; no request leaves the
 * process.
 */
class ProviderBoundsAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $this->actingAs(User::factory()->create(['role' => 'owner', 'is_active' => true]));
    }

    private function availableService(string $id, string $min, string $max): ProviderService
    {
        return ProviderService::factory()->available()->create([
            'provider_service_id' => $id,
            'name' => '虛構可用服務 '.$id,
            'minimum_quantity_raw' => $min,
            'maximum_quantity_raw' => $max,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function draftVariant(array $overrides = []): ServiceVariant
    {
        return ServiceVariant::factory()->create(array_merge([
            'min_quantity' => 100,
            'max_quantity' => 10000,
            'step_quantity' => 100,
            'default_quantity' => 1000,
        ], $overrides));
    }

    public function test_create_defaults_the_apply_option_on_and_applies_bounds_while_mapping_stays_disabled(): void
    {
        $this->availableService('424242', '50', '5000');
        $variant = $this->draftVariant();
        $before = $variant->fresh()->getAttributes();

        Livewire::test(CreateFulfillmentMapping::class)
            ->assertSchemaStateSet(['apply_provider_bounds' => true])
            ->fillForm([
                'service_variant_id' => $variant->id,
                'provider_service_id' => '424242',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $variant->refresh();
        $this->assertSame(50, (int) $variant->min_quantity);
        $this->assertSame(5000, (int) $variant->max_quantity);
        // 1000 仍符合新範圍與原 step:保留。
        $this->assertSame(1000, (int) $variant->default_quantity);

        // ⛔ step、價格、SKU、狀態一律不動;mapping 保持停用。
        $this->assertSame($before['step_quantity'], $variant->getAttributes()['step_quantity']);
        $this->assertSame($before['unit_price'], $variant->getAttributes()['unit_price']);
        $this->assertSame($before['sku'], $variant->getAttributes()['sku']);
        $this->assertSame($before['status'], $variant->getAttributes()['status']);
        $this->assertDatabaseHas('fulfillment_mappings', [
            'service_variant_id' => $variant->id,
            'provider_service_id' => '424242',
            'is_enabled' => false,
        ]);
    }

    public function test_create_with_the_option_unchecked_leaves_the_variant_alone(): void
    {
        $this->availableService('424242', '50', '5000');
        $variant = $this->draftVariant();
        $before = $variant->fresh()->getAttributes();

        Livewire::test(CreateFulfillmentMapping::class)
            ->fillForm([
                'service_variant_id' => $variant->id,
                'provider_service_id' => '424242',
                'apply_provider_bounds' => false,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame($before, $variant->fresh()->getAttributes());
        $this->assertSame(1, FulfillmentMapping::query()->count());
    }

    public function test_a_provider_minimum_of_zero_becomes_a_site_minimum_of_one(): void
    {
        $this->availableService('424242', '0', '5000');
        $variant = $this->draftVariant();

        Livewire::test(CreateFulfillmentMapping::class)
            ->fillForm([
                'service_variant_id' => $variant->id,
                'provider_service_id' => '424242',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(1, (int) $variant->fresh()->min_quantity);
        $this->assertSame(5000, (int) $variant->fresh()->max_quantity);
    }

    /** ⛔ M3A:default 跑出新範圍時調整為新的 min,不再對齊倍數。 */
    public function test_an_out_of_range_default_is_adjusted_to_the_new_minimum(): void
    {
        $this->availableService('424242', '2050', '9000');
        $variant = $this->draftVariant();
        $stepBefore = (int) $variant->step_quantity;

        Livewire::test(CreateFulfillmentMapping::class)
            ->fillForm([
                'service_variant_id' => $variant->id,
                'provider_service_id' => '424242',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $variant->refresh();
        $this->assertSame(2050, (int) $variant->min_quantity);
        // ⛔ 直接用新 min,不再抬高到 2100。
        $this->assertSame(2050, (int) $variant->default_quantity);
        // ⛔ legacy step 欄位仍完全不被此動作改動。
        $this->assertSame($stepBefore, (int) $variant->step_quantity);
    }

    public function test_apply_and_enable_in_the_same_submit_is_rejected_whole(): void
    {
        $this->availableService('424242', '10', '100000');
        $variant = $this->draftVariant();
        $before = $variant->fresh()->getAttributes();

        Livewire::test(CreateFulfillmentMapping::class)
            ->fillForm([
                'service_variant_id' => $variant->id,
                'provider_service_id' => '424242',
                'is_enabled' => true,
                'apply_provider_bounds' => true,
            ])
            ->call('create')
            ->assertHasFormErrors(['apply_provider_bounds']);

        // ⛔ 整筆拒絕:mapping 未建立,variant 未變。
        $this->assertSame(0, FulfillmentMapping::query()->count());
        $this->assertSame($before, $variant->fresh()->getAttributes());
    }

    public function test_edit_defaults_the_apply_option_off_and_does_not_touch_the_variant(): void
    {
        $this->availableService('424242', '50', '5000');
        $variant = $this->draftVariant();
        $mapping = FulfillmentMapping::factory()->create([
            'service_variant_id' => $variant->id,
            'provider_service_id' => '424242',
            'is_enabled' => false,
        ]);
        $before = $variant->fresh()->getAttributes();

        Livewire::test(EditFulfillmentMapping::class, ['record' => $mapping->id])
            ->assertSchemaStateSet(['apply_provider_bounds' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        // 沒有明確勾選就沒有套用。
        $this->assertSame($before, $variant->fresh()->getAttributes());
    }

    public function test_edit_applies_only_when_the_owner_explicitly_opts_in(): void
    {
        $this->availableService('424242', '50', '5000');
        $variant = $this->draftVariant();
        $mapping = FulfillmentMapping::factory()->create([
            'service_variant_id' => $variant->id,
            'provider_service_id' => '424242',
            'is_enabled' => false,
        ]);

        Livewire::test(EditFulfillmentMapping::class, ['record' => $mapping->id])
            ->fillForm(['apply_provider_bounds' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $variant->refresh();
        $this->assertSame(50, (int) $variant->min_quantity);
        $this->assertSame(5000, (int) $variant->max_quantity);
        $this->assertFalse($mapping->fresh()->is_enabled);
    }

    public function test_edit_rejects_apply_together_with_enable(): void
    {
        $this->availableService('424242', '10', '100000');
        $variant = $this->draftVariant();
        $mapping = FulfillmentMapping::factory()->create([
            'service_variant_id' => $variant->id,
            'provider_service_id' => '424242',
            'is_enabled' => false,
        ]);
        $before = $variant->fresh()->getAttributes();

        Livewire::test(EditFulfillmentMapping::class, ['record' => $mapping->id])
            ->fillForm([
                'is_enabled' => true,
                'apply_provider_bounds' => true,
            ])
            ->call('save')
            ->assertHasFormErrors(['apply_provider_bounds']);

        $this->assertFalse($mapping->fresh()->is_enabled);
        $this->assertSame($before, $variant->fresh()->getAttributes());
    }

    public function test_a_stale_unavailable_service_blocks_apply_and_rolls_the_edit_back(): void
    {
        // 停用中的 stale mapping 可保留舊 ID(草稿語意)……
        $variant = $this->draftVariant();
        $mapping = FulfillmentMapping::factory()->create([
            'service_variant_id' => $variant->id,
            'provider_service_id' => 'OLD-GONE-0001',
            'is_enabled' => false,
        ]);
        $before = $variant->fresh()->getAttributes();

        // ……但套用必須以 available=true 重查,stale 一律 fail closed。
        Livewire::test(EditFulfillmentMapping::class, ['record' => $mapping->id])
            ->fillForm(['apply_provider_bounds' => true])
            ->call('save')
            ->assertHasFormErrors(['apply_provider_bounds']);

        $this->assertSame($before, $variant->fresh()->getAttributes());
    }

    public function test_a_tampered_unknown_service_id_is_rejected_at_submit_even_with_apply_on(): void
    {
        $this->availableService('424242', '50', '5000');
        $variant = $this->draftVariant();
        $before = $variant->fresh()->getAttributes();

        Livewire::test(CreateFulfillmentMapping::class)
            ->fillForm([
                'service_variant_id' => $variant->id,
                // ⛔ 選單被繞過,注入不存在的代碼。
                'provider_service_id' => '999999',
                'apply_provider_bounds' => true,
            ])
            ->call('create')
            ->assertHasFormErrors(['provider_service_id']);

        $this->assertSame(0, FulfillmentMapping::query()->count());
        $this->assertSame($before, $variant->fresh()->getAttributes());
    }

    public function test_malformed_provider_bounds_fail_closed_without_echoing_the_raw_value(): void
    {
        // available 但 bounds 是垃圾(前導零):套用必須拒絕且不回顯原值。
        $this->availableService('424242', '007', '5000');
        $variant = $this->draftVariant();
        $before = $variant->fresh()->getAttributes();

        $page = Livewire::test(CreateFulfillmentMapping::class)
            ->fillForm([
                'service_variant_id' => $variant->id,
                'provider_service_id' => '424242',
            ])
            ->call('create')
            ->assertHasFormErrors(['apply_provider_bounds']);

        $errors = $page->instance()->getErrorBag()->all();
        $this->assertStringNotContainsString('007', implode(' ', $errors));

        $this->assertSame(0, FulfillmentMapping::query()->count());
        $this->assertSame($before, $variant->fresh()->getAttributes());
    }

    /**
     * ⛔ M3A:窄範圍現在可以套用——範圍內每個整數都買得到。
     *
     * 原測試主張 [101,199] 必須整筆拒絕(沒有 100 的倍數)。那是 legacy step
     * 造成的假性錯誤:供應商願意接的範圍被我們自己拒收。
     */
    public function test_a_narrow_range_is_now_applied_instead_of_rejected(): void
    {
        $this->availableService('424242', '101', '199');
        $variant = $this->draftVariant();
        $stepBefore = (int) $variant->step_quantity;

        Livewire::test(CreateFulfillmentMapping::class)
            ->fillForm([
                'service_variant_id' => $variant->id,
                'provider_service_id' => '424242',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $variant->refresh();
        $this->assertSame(101, (int) $variant->min_quantity);
        $this->assertSame(199, (int) $variant->max_quantity);
        $this->assertSame(101, (int) $variant->default_quantity);
        // ⛔ legacy step 欄位不被改動。
        $this->assertSame($stepBefore, (int) $variant->step_quantity);
    }

    public function test_an_observer_rejection_rolls_back_mapping_and_variant_together(): void
    {
        /*
         * ⛔ M3A:小數台幣已不是拒絕理由(改為 half-up),所以原本的
         * 「0.0001×5000 不是整數」不再會觸發 observer。四捨五入救不了的
         * 只剩「金額不足 1 元」:
         *
         * 已發布款式,單價 0.0001、最低 10000 → 1 元,剛好可售。套用後
         * 範圍 [1,15000] 會讓最低量變成 1 → 0.0001 元,四捨五入為 0
         * → VariantIntegrityObserver 拒絕,⛔ mapping 與 variant 必須
         * 一起 rollback。
         */
        $this->availableService('424242', '1', '15000');
        $variant = ServiceVariant::factory()->create([
            'min_quantity' => 10000,
            'max_quantity' => 10000,
            'step_quantity' => 1,
            'default_quantity' => 10000,
            'unit_price' => '0.0001',
            'status' => 'published',
        ]);
        $before = $variant->fresh()->getAttributes();

        Livewire::test(CreateFulfillmentMapping::class)
            ->fillForm([
                'service_variant_id' => $variant->id,
                'provider_service_id' => '424242',
            ])
            ->call('create');

        // ⛔ 不留半套:mapping 未建立,variant 完全未變。
        $this->assertSame(0, FulfillmentMapping::query()->count());
        $this->assertSame($before, $variant->fresh()->getAttributes());
    }

    public function test_a_catalog_refresh_never_touches_any_variant(): void
    {
        $variant = $this->draftVariant();
        $before = $variant->fresh()->getAttributes();

        $item = static fn (string $min, string $max): array => [
            'service' => 424242,
            'name' => '虛構服務 424242',
            'type' => 'Default',
            'category' => '虛構分類',
            'rate' => '0.90',
            'min' => $min,
            'max' => $max,
            'refill' => false,
            'cancel' => false,
        ];

        app(ApplyProviderServiceCatalogSnapshot::class)(
            json_encode([$item('10', '10000')]),
            new DateTimeImmutable('2026-08-17 12:00:00'),
        );
        app(ApplyProviderServiceCatalogSnapshot::class)(
            json_encode([$item('500', '99999')]),
            new DateTimeImmutable('2026-08-17 13:00:00'),
        );

        // ⛔ catalog 是觀察,不是同步規則:variant 一個位元都不能動。
        $this->assertSame('500', ProviderService::query()->where('provider_service_id', '424242')->value('minimum_quantity_raw'));
        $this->assertSame($before, $variant->fresh()->getAttributes());
    }

    public function test_the_audit_trail_records_the_change_without_the_provider_service_id(): void
    {
        $this->availableService('424242', '50', '5000');
        $variant = $this->draftVariant();

        Livewire::test(CreateFulfillmentMapping::class)
            ->fillForm([
                'service_variant_id' => $variant->id,
                'provider_service_id' => '424242',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $logs = AdminAuditLog::query()->get();

        // 有 mapping 建立與 variant 更新的稽核……
        $this->assertTrue($logs->contains(fn (AdminAuditLog $log) => $log->auditable_type === FulfillmentMapping::class));
        $this->assertTrue($logs->contains(fn (AdminAuditLog $log) => $log->auditable_type === ServiceVariant::class));

        // ……但任何一筆都不含供應商服務代碼(raw payload 不擴散)。
        $this->assertStringNotContainsString('424242', json_encode($logs->toArray()));
    }

    public function test_an_editor_cannot_reach_the_mapping_pages_at_all(): void
    {
        $editor = User::factory()->create(['role' => 'editor', 'is_active' => true]);

        $this->actingAs($editor)->get('/admin/fulfillment-mappings/create')->assertForbidden();
        $this->actingAs($editor)->get('/admin/fulfillment-mappings')->assertForbidden();
    }

    public function test_the_action_itself_refuses_a_non_owner_actor(): void
    {
        $this->availableService('424242', '50', '5000');
        $variant = $this->draftVariant();
        $editor = User::factory()->create(['role' => 'editor', 'is_active' => true]);

        try {
            app(ApplyProviderBoundsToVariant::class)->apply($variant, '424242', $editor);
            $this->fail('editor 不得套用供應商上下限。');
        } catch (ValidationException) {
            // fail closed as expected
        }

        try {
            app(ApplyProviderBoundsToVariant::class)->apply($variant, '424242', null);
            $this->fail('匿名不得套用供應商上下限。');
        } catch (ValidationException) {
            // fail closed as expected
        }

        $this->assertSame(100, (int) $variant->fresh()->min_quantity);
    }
}
