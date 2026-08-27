<?php

namespace Tests\Feature\Fulfillment;

use App\Actions\Fulfillment\CreateFulfillmentReplacement;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Filament\Resources\Orders\RelationManagers\FulfillmentOrdersRelationManager;
use App\Jobs\SubmitFulfillmentOrder;
use App\Models\FulfillmentOrder;
use App\Models\Order;
use App\Models\User;
use App\Services\Fulfillment\TheMostPanelCurlCapability;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\StateCasts\NumberStateCast;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ConfiguresLiveIntegrations;
use Tests\TestCase;

/**
 * The quantity field's type boundary — where the staging bug actually lived.
 *
 * ⛔⛔ Owner 在 staging 完全不改預設數量 `50` 就送出，得到
 * 「實際送出數量必須是正整數（不含小數、正負號或空白）」。
 *
 * ⭐ 根因不在核心 action，而在 Filament 的型別邊界。我已直接讀安裝版
 * Filament v5.7.6 原始碼確認整條鏈：
 *
 *  1. `TextInput::integer()` 第一行就呼叫 `numeric()`（TextInput.php:88）；
 *  2. `numeric()` 設定 `$this->isNumeric = true`（TextInput.php:137）；
 *  3. `getDefaultStateCasts()` 只要 `isNumeric()` 為真就掛上
 *     `NumberStateCast`（TextInput.php:305）；
 *  4. `NumberStateCast::get()／set()` 無條件 `floatval()`，回傳 `?float`。
 *
 * ⛔ 所以合法的 `50` 在**送到 action 之前**就已經是 `50.0`，
 * 而 action 為了擋 `1.5 → 1` 的靜默截斷而正確拒絕所有 float。
 *
 * ⛔⛔ 我 R1～R3 的測試全部直接呼叫 action，⛔ 沒有任何一條走過 Filament／
 * Livewire——這正是這個 bug 能一路到 staging 的原因。
 * ⭐ 因此這個檔案刻意走**真實表單提交**。
 */
class FulfillmentReplacementFormTest extends TestCase
{
    use ConfiguresLiveIntegrations;
    use RefreshDatabase;

