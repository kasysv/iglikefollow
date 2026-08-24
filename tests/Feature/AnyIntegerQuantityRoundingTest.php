<?php

namespace Tests\Feature;

use App\Exceptions\UnsellablePriceException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentAttempt;
use App\Models\ProviderService;
use App\Models\Service;
use App\Models\ServiceVariant;
use App\Support\Money;
use App\Support\ProviderBoundsTarget;
use App\Support\QuantityCompatibility;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * M3A:取消購買倍數限制,金額改為 half-up 四捨五入。
 *
 * Owner 在商品頁輸入 100,瀏覽器卻提示最近有效值是 10 與 110——因為
 * HTML `step` 與後端 `quantity % step === 0` 把「有效」定義成倍數,而
 * 最低量本身不是 step 倍數時,序列會從最低量起算而更反直覺。
 *
 * ⛔ 這個檔案要同時證明兩件相反的事:
 *   1. 倍數限制真的消失了(前端、驗證、建單、履約相容性都一致);
 *   2. 放寬倍數沒有放寬別的東西——上下限、整數、正數、overflow、
 *      「四捨五入後不足 1 元」與「後端永遠重算金額」全部照舊。
 *
 * ⛔ 全程 `Http::preventStrayRequests()`:任何外部呼叫都會讓測試失敗。
 */
class AnyIntegerQuantityRoundingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);

        Http::preventStrayRequests();
    }

    /** legacy step 100 的款式:⛔ 它必須完全不影響顧客能買什麼。 */
    private function legacyStepVariant(string $rate = '0.5900', int $min = 10, int $max = 10000): ServiceVariant
    {
        $variant = ServiceVariant::query()->where('sku', 'ig-followers-standard')->firstOrFail();

        // 繞過 observer 直接寫入,模擬升級前留下的既有資料。
        DB::table('service_variants')->where('id', $variant->id)->update([
            'unit_price' => $rate,
            'min_quantity' => $min,
            'max_quantity' => $max,
            'step_quantity' => 100,
            'default_quantity' => $min,
        ]);

        return ServiceVariant::query()->findOrFail($variant->id);
    }

    // ================================================== 1. 任意整數皆可

    /** 施工單指定案例:min=10、max>=1000、legacy step=100。 */
    public function test_every_integer_between_the_bounds_is_purchasable(): void
    {
        $variant = $this->legacyStepVariant();

        foreach ([10, 11, 100, 101, 155, 999, 1000] as $quantity) {
            $this->assertTrue(
                $variant->quantityIsValid($quantity),
                "數量 {$quantity} 應該可購買"
            );
        }
    }

    /** ⛔ 邊界沒有被放寬:min-1 與 max+1 仍然拒絕。 */
    public function test_quantities_outside_the_bounds_are_still_refused(): void
    {
        $variant = $this->legacyStepVariant();

        $this->assertFalse($variant->quantityIsValid(9));
        $this->assertFalse($variant->quantityIsValid(10001));
        $this->assertFalse($variant->quantityIsValid(0));
        $this->assertFalse($variant->quantityIsValid(-100));
    }

    /** 施工單指定案例:100 與 1000 都必須真的能送出去。 */
    public function test_checkout_accepts_the_quantities_the_owner_reported(): void
    {
        $variant = $this->legacyStepVariant();

        foreach ([100, 1000] as $quantity) {
            $this->post('/checkout/start', [
                'variant' => $variant->id,
                'quantity' => $quantity,
            ])->assertSessionHasNoErrors()->assertRedirect('/checkout');
        }
    }

    // ================================================== 2. 前端不再限制倍數

    public function test_the_product_page_uses_step_one_and_never_mentions_multiples(): void
    {
        $html = $this->get('/product/ig買粉絲/')->assertOk()->getContent();

        // ⛔ 固定 step="1";不得再綁 b.step。
        $this->assertStringContainsString('step="1"', $html);
        $this->assertStringNotContainsString(':step="b.step"', $html);

        // ⛔ 畫面不得再出現倍數概念。
        $this->assertStringNotContainsString('的倍數', $html);
        $this->assertStringNotContainsString('數量間隔', $html);
        $this->assertStringNotContainsString('一階', $html);

        // ⛔ bounds payload 不再輸出 step,免得被誤用成限制。
        $this->assertStringNotContainsString('&quot;step&quot;', $html);
    }

    /** ⛔ Alpine 的 valid 與後端同一條規則:對 100 與 1000 必須為 true。 */
    public function test_the_alpine_validity_rule_has_no_modulo(): void
    {
        $html = $this->get('/product/ig買粉絲/')->assertOk()->getContent();

        // 原本是 `q % this.b.step === 0`;⛔ 不得再有任何 modulo。
        $this->assertStringNotContainsString('% this.b.step', $html);
        $this->assertStringContainsString(
            'Number.isInteger(q) && q >= this.b.min && q <= this.b.max',
            $html,
        );
    }

    // ================================================== 3. half-up 四捨五入

    /** 施工單指定案例:0.59 × 100 / 101 / 1000。 */
    public function test_the_owner_specified_amounts(): void
    {
        $rate = Money::toMills('0.59');

        $this->assertSame(59, Money::total($rate, 100));     // 59
        $this->assertSame(60, Money::total($rate, 101));     // 59.59 → 60
        $this->assertSame(590, Money::total($rate, 1000));   // 590
    }

    /**
     * ⛔ 精確 half-up 邊界:餘數 4,999 捨去、5,000 進位。
     *
     * 直接構造 mills 餘數,⛔ 不倚賴十進位字面值看起來像不像 .5。
     */
    public function test_the_exact_mills_boundary(): void
    {
        // 單價 = 1 元又 4,999 mills,數量 1。
        $this->assertSame(1, Money::total(Money::SCALE + 4999, 1));

        // 單價 = 1 元又 5,000 mills(剛好 .5)→ ⛔ 必須進位。
        $this->assertSame(2, Money::total(Money::SCALE + 5000, 1));

        // 剛好 .5 的另一組:2.5 → 3。⛔ banker's rounding 會給 2。
        $this->assertSame(3, Money::total(2 * Money::SCALE + 5000, 1));

        // 4.5 → 5(banker's 會給 4)。
        $this->assertSame(5, Money::total(4 * Money::SCALE + 5000, 1));
    }

    /** ⛔ 四捨五入為 0 一律拒絕:不建 NT$0 訂單,也不暗自墊到 NT$1。 */
    public function test_an_amount_that_rounds_to_zero_is_refused(): void
    {
        $this->expectException(UnsellablePriceException::class);

        Money::total(Money::toMills('0.0001'), 1);   // 0.0001 元 → 0
    }

    public function test_a_rounds_to_zero_checkout_creates_nothing(): void
    {
        $variant = $this->legacyStepVariant('0.0001', 1, 10000);

        $this->post('/checkout/start', ['variant' => $variant->id, 'quantity' => 1])
            ->assertRedirect();

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, OrderItem::query()->count());
        $this->assertSame(0, PaymentAttempt::query()->count());
        Http::assertNothingSent();
    }

    /** ⛔ 負價、零價、零數量與 overflow 仍然 fail closed。 */
    public function test_the_other_money_guards_are_unchanged(): void
    {
        foreach ([[-100, 10], [0, 10], [5900, 0], [5900, -10]] as [$rate, $quantity]) {
            $this->assertFalse(Money::isPayable($rate, $quantity));
        }

        // overflow 必須拋錯,⛔ 不得靜默變成 float。
        $this->expectException(UnsellablePriceException::class);
        Money::total(PHP_INT_MAX, 999);
    }

    // ================================================== 4. 後端是唯一真實來源

    public function test_a_crafted_post_cannot_smuggle_a_price_or_amount(): void
    {
        $variant = $this->legacyStepVariant();

        $this->post('/checkout/start', [
            'variant' => $variant->id,
            'quantity' => 101,
            // ⛔ 前端塞入的價格與金額一律忽略。
            'price' => 1,
            'amount' => 1,
            'unit_price' => 1,
        ])->assertRedirect('/checkout');

        // 0.59 × 101 = 59.59 → 60;⛔ 不是前端送的 1。
        $this->assertSame(60, $variant->fresh()->amountFor(101));

        $html = $this->get('/checkout')->assertOk()->getContent();
        $this->assertStringContainsString('NT$60', $html);
    }

    public function test_the_order_snapshot_and_payment_attempt_agree_with_the_server(): void
    {
        $variant = $this->legacyStepVariant();

        $this->post('/checkout/start', ['variant' => $variant->id, 'quantity' => 101]);
        $this->post('/checkout/mock', [
            'target' => 'example_account',
            'payment' => 'line-pay',
            'customer_email' => 'buyer@example.invalid',
            'invoice_kind' => 'personal',
            'personal_invoice_mode' => 'email',
            // ⛔ 再次嘗試竄改。
            'price' => 1,
            'amount' => 1,
        ])->assertOk();

        $order = Order::query()->sole();
        $item = OrderItem::query()->sole();

        // ⛔ 三處金額必須一致,且都等於後端算出來的 60。
        $this->assertSame(60, (int) $order->total_amount);
        $this->assertSame(60, (int) $item->amount);
        $this->assertSame(101, (int) $item->quantity);
        // ⛔ order item 仍保存精確單價快照(未被四捨五入汙染)。
        $this->assertSame(5900, (int) $item->unit_price_mills);

        $attempt = PaymentAttempt::query()->sole();
        $this->assertSame(60, (int) $attempt->amount);

        Http::assertNothingSent();
    }

    // ================================================== 5. 履約相容性

    /** 施工單指定案例:provider 10–1000 對本站 10–1000 必須相容。 */
    public function test_provider_bounds_match_the_raw_range_not_a_step_multiple(): void
    {
        $variant = $this->legacyStepVariant('0.5900', 10, 1000);

        $service = ProviderService::factory()->available()->create([
            'provider_service_id' => '91001',
            'minimum_quantity_raw' => '10',
            'maximum_quantity_raw' => '1000',
        ]);

        $assessment = QuantityCompatibility::assess($variant, $service);

        $this->assertTrue($assessment->compatible);
        // ⛔ legacy step 100 不得把 first 算成 100、也不得把 last 截成 1000 以下。
        $this->assertSame(10, $assessment->siteFirstPurchasable);
        $this->assertSame(1000, $assessment->siteLastPurchasable);
    }

    /** ⛔ 套用 provider 上下限只按 min/max,不做倍數對齊。 */
    public function test_applying_provider_bounds_does_not_align_the_default(): void
    {
        $variant = $this->legacyStepVariant('0.5900', 10, 1000);
        $stepBefore = (int) $variant->step_quantity;

        $service = ProviderService::factory()->available()->create([
            'provider_service_id' => '91002',
            'minimum_quantity_raw' => '150',
            'maximum_quantity_raw' => '900',
        ]);

        $target = ProviderBoundsTarget::compute($variant, $service);

        $this->assertTrue($target->ok);
        $this->assertSame(150, $target->targetMin);
        $this->assertSame(900, $target->targetMax);
        // 原 default 10 低於新 min → 調整為新 min 本身;⛔ 不是 200。
        $this->assertSame(150, $target->targetDefault);

        // ⛔ legacy step 欄位完全不被此計算改動。
        $this->assertSame($stepBefore, (int) $variant->fresh()->step_quantity);
    }

    // ================================================== 6. 商品完整性

    public function test_a_default_that_is_not_a_multiple_can_be_saved(): void
    {
        $service = Service::query()->firstOrFail();

        $variant = ServiceVariant::factory()->create([
            'service_id' => $service->id,
            'unit_price' => '1.0000',
            'min_quantity' => 100,
            'max_quantity' => 1000,
            'default_quantity' => 155,   // ⛔ 曾因不是 100 的倍數被拒絕
            'status' => 'published',
        ]);

        $this->assertSame(155, (int) $variant->fresh()->default_quantity);
    }

    /** ⛔ 但結構錯誤與「收不到錢」仍然擋下。 */
    public function test_structural_and_unpayable_configurations_are_still_refused(): void
    {
        $service = Service::query()->firstOrFail();

        // 空範圍。
        $threw = false;
        try {
            ServiceVariant::factory()->create([
                'service_id' => $service->id,
                'min_quantity' => 500, 'max_quantity' => 100, 'default_quantity' => 300,
            ]);
        } catch (ValidationException) {
            $threw = true;
        }
        $this->assertTrue($threw, '空範圍必須被拒絕');

        // 已發布但最低量的金額四捨五入後為 0。
        $threw = false;
        try {
            ServiceVariant::factory()->create([
                'service_id' => $service->id,
                'unit_price' => '0.0001',
                'min_quantity' => 1, 'max_quantity' => 1000, 'default_quantity' => 1,
                'status' => 'published',
            ]);
        } catch (ValidationException) {
            $threw = true;
        }
        $this->assertTrue($threw, '四捨五入後為 0 的設定必須被拒絕');
    }

    // ================================================== 7. 新資料預設

    public function test_new_data_defaults_to_a_step_of_one(): void
    {
        // ⛔ factory 與 seeder 都不得再產生會暗示倍數限制的 step。
        $this->assertSame(1, (int) ServiceVariant::factory()->make()->step_quantity);

        $this->assertSame(
            0,
            ServiceVariant::query()->where('step_quantity', '>', 1)->count(),
            'seeder 不得寫入大於 1 的 step_quantity',
        );
    }
}
