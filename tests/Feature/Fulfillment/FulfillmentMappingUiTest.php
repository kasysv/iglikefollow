<?php

namespace Tests\Feature\Fulfillment;

use App\Enums\FulfillmentPayloadType;
use App\Enums\IntegrationProvider;
use App\Filament\Resources\FulfillmentMappings\Pages\CreateFulfillmentMapping;
use App\Filament\Resources\FulfillmentMappings\Pages\EditFulfillmentMapping;
use App\Models\FulfillmentMapping;
use App\Models\ProviderService;
use App\Models\ServiceVariant;
use App\Models\User;
use App\Rules\AvailableProviderService;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The mapping form after MAPPING-UI-A: the provider service ID is chosen from
 * the observed available catalog, and the server re-checks at submit time.
 *
 * ⛔ The menu is convenience; the rule is the boundary. Every test here proves
 * the boundary holds when the menu is bypassed, raced or fed a stale value.
 * Fixtures are fictional; no request leaves the process.
 */
class FulfillmentMappingUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $this->actingAs(User::factory()->create(['role' => 'owner', 'is_active' => true]));
    }

    private function availableService(string $id): ProviderService
    {
        return ProviderService::factory()->available()->create([
            'provider_service_id' => $id,
            'name' => '虛構可用服務 '.$id,
        ]);
    }

    public function test_a_mapping_is_created_from_an_available_catalog_row(): void
    {
        $this->availableService('91011');
        $variant = ServiceVariant::factory()->create();

        Livewire::test(CreateFulfillmentMapping::class)
            ->fillForm([
                'service_variant_id' => $variant->id,
                'provider_service_id' => '91011',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('fulfillment_mappings', [
            'service_variant_id' => $variant->id,
            'provider' => IntegrationProvider::TheMostPanel->value,
            'provider_service_id' => '91011',
            'payload_type' => FulfillmentPayloadType::LinkQuantity->value,
            // ⛔ 預設不啟用:選好 mapping 不等於允許派單。
            'is_enabled' => false,
        ]);
    }

    public function test_an_unknown_service_id_is_rejected_at_submit(): void
    {
        $this->availableService('91011');
        $variant = ServiceVariant::factory()->create();

        // ⛔ 選單被繞過(直接注入 state):server-side rule 仍然要擋。
        Livewire::test(CreateFulfillmentMapping::class)
            ->fillForm([
                'service_variant_id' => $variant->id,
                'provider_service_id' => 'NOT-IN-CATALOG-999',
            ])
            ->call('create')
            ->assertHasFormErrors(['provider_service_id']);

        $this->assertSame(0, FulfillmentMapping::query()->count());
    }

    public function test_an_unavailable_service_id_is_rejected_at_submit(): void
    {
        // 存在但 unavailable:等同不可選。
        ProviderService::factory()->create(['provider_service_id' => '93033']);
        $variant = ServiceVariant::factory()->create();

        Livewire::test(CreateFulfillmentMapping::class)
            ->fillForm([
                'service_variant_id' => $variant->id,
                'provider_service_id' => '93033',
            ])
            ->call('create')
            ->assertHasFormErrors(['provider_service_id']);

        $this->assertSame(0, FulfillmentMapping::query()->count());
    }

    /** ⛔ submit-time race:選單渲染後、送出前該列變 unavailable,仍須拒絕。 */
    public function test_a_row_going_unavailable_between_menu_and_submit_is_rejected(): void
    {
        $service = $this->availableService('94044');
        $variant = ServiceVariant::factory()->create();

        $page = Livewire::test(CreateFulfillmentMapping::class)
            ->fillForm([
                'service_variant_id' => $variant->id,
                'provider_service_id' => '94044',
            ]);

        // 選單已經看過它;現在下一個 snapshot 把它標成 unavailable。
        $service->update(['is_available' => false]);

        $page->call('create')->assertHasFormErrors(['provider_service_id']);

        $this->assertSame(0, FulfillmentMapping::query()->count());
    }

    /** stale mapping 可以停用保留舊值——歷史必須可理解。 */
    public function test_editing_a_stale_mapping_may_keep_the_id_while_disabled(): void
    {
        $mapping = FulfillmentMapping::factory()->create([
            'provider_service_id' => 'OLD-GONE-0001',
            'is_enabled' => false,
        ]);

        Livewire::test(EditFulfillmentMapping::class, ['record' => $mapping->id])
            ->fillForm(['is_enabled' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('OLD-GONE-0001', $mapping->fresh()->provider_service_id);
        $this->assertFalse($mapping->fresh()->is_enabled);
    }

    /** ⛔ 失效舊 ID 不得重新啟用:啟用必須換成 available ID。 */
    public function test_a_stale_id_cannot_be_re_enabled(): void
    {
        $mapping = FulfillmentMapping::factory()->create([
            'provider_service_id' => 'OLD-GONE-0002',
            'is_enabled' => false,
        ]);

        Livewire::test(EditFulfillmentMapping::class, ['record' => $mapping->id])
            ->fillForm(['is_enabled' => true])
            ->call('save')
            ->assertHasFormErrors(['provider_service_id']);

        $this->assertFalse($mapping->fresh()->is_enabled);
    }

    public function test_a_stale_mapping_can_switch_to_an_available_id(): void
    {
        $this->availableService('95055');

        $mapping = FulfillmentMapping::factory()->create([
            'provider_service_id' => 'OLD-GONE-0003',
            'is_enabled' => false,
        ]);

        Livewire::test(EditFulfillmentMapping::class, ['record' => $mapping->id])
            ->fillForm(['provider_service_id' => '95055', 'is_enabled' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $fresh = $mapping->fresh();
        $this->assertSame('95055', $fresh->provider_service_id);
        $this->assertTrue($fresh->is_enabled);
    }

    /** ⛔ 換到另一個「不在目錄」的 ID 也不行:可保留的只有原值且僅限停用。 */
    public function test_switching_to_a_different_unknown_id_is_rejected(): void
    {
        $mapping = FulfillmentMapping::factory()->create([
            'provider_service_id' => 'OLD-GONE-0004',
            'is_enabled' => false,
        ]);

        Livewire::test(EditFulfillmentMapping::class, ['record' => $mapping->id])
            ->fillForm(['provider_service_id' => 'ANOTHER-GONE-0005', 'is_enabled' => false])
            ->call('save')
            ->assertHasFormErrors(['provider_service_id']);

        $this->assertSame('OLD-GONE-0004', $mapping->fresh()->provider_service_id);
    }

    /** ⛔ provider 是固定值:即使 state 被竄改也不能存成別的供應商。 */
    public function test_the_provider_cannot_be_changed_by_the_client(): void
    {
        $this->availableService('96066');
        $variant = ServiceVariant::factory()->create();

        $page = Livewire::test(CreateFulfillmentMapping::class)
            ->fillForm([
                'service_variant_id' => $variant->id,
                'provider_service_id' => '96066',
                'provider' => 'evil-panel',
            ])
            ->call('create');

        // disabled+dehydrated+in-rule:結果只能是「存成 themostpanel」或「驗證失敗」。
        $stored = FulfillmentMapping::query()->first();

        if ($stored !== null) {
            $this->assertSame(IntegrationProvider::TheMostPanel->value, $stored->provider);
        } else {
            $page->assertHasFormErrors();
        }
    }

    // ==================================== R1:shape 先行的 fail-closed 邊界

    /**
     * ⛔ GPT 反例:array state 曾讓 rule 的 `(string)` cast 產生
     * `Array to string conversion` warning。R1 之後 shape 先行:非 string
     * 與非 canonical 形狀在 cast／regex／DB query 之前就以固定訊息拒絕。
     *
     * @return array<string, array{0: mixed}>
     */
    public static function malformedShapeProvider(): array
    {
        return [
            'array' => [['91011']],
            'nested array' => [[['deep' => '91011']]],
            'object' => [(object) ['id' => '91011']],
            'null' => [null],
            'bool true' => [true],
            'bool false' => [false],
            'int' => [91011],
            'float' => [910.11],
            'overlong string' => [str_repeat('9', 65)],
            'zero' => ['0'],
            'leading zeros' => ['00791011'],
            'decimal string' => ['9.5'],
            'signed string' => ['-91011'],
            'alpha string' => ['NOT-IN-CATALOG'],
            'embedded space' => ['9101 1'],
        ];
    }

    #[DataProvider('malformedShapeProvider')]
    public function test_a_malformed_shape_fails_closed_without_warnings_or_queries(mixed $value): void
    {
        // catalog 有資料,證明「不查 DB」是 shape gate 的效果,不是空庫巧合。
        $this->availableService('91011');

        $catalogQueries = 0;
        $watching = true;

        DB::listen(function ($query) use (&$catalogQueries, &$watching) {
            if ($watching && str_contains($query->sql, 'provider_services')) {
                $catalogQueries++;
            }
        });

        $failedWith = null;

        // ⛔ 任何 PHP warning／notice 都直接讓測試失敗。
        set_error_handler(function (int $errno, string $errstr): bool {
            $this->fail('⛔ 不可信輸入產生了 PHP error:'.$errstr);
        });

        try {
            (new AvailableProviderService)->validate(
                'provider_service_id',
                $value,
                function (string $message) use (&$failedWith) {
                    $failedWith = $message;

                    return new class
                    {
                        public function __call(string $name, array $arguments): static
                        {
                            return $this;
                        }
                    };
                },
            );
        } finally {
            restore_error_handler();
            $watching = false;
        }

        $this->assertSame(AvailableProviderService::FAILED_MESSAGE, $failedWith);
        // ⛔ 不合法 shape 永不觸碰 provider_services。
        $this->assertSame(0, $catalogQueries);
    }

    /** ⛔ GPT 原反例的 end-to-end 版本:array state 進 form → error、0 mapping、無 warning。 */
    public function test_an_array_state_is_rejected_at_submit(): void
    {
        $this->availableService('91011');
        $variant = ServiceVariant::factory()->create();

        Livewire::test(CreateFulfillmentMapping::class)
            ->fillForm([
                'service_variant_id' => $variant->id,
                'provider_service_id' => ['91011'],
            ])
            ->call('create')
            ->assertHasFormErrors(['provider_service_id']);

        $this->assertSame(0, FulfillmentMapping::query()->count());
    }

    /** 歷史保留例外不查 DB:exact 相等的舊 string(即使非 canonical)直接通過。 */
    public function test_the_retainable_stale_id_passes_without_touching_the_catalog(): void
    {
        $catalogQueries = 0;
        $watching = true;

        DB::listen(function ($query) use (&$catalogQueries, &$watching) {
            if ($watching && str_contains($query->sql, 'provider_services')) {
                $catalogQueries++;
            }
        });

        $failedWith = null;

        (new AvailableProviderService('OLD-GONE-0001'))->validate(
            'provider_service_id',
            'OLD-GONE-0001',
            function (string $message) use (&$failedWith) {
                $failedWith = $message;
            },
        );
        $watching = false;

        $this->assertNull($failedWith);
        $this->assertSame(0, $catalogQueries);
    }

    /** stale 舊值必須在編輯表單的選項中看得見(帶失效附註),不是憑空消失。 */
    public function test_the_edit_form_shows_the_stale_id_with_a_stale_note(): void
    {
        $this->availableService('97077');

        $mapping = FulfillmentMapping::factory()->create([
            'provider_service_id' => 'OLD-GONE-0006',
            'is_enabled' => false,
        ]);

        // searchable select 的選項由 Livewire lazy 載入,不在初始 HTML;
        // 直接驗證 form component 解析出的 options。
        /** @var Select $select */
        $select = Livewire::test(EditFulfillmentMapping::class, ['record' => $mapping->id])
            ->instance()
            ->getSchema('form')
            ->getComponent(
                fn ($component) => method_exists($component, 'getName')
                    && $component->getName() === 'provider_service_id'
            );

        $options = $select->getOptions();

        // available row 正常列出;stale 舊值帶失效附註,而不是消失。
        $this->assertArrayHasKey('97077', $options);
        $this->assertArrayHasKey('OLD-GONE-0006', $options);
        $this->assertStringContainsString('已不在可用目錄', $options['OLD-GONE-0006']);
    }
}