    private const NEW_TARGET = 'https://instagram.com/replacement_account';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        config()->set('fulfillment.driver', 'fake');
        $this->enableDispatchSwitch();
        $this->app->bind(
            TheMostPanelCurlCapability::class,
            fn () => TheMostPanelCurlCapability::supported(),
        );
    }

    private function owner(): User
    {
        return User::factory()->create(['role' => 'owner', 'is_active' => true]);
    }

    /** 一張已付款訂單，含一個商品項目與一筆已送出的履約。 */
    private function paidOrderWithSubmittedBatch(array $batchOverrides = []): FulfillmentOrder
    {
        $order = Order::factory()->create([
            'order_status' => OrderStatus::Paid,
            'payment_status' => PaymentStatus::Succeeded,
            'paid_at' => now(),
        ]);

        $item = $order->items()->create([
            'platform_name' => 'Instagram',
            'service_name' => 'Instagram 粉絲',
            'variant_label' => '一般粉絲',
            'sku' => 'ig-followers-standard',
            'unit_price_mills' => 5900,
            'quantity' => 1000,
            'quantity_unit' => '個',
            'amount' => 590,
            'target_kind' => 'account',
            'target_value' => 'original_account',
        ]);

        return FulfillmentOrder::factory()
            ->submitted('SMM-PARENT-1')
            ->create(array_merge(['order_item_id' => $item->id], $batchOverrides));
    }

    /**
     * 走真正的 Filament RelationManager action。
     *
     * ⛔ 刻意不直接呼叫 `CreateFulfillmentReplacement`：這一輪要驗證的
     * 就是**表單到 action 之間**那一段。
     */
    private function submit(FulfillmentOrder $parent, mixed $quantity, mixed $target = self::NEW_TARGET): void
    {
        Livewire::actingAs($this->owner())
            ->test(FulfillmentOrdersRelationManager::class, [
                'ownerRecord' => $parent->orderItem->order,
                'pageClass' => ViewOrder::class,
            ])
            ->callTableAction('replaceFulfillment', $parent, data: [
                'target' => $target,
                'quantity' => $quantity,
            ]);
    }

    // ============================ 1. 直接檢查 component 的 state cast

    /**
     * ⛔⛔ 施工單 §4.1：直接檢查安裝版 Filament component，
     * quantity 欄位不得掛上 `NumberStateCast`。
     *
     * ⭐ 這是最不脆弱的一條：它不依賴任何提交流程，
     * 直接問「這個欄位會不會把輸入轉成 float」。
     */
    public function test_the_quantity_field_has_no_number_state_cast(): void
    {
        $field = TextInput::make('quantity')
            ->type('number')
            ->inputMode('numeric')
            ->step(1)
            ->rule('integer');

        foreach ($field->getDefaultStateCasts() as $cast) {
            $this->assertNotInstanceOf(
                NumberStateCast::class,
                $cast,
                '⛔⛔ quantity 欄位掛上了 NumberStateCast：它會無條件 floatval()，'
                .'合法的 50 會變成 50.0，然後被 action 正確拒絕。',
            );
        }

        // ⭐ 反面確認：`integer()` 確實會掛上它——證明上面的斷言不是空跑。
        $numeric = TextInput::make('quantity')->integer();

        $hasCast = false;
        foreach ($numeric->getDefaultStateCasts() as $cast) {
            $hasCast = $hasCast || $cast instanceof NumberStateCast;
        }

        $this->assertTrue(
            $hasCast,
            '⛔ 若 `integer()` 不再掛 NumberStateCast，本測試的前提已改變，需重新確認。',
        );
    }

    // ============================ 2. 預設值直接送出（staging 的實際操作）

    /**
     * ⛔⛔ staging 的實際操作：完全不修改預設數量就按送出。
     *
     * ⭐ 建議數量來自 `provider_remains ?? parent.effectiveQuantity()`；
     * 這裡把 parent 的 remains 設為 50，重現 Owner 遇到的那個 `50`。
     */
    public function test_submitting_the_untouched_default_quantity_creates_the_replacement(): void
    {
        $parent = $this->paidOrderWithSubmittedBatch([
            'provider_remains' => 50,
        ]);

        Bus::fake();

        $this->submit($parent, quantity: 50);

        $child = $parent->fresh()->replacement;

        $this->assertNotNull(
            $child,
            '⛔⛔ 不修改預設數量就送出必須成功——這正是 staging 失敗的那一步。',
        );
        $this->assertSame(50, $child->quantity_override);
        $this->assertSame(2, $child->sequence_no);

        // ⛔ 恰好一個派單 job。
        Bus::assertDispatchedTimes(SubmitFulfillmentOrder::class, 1);
    }

    /** ⭐ Owner 改成另一個合法整數同樣要成功。 */
    public function test_submitting_an_edited_quantity_creates_the_replacement(): void
    {
        $parent = $this->paidOrderWithSubmittedBatch([
            'provider_remains' => 50,
        ]);

        Bus::fake();

        $this->submit($parent, quantity: 51);

        $child = $parent->fresh()->replacement;

        $this->assertNotNull($child, '⛔ 合法的 51 必須被接受。');
        $this->assertSame(51, $child->quantity_override);

        Bus::assertDispatchedTimes(SubmitFulfillmentOrder::class, 1);
    }

    // ============================ 3. 嚴格拒絕不得被放寬

    /**
     * ⛔⛔ 修好預設值**不得**順手放寬驗證。
     *
     * ⭐ 這幾個值全都是「看起來像數字、但不是使用者打的那個整數」：
     * `1.5` 會被靜默截斷成 `1`、`1e3` 是指數、`+50` 帶符號、
     * ` 50 ` 帶空白、`050` 有前導零。⛔ Owner 明確要求不自動調整，
     * 所以這些一律在建立 child **之前**拒絕。
     *
     * @return array<string, array{mixed}>
     */
    public static function illegalQuantities(): array
    {
        return [
            'decimal' => ['1.5'],
            'integral float string' => ['50.0'],
            'non-integral float' => [1.5],
            'exponent' => ['1e3'],
            'signed' => ['+50'],
            'padded with spaces' => [' 50 '],
            'leading zero' => ['050'],
            'zero' => ['0'],
            'negative' => ['-5'],
            'empty' => [''],
        ];
    }

    #[DataProvider('illegalQuantities')]
    public function test_illegal_quantities_never_create_a_child(mixed $quantity): void
    {
        $parent = $this->paidOrderWithSubmittedBatch([
            'provider_remains' => 50,
        ]);

        Bus::fake();

        $this->submit($parent, quantity: $quantity);

        $this->assertNull(
            $parent->fresh()->replacement,
            '⛔⛔ 非法數量不得建立 child：'.var_export($quantity, true),
        );

        // ⛔ 0 job、0 外呼。
        Bus::assertNotDispatched(SubmitFulfillmentOrder::class);
        $this->assertSame(1, FulfillmentOrder::count());
    }

    /**
     * ⛔⛔ action 的 float 拒絕**沒有**因為 R4 而放寬——但必須在
     * ⭐ 它真正可達的那一層測。
     *
     * ⭐ 我原本在上面的清單放了一個 PHP float `50.0`，它卻建立了 child。
     * 追下去發現原因不在驗證被放寬，而在**傳輸層根本送不出整數浮點數**：
     *
     * ```php
     * json_encode(50.0)  // → "50"    ⟹ decode 後是 int(50)
     * json_encode(1.5)   // → "1.5"   ⟹ decode 後仍是 float(1.5)
     * ```
     *
     * ⛔ 也就是說「整數值的 float」在 Livewire／HTTP 邊界會變回 int，
     * ⛔ 那個 case 測的是一個**不可達**的狀態，留著只會給人虛假的安全感。
     *
     * ⭐ 真正該釘住的是：**直接呼叫 action 時** float 仍被拒絕。
     * 那才是偽造呼叫、內部誤用會走到的路徑。
     */
    public function test_the_action_still_refuses_floats_when_called_directly(): void
    {
        $parent = $this->paidOrderWithSubmittedBatch(['provider_remains' => 50]);

        Bus::fake();

        foreach ([50.0, 1.5, 2.0] as $float) {
            try {
                app(CreateFulfillmentReplacement::class)->handle(
                    $this->owner(),
                    $parent->fresh(),
                    self::NEW_TARGET,
                    $float,
                );

                $this->fail('⛔⛔ action 必須拒絕 float：'.var_export($float, true));
            } catch (ValidationException $e) {
                $this->assertStringContainsString('正整數', $e->validator->errors()->first());
            }
        }

        $this->assertNull($parent->fresh()->replacement);
        Bus::assertNotDispatched(SubmitFulfillmentOrder::class);
    }
}
