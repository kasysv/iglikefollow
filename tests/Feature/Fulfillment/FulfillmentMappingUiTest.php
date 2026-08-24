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
use Illuminate\Validation\ValidationException;
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

    // ==================================== UI-B:數量相容性 enable guard

    /** boundary equal:供應商範圍恰好等於本站實際可購範圍 → 可啟用。 */
    public function test_enabling_with_exactly_matching_bounds_succeeds(): void
    {
        // variant factory:min 100/max 10000/step 100 → 實際 100–10000。
        ProviderService::factory()->available()->create([
            'provider_service_id' => '81011',
            'minimum_quantity_raw' => '100',
            'maximum_quantity_raw' => '10000',
        ]);
        $variant = ServiceVariant::factory()->create();

        Livewire::test(CreateFulfillmentMapping::class)
            ->fillForm([
                'service_variant_id' => $variant->id,
                'provider_service_id' => '81011',
                'is_enabled' => true,
                // M2B:create 預設開啟「套用上下限」;此測試專測啟用路徑,明確關閉套用。
                'apply_provider_bounds' => false,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertTrue(FulfillmentMapping::query()->sole()->is_enabled);
    }

    /** ⛔ 供應商最低量高於本站實際最低可購量:不得啟用;停用草稿可保存。 */
    public function test_a_provider_minimum_above_ours_blocks_enabling_but_allows_a_disabled_draft(): void
    {
        ProviderService::factory()->available()->create([
            'provider_service_id' => '82022',
            'minimum_quantity_raw' => '150',
            'maximum_quantity_raw' => '20000',
        ]);
        $variant = ServiceVariant::factory()->create();

        Livewire::test(CreateFulfillmentMapping::class)
            ->fillForm([
                'service_variant_id' => $variant->id,
                'provider_service_id' => '82022',
                'is_enabled' => true,
            ])
            ->call('create')
            ->assertHasFormErrors(['provider_service_id']);

        $this->assertSame(0, FulfillmentMapping::query()->count());

        // 同一組合,停用 → 草稿可保存。
        Livewire::test(CreateFulfillmentMapping::class)
            ->fillForm([
                'service_variant_id' => $variant->id,
                'provider_service_id' => '82022',
                'is_enabled' => false,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertFalse(FulfillmentMapping::query()->sole()->is_enabled);
    }

    /** ⛔ 供應商最高量低於本站實際最高可購量:不得啟用。 */
    public function test_a_provider_maximum_below_ours_blocks_enabling(): void
    {
        ProviderService::factory()->available()->create([
            'provider_service_id' => '83033',
            'minimum_quantity_raw' => '10',
            'maximum_quantity_raw' => '9999',
        ]);
        $variant = ServiceVariant::factory()->create();

        Livewire::test(CreateFulfillmentMapping::class)
            ->fillForm([
                'service_variant_id' => $variant->id,
                'provider_service_id' => '83033',
                'is_enabled' => true,
            ])
            ->call('create')
            ->assertHasFormErrors(['provider_service_id']);

        $this->assertSame(0, FulfillmentMapping::query()->count());
    }

    /** ⛔ 本站範圍內沒有任何可購數量:無論供應商多寬都不得啟用。 */
    public function test_a_variant_with_no_purchasable_quantity_cannot_be_enabled(): void
    {
        ProviderService::factory()->available()->create([
            'provider_service_id' => '84044',
            'minimum_quantity_raw' => '1',
            'maximum_quantity_raw' => '999999',
        ]);
        /*
         * ⛔ M3A:「沒有可購數量」現在只剩空範圍(max < min)一種——範圍內
         * 每個整數都買得到,不再有「區間內沒有 step 倍數」這種情況。
         * VariantIntegrityObserver 已讓這種款式無法正常保存——⛔ 這裡以
         * withoutEvents 模擬 legacy／corrupt row,證明 guard 對「不該存在
         * 但存在」的資料仍 fail closed,不倚賴上游防線。
         */
        $variant = ServiceVariant::withoutEvents(fn () => ServiceVariant::factory()->create([
            'min_quantity' => 199, 'max_quantity' => 150, 'step_quantity' => 1,
            'default_quantity' => 150,
        ]));

        Livewire::test(CreateFulfillmentMapping::class)
            ->fillForm([
                'service_variant_id' => $variant->id,
                'provider_service_id' => '84044',
                'is_enabled' => true,
            ])
            ->call('create')
            ->assertHasFormErrors(['provider_service_id']);

        $this->assertSame(0, FulfillmentMapping::query()->count());
    }

    /**
     * ⛔ M3A:實際範圍就是 raw min/max,legacy step 不得再收窄它。
     *
     * 原測試主張 provider 150–9950 可以承接 min 101／max 9999 的款式
     * (因為 step 會把實際範圍縮成 200–9900)。那正是 Owner 回報缺陷的
     * 同一個根:那個承諾是假的,顧客其實買得到 101。現在必須不相容。
     */
    public function test_the_raw_min_and_max_are_used_not_step_derived_bounds(): void
    {
        ProviderService::factory()->available()->create([
            'provider_service_id' => '85055',
            'minimum_quantity_raw' => '150',
            'maximum_quantity_raw' => '9950',
        ]);
        $variant = ServiceVariant::factory()->create([
            'min_quantity' => 101, 'max_quantity' => 9999, 'step_quantity' => 100,
        ]);

        // provider 最低 150 > 本站實際最低 101 → ⛔ 不得啟用。
        Livewire::test(CreateFulfillmentMapping::class)
            ->fillForm([
                'service_variant_id' => $variant->id,
                'provider_service_id' => '85055',
                'is_enabled' => true,
                // M2B:create 預設開啟「套用上下限」;此測試專測啟用路徑,明確關閉套用。
                'apply_provider_bounds' => false,
            ])
            ->call('create')
            ->assertHasFormErrors(['provider_service_id']);

        $this->assertSame(0, FulfillmentMapping::query()->count());

        // 供應商真的涵蓋 raw 範圍時才可啟用。
        ProviderService::factory()->available()->create([
            'provider_service_id' => '85056',
            'minimum_quantity_raw' => '101',
            'maximum_quantity_raw' => '9999',
        ]);

        Livewire::test(CreateFulfillmentMapping::class)
            ->fillForm([
                'service_variant_id' => $variant->id,
                'provider_service_id' => '85056',
                'is_enabled' => true,
                'apply_provider_bounds' => false,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertTrue(FulfillmentMapping::query()->sole()->is_enabled);
    }

    /**
     * ⛔ R1/GPT end-to-end:step 0 corrupt row(繞過 observer)不得啟用,
     * summary 標「結構不合法」而不是把它顯示成「實際可購 100–10000」。
     */
    public function test_a_legacy_step_zero_variant_is_now_usable_and_labelled_compatible(): void
    {
        ProviderService::factory()->available()->create([
            'provider_service_id' => '78088',
            'minimum_quantity_raw' => '10',
            'maximum_quantity_raw' => '20000',
        ]);
        $variant = ServiceVariant::withoutEvents(fn () => ServiceVariant::factory()->create([
            'step_quantity' => 0,
        ]));

        // ⛔ M3A:step 已不參與任何計算,除零路徑不存在,因此這筆 min/max
        // 正常的 legacy row 必須照常可啟用——否則升級後既有商品會無故失效。
        Livewire::test(CreateFulfillmentMapping::class)
            ->fillForm([
                'service_variant_id' => $variant->id,
                'provider_service_id' => '78088',
                'is_enabled' => true,
                'apply_provider_bounds' => false,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertTrue(FulfillmentMapping::query()->sole()->is_enabled);

        $html = html_entity_decode(
            Livewire::test(CreateFulfillmentMapping::class)
                ->fillForm([
                    'service_variant_id' => $variant->id,
                    'provider_service_id' => '78088',
                ])
                ->html(),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8',
        );

        $this->assertStringContainsString('✔ 數量相容', $html);
        $this->assertStringContainsString('實際可購 100–10000', $html);
        $this->assertStringNotContainsString('結構不合法', $html);
    }

    /** ⛔ R1:min 0 由 observer 直接拒絕;legacy row 也不得啟用或顯示「可購 0」。 */
    public function test_a_zero_minimum_is_rejected_and_never_shown_as_purchasable(): void
    {
        try {
            ServiceVariant::factory()->create(['min_quantity' => 0, 'default_quantity' => 100]);
            $this->fail('min 0 必須被 observer 拒絕');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('min_quantity', $e->errors());
        }

        ProviderService::factory()->available()->create([
            'provider_service_id' => '77077',
            'minimum_quantity_raw' => '1',
            'maximum_quantity_raw' => '999999',
        ]);
        $variant = ServiceVariant::withoutEvents(fn () => ServiceVariant::factory()->create([
            'min_quantity' => 0, 'default_quantity' => 100,
        ]));

        Livewire::test(CreateFulfillmentMapping::class)
            ->fillForm([
                'service_variant_id' => $variant->id,
                'provider_service_id' => '77077',
                'is_enabled' => true,
            ])
            ->call('create')
            ->assertHasFormErrors(['provider_service_id']);

        $this->assertSame(0, FulfillmentMapping::query()->count());

        $html = html_entity_decode(
            Livewire::test(CreateFulfillmentMapping::class)
                ->fillForm([
                    'service_variant_id' => $variant->id,
                    'provider_service_id' => '77077',
                ])
                ->html(),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8',
        );

        // ⛔ 不得顯示不存在的「實際可購 0」或相容。
        $this->assertStringNotContainsString('實際可購 0', $html);
        $this->assertStringContainsString('結構不合法', $html);
        $this->assertStringNotContainsString('✔ 數量相容', $html);
    }

    /** ⛔ submit-time race:選擇後、送出前供應商 minimum 變高 → 啟用拒絕。 */
    public function test_bounds_changing_between_menu_and_submit_block_enabling(): void
    {
        $service = ProviderService::factory()->available()->create([
            'provider_service_id' => '86066',
            'minimum_quantity_raw' => '10',
            'maximum_quantity_raw' => '20000',
        ]);
        $variant = ServiceVariant::factory()->create();

        $page = Livewire::test(CreateFulfillmentMapping::class)
            ->fillForm([
                'service_variant_id' => $variant->id,
                'provider_service_id' => '86066',
                'is_enabled' => true,
            ]);

        // 下一個 snapshot 把 minimum 抬高到本站範圍之上。
        $service->update(['minimum_quantity_raw' => '99999']);

        $page->call('create')->assertHasFormErrors(['provider_service_id']);

        $this->assertSame(0, FulfillmentMapping::query()->count());
    }

    /** ⛔ 竄改的 variant state:guard 重讀 DB,不存在的款式不得啟用。 */
    public function test_a_tampered_variant_id_blocks_enabling(): void
    {
        ProviderService::factory()->available()->create([
            'provider_service_id' => '87077',
            'minimum_quantity_raw' => '10',
            'maximum_quantity_raw' => '20000',
        ]);

        Livewire::test(CreateFulfillmentMapping::class)
            ->fillForm([
                'service_variant_id' => 999999,
                'provider_service_id' => '87077',
                'is_enabled' => true,
            ])
            ->call('create')
            ->assertHasFormErrors(['provider_service_id']);

        $this->assertSame(0, FulfillmentMapping::query()->count());
    }

    /** Owner 對照畫面:本站實際範圍、provider 事實、rate 警語與相容標示。 */
    public function test_the_compatibility_summary_shows_both_sides_and_the_rate_warning(): void
    {
        ProviderService::factory()->available()->create([
            'provider_service_id' => '88088',
            'name' => '虛構對照服務',
            'minimum_quantity_raw' => '150',
            'maximum_quantity_raw' => '20000',
            'rate_raw' => '0.90',
        ]);
        $variant = ServiceVariant::factory()->create();

        // ->live() 選擇後 placeholder 重新 render:以最終 HTML 驗證。
        $html = html_entity_decode(
            Livewire::test(CreateFulfillmentMapping::class)
                ->fillForm([
                    'service_variant_id' => $variant->id,
                    'provider_service_id' => '88088',
                ])
                ->html(),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8',
        );

        // 本站實際範圍與 provider 事實同畫面。
        $this->assertStringContainsString('實際可購 100–10000', $html);
        $this->assertStringContainsString('虛構對照服務', $html);
        $this->assertStringContainsString('min 150／max 20000', $html);
        $this->assertStringContainsString('不是本站售價', $html);
        // min 150 > 實際下限 100 → 不相容標示。
        $this->assertStringContainsString('✘', $html);
        $this->assertStringContainsString('供應商最低量高於本站實際最低可購量', $html);
    }

    /** ⛔ placeholder 是 provider text 的新出口:rendered HTML 必須 escaped。 */
    public function test_the_compatibility_summary_escapes_hostile_provider_text(): void
    {
        $hostile = '<script>alert("summary-xss")</script>';

        ProviderService::factory()->available()->create([
            'provider_service_id' => '89099',
            'name' => $hostile,
            'minimum_quantity_raw' => '10',
            'maximum_quantity_raw' => '20000',
        ]);
        $variant = ServiceVariant::factory()->create();

        $html = Livewire::test(CreateFulfillmentMapping::class)
            ->fillForm([
                'service_variant_id' => $variant->id,
                'provider_service_id' => '89099',
            ])
            ->html();

        $this->assertStringNotContainsString($hostile, $html);
        $this->assertStringNotContainsString('<script>alert("summary-xss")', $html);
        // 正向證明 placeholder 真的渲染了敵意文字(escaped),不是沒渲染。
        $this->assertStringContainsString('summary-xss', $html);
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
