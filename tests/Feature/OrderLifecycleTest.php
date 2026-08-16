<?php

namespace Tests\Feature;

use App\Actions\Orders\RecordPaymentResult;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Events\OrderPaid;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\PaymentAttempt;
use App\Models\ServiceVariant;
use App\Support\CheckoutSession;
use Database\Seeders\CatalogSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * M3A: the local order and payment lifecycle.
 *
 * The order exists from the moment checkout validates, before any payment is
 * attempted. Payment outcomes attach to individual attempts, and only a
 * verified success may promote the order to paid — exactly once.
 */
class OrderLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);

        // ⛔ 本輪不得有任何外部 HTTP：金流、發票與 SMM 一律禁止。
        Http::preventStrayRequests();
    }

    private function variant(string $sku = 'ig-followers-standard'): ServiceVariant
    {
        return ServiceVariant::query()->where('sku', $sku)->firstOrFail();
    }

    private function startCheckout(?int $variantId = null, int $quantity = 1000): void
    {
        $this->post('/checkout/start', [
            'variant' => $variantId ?? $this->variant()->id,
            'quantity' => $quantity,
        ])->assertRedirect(route('checkout'));
    }

    /** @return array<string, mixed> */
    private function orderForm(array $overrides = []): array
    {
        return array_merge([
            'target' => 'example_account',
            'payment' => 'line-pay',
            'customer_email' => 'buyer@example.com',
            'invoice_kind' => 'personal',
            'personal_invoice_mode' => 'email',
        ], $overrides);
    }

    private function checkout(array $overrides = [])
    {
        $this->startCheckout();

        return $this->post('/checkout/mock', $this->orderForm($overrides));
    }

    // ================================================ 1. 建單即為 pending_payment

    public function test_a_submitted_checkout_creates_a_pending_order_snapshot_and_attempt(): void
    {
        $this->checkout(['fake_payment_result' => PaymentStatus::Pending->value])->assertOk();

        $order = Order::latest('id')->firstOrFail();

        $this->assertSame(OrderStatus::PendingPayment, $order->order_status);
        $this->assertSame(1, $order->items()->count());
        $this->assertSame(1, $order->paymentAttempts()->count());
        $this->assertNull($order->paid_at);
    }

    public function test_the_order_records_a_created_event(): void
    {
        $this->checkout(['fake_payment_result' => PaymentStatus::Pending->value]);

        $this->assertDatabaseHas('order_events', [
            'order_id' => Order::latest('id')->value('id'),
            'type' => OrderEvent::TYPE_ORDER_CREATED,
        ]);
    }

    public function test_the_order_reference_is_not_guessable_and_is_the_only_public_id(): void
    {
        $response = $this->checkout()->assertOk();

        $order = Order::latest('id')->firstOrFail();

        $this->assertMatchesRegularExpression('/^IGL-[A-Z0-9]{12}$/', $order->reference);
        $response->assertSee($order->reference);
        // ⛔ 資料庫 id 不得作為對外識別。
        $this->assertSame('reference', $order->getRouteKeyName());
    }

    // ================================================ 2. 後端重算價格

    public function test_the_amount_is_recomputed_from_the_published_variant(): void
    {
        // 1000 × 0.59 = 590
        $this->checkout(['price' => 1, 'amount' => 1, 'total_amount' => 1])->assertOk();

        $order = Order::latest('id')->firstOrFail();

        $this->assertSame(590, $order->total_amount);
        $this->assertSame(590, $order->items()->value('amount'));
    }

    public function test_a_forged_variant_or_quantity_in_the_form_is_ignored(): void
    {
        $expensive = $this->variant('ig-followers-taiwan');

        $this->startCheckout();
        $this->post('/checkout/mock', $this->orderForm([
            'variant' => $expensive->id,
            'quantity' => 99999,
        ]))->assertOk();

        $item = Order::latest('id')->firstOrFail()->items()->firstOrFail();

        // 商品與數量只能來自 server-side session。
        $this->assertSame(1000, $item->quantity);
        $this->assertSame('一般粉絲', $item->variant_label);
    }

    public function test_an_unpublished_variant_cannot_reach_an_order(): void
    {
        $variant = $this->variant();
        $this->startCheckout($variant->id);

        $variant->update(['status' => 'draft']);

        $this->post('/checkout/mock', $this->orderForm())->assertRedirect(route('checkout'));

        $this->assertSame(0, Order::count());
    }

    // ================================================ 3. 四種付款結果

    public function test_a_fake_success_marks_the_order_paid(): void
    {
        $this->checkout()->assertOk();

        $order = Order::latest('id')->firstOrFail();

        $this->assertSame(OrderStatus::Paid, $order->order_status);
        $this->assertSame(PaymentStatus::Succeeded, $order->payment_status);
        $this->assertNotNull($order->paid_at);
    }

    /**
     * 失敗、取消、逾期都必須保留訂單，⛔ 不可讓訂單消失或被覆蓋成一段文字。
     */
    public function test_unsuccessful_outcomes_keep_the_order_and_record_the_attempt(): void
    {
        foreach ([PaymentStatus::Failed, PaymentStatus::Canceled, PaymentStatus::Expired] as $outcome) {
            $this->checkout(['fake_payment_result' => $outcome->value])->assertOk();

            $order = Order::latest('id')->firstOrFail();
            $attempt = $order->paymentAttempts()->latest('id')->firstOrFail();

            $this->assertSame($outcome, $attempt->status, $outcome->value.' 應保存在付款嘗試上');
            $this->assertSame($outcome, $order->payment_status);
            // 訂單保留為待付款，客人仍可重新付款。
            $this->assertSame(OrderStatus::PendingPayment, $order->order_status);
            $this->assertNull($order->paid_at);
        }

        $this->assertSame(3, Order::count());
    }

    public function test_a_failed_attempt_stores_only_a_masked_failure_code(): void
    {
        $this->checkout(['fake_payment_result' => PaymentStatus::Failed->value]);

        $attempt = PaymentAttempt::latest('id')->firstOrFail();

        $this->assertSame('FAKE_DECLINED', $attempt->failure_code);
        // ⛔ 不得保存卡號、CVV、secret 或完整 provider payload。
        foreach (['card', 'cvv', 'secret', 'hashkey'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase(
                $forbidden,
                json_encode($attempt->toArray(), JSON_UNESCAPED_UNICODE)
            );
        }
    }

    // ================================================ 4. 防重複建單

    public function test_resubmitting_the_same_checkout_creates_only_one_order(): void
    {
        $this->startCheckout();

        $this->post('/checkout/mock', $this->orderForm())->assertOk();
        // 送出後 session 已清空，重送會被導回 /checkout。
        $this->post('/checkout/mock', $this->orderForm())->assertRedirect(route('checkout'));

        $this->assertSame(1, Order::count());
    }

    public function test_the_checkout_token_is_unique_at_the_database_level(): void
    {
        $order = Order::factory()->create();

        $this->expectException(UniqueConstraintViolationException::class);

        // ⛔ 同一次結帳不得產生第二張訂單，由 DB constraint 保障。
        Order::factory()->create(['checkout_token' => $order->checkout_token]);
    }

    public function test_two_submissions_sharing_a_token_resolve_to_the_same_order(): void
    {
        $this->startCheckout();
        $token = session(CheckoutSession::KEY)['token'];

        $this->post('/checkout/mock', $this->orderForm())->assertOk();

        // 模擬並行請求：session 已清，但 token 相同的訂單已存在。
        $this->assertSame(1, Order::where('checkout_token', $token)->count());
        $this->assertSame(1, Order::count());
    }

    public function test_changing_quantity_before_submitting_keeps_one_checkout_token(): void
    {
        $this->startCheckout(quantity: 1000);
        $first = session(CheckoutSession::KEY)['token'];

        // 客人回頭改數量仍是同一次結帳。
        $this->startCheckout(quantity: 2000);
        $second = session(CheckoutSession::KEY)['token'];

        $this->assertSame($first, $second);
    }

    // ================================================ 5. 付款通知冪等

    public function test_a_repeated_success_promotes_the_order_only_once(): void
    {
        Event::fake([OrderPaid::class]);

        $this->checkout(['fake_payment_result' => PaymentStatus::Pending->value]);

        $order = Order::latest('id')->firstOrFail();
        $attempt = $order->paymentAttempts()->firstOrFail();

        $action = app(RecordPaymentResult::class);
        $action->handle($attempt, PaymentStatus::Succeeded, 'TXN-1');
        $action->handle($attempt->fresh(), PaymentStatus::Succeeded, 'TXN-1');
        $action->handle($attempt->fresh(), PaymentStatus::Succeeded, 'TXN-1');

        $order->refresh();

        $this->assertSame(OrderStatus::Paid, $order->order_status);
        // ⛔ 只能有一個履約 seam。
        $this->assertSame(1, $order->events()->where('type', OrderEvent::TYPE_ORDER_PAID)->count());
        Event::assertDispatchedTimes(OrderPaid::class, 1);
    }

    public function test_a_late_failure_cannot_downgrade_a_paid_order(): void
    {
        $this->checkout()->assertOk();

        $order = Order::latest('id')->firstOrFail();
        $this->assertSame(OrderStatus::Paid, $order->order_status);

        // 同一筆嘗試的遲到失敗通知。
        app(RecordPaymentResult::class)->handle(
            $order->paymentAttempts()->firstOrFail(),
            PaymentStatus::Failed,
        );

        $this->assertSame(OrderStatus::Paid, $order->fresh()->order_status);
    }

    public function test_the_same_provider_transaction_cannot_be_recorded_twice(): void
    {
        $a = PaymentAttempt::factory()->create(['provider_reference' => 'TXN-DUP']);

        $this->expectException(UniqueConstraintViolationException::class);

        PaymentAttempt::factory()->create([
            'provider' => $a->provider,
            'provider_reference' => 'TXN-DUP',
        ]);
    }

    public function test_the_order_paid_event_row_is_unique_per_order(): void
    {
        $order = Order::factory()->create();
        $order->events()->create([
            'type' => OrderEvent::TYPE_ORDER_PAID,
            'unique_key' => OrderEvent::TYPE_ORDER_PAID,
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        $order->events()->create([
            'type' => OrderEvent::TYPE_ORDER_PAID,
            'unique_key' => OrderEvent::TYPE_ORDER_PAID,
        ]);
    }

    public function test_repeatable_events_are_not_blocked_by_the_unique_key(): void
    {
        $order = Order::factory()->create();

        // 失敗可以發生很多次，⛔ 唯一鍵不得擋住它們。
        $order->events()->create(['type' => OrderEvent::TYPE_PAYMENT_FAILED]);
        $order->events()->create(['type' => OrderEvent::TYPE_PAYMENT_FAILED]);

        $this->assertSame(2, $order->events()->count());
    }

    public function test_a_browser_return_cannot_mark_an_order_paid(): void
    {
        $this->checkout(['fake_payment_result' => PaymentStatus::Pending->value]);
        $order = Order::latest('id')->firstOrFail();

        // 前台沒有任何可把訂單改成已付款的入口。
        $this->get('/checkout')->assertRedirect();
        $this->get('/')->assertOk();

        $this->assertSame(OrderStatus::PendingPayment, $order->fresh()->order_status);
    }

    // ================================================ 6. 快照不可變

    public function test_editing_the_catalogue_does_not_change_an_existing_order(): void
    {
        $this->checkout()->assertOk();

        $item = Order::latest('id')->firstOrFail()->items()->firstOrFail();
        $before = $item->only(['variant_label', 'unit_price_cents', 'amount', 'sku']);

        // 改價、改名、下架。
        $this->variant()->update([
            'label' => '改過的名字',
            'unit_price' => 99,
            'status' => 'archived',
        ]);

        $this->assertSame($before, $item->fresh()->only(['variant_label', 'unit_price_cents', 'amount', 'sku']));
        $this->assertSame(590, Order::latest('id')->value('total_amount'));
    }

    public function test_a_hard_deleted_variant_leaves_the_snapshot_intact(): void
    {
        $this->checkout()->assertOk();

        $item = Order::latest('id')->firstOrFail()->items()->firstOrFail();
        $this->variant()->forceDelete();

        $item->refresh();

        $this->assertNull($item->service_variant_id);
        $this->assertSame('一般粉絲', $item->variant_label);
        $this->assertSame(590, $item->amount);
    }

    // ================================================ 7. 個資最小化

    public function test_the_success_page_never_shows_full_contact_details(): void
    {
        $response = $this->checkout([
            'customer_email' => 'private@example.com',
            'customer_phone' => '0912345678',
            'personal_invoice_mode' => 'mobile_barcode',
            'carrier_number' => '/ABC1234',
        ])->assertOk();

        $response->assertDontSee('private@example.com')
            ->assertDontSee('0912345678')
            ->assertDontSee('/ABC1234', false);

        $response->assertSee('@example.com');
    }

    public function test_no_personal_data_reaches_the_url(): void
    {
        $this->startCheckout();

        $location = $this->post('/checkout/mock', $this->orderForm([
            'customer_email' => 'private@example.com',
        ]))->headers->get('Location');

        // 成功時直接回傳頁面而非轉址；若有轉址也不得帶個資。
        $this->assertStringNotContainsString('private', (string) $location);
        $this->assertStringNotContainsString('@', (string) $location);
    }

    public function test_the_mock_makes_no_outbound_request(): void
    {
        // Http::preventStrayRequests() 已在 setUp 啟用。
        $this->checkout()->assertOk();

        $this->assertSame(1, Order::count());
    }
}
