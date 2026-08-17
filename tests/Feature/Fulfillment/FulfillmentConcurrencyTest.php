<?php

namespace Tests\Feature\Fulfillment;

use App\Actions\Fulfillment\PrepareFulfillmentForOrder;
use App\Actions\Fulfillment\SubmitFulfillment;
use App\Enums\FulfillmentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Jobs\PrepareFulfillmentForPaidOrder;
use App\Jobs\SubmitFulfillmentOrder;
use App\Models\FulfillmentMapping;
use App\Models\FulfillmentOrder;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ServiceVariant;
use App\Services\Fulfillment\FakeFulfillmentGateway;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * What happens when the same work arrives twice at once.
 *
 * ⛔ The expensive failure is two submissions for one item: the supplier is
 * paid twice and the customer receives a service they did not buy. Queue
 * uniqueness is only the first layer — these tests exercise the database
 * guarantees underneath it, because a queue lock expires and a worker can
 * always be restarted.
 */
class FulfillmentConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config()->set('fulfillment.driver', 'fake');
        config()->set('fulfillment.dispatch_enabled', true);
    }

    private function readyFulfillment(): FulfillmentOrder
    {
        $variant = ServiceVariant::factory()->create();
        $order = Order::factory()->create([
            'order_status' => OrderStatus::Paid,
            'payment_status' => PaymentStatus::Succeeded,
            'total_amount' => 590,
            'paid_at' => now(),
        ])->fresh();
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'service_variant_id' => $variant->id,
        ]);
        FulfillmentMapping::factory()->enabled()->create(['service_variant_id' => $variant->id]);

        return app(PrepareFulfillmentForOrder::class)->handle($order)[0];
    }

    public function test_two_workers_cannot_both_submit_one_row(): void
    {
        $row = $this->readyFulfillment();

        $first = new FakeFulfillmentGateway;
        $second = new FakeFulfillmentGateway;

        // 兩個 worker 拿到同一列的同一份快照。
        $stale = FulfillmentOrder::find($row->id);

        (new SubmitFulfillment($first))->handle($row);
        (new SubmitFulfillment($second))->handle($stale);

        // ⛔ 只有一個搶到；另一個完全沒有呼叫 gateway。
        $this->assertCount(1, $first->submissions);
        $this->assertCount(0, $second->submissions);
        $this->assertSame(1, $row->fresh()->attempt_count);
    }

    public function test_the_attempt_count_never_double_increments(): void
    {
        $row = $this->readyFulfillment();
        $gateway = new FakeFulfillmentGateway;

        for ($i = 0; $i < 5; $i++) {
            (new SubmitFulfillment($gateway))->handle($row->fresh());
        }

        // ⛔ 一次有效 claim，一次呼叫。
        $this->assertCount(1, $gateway->submissions);
        $this->assertSame(1, $row->fresh()->attempt_count);
    }

    public function test_replaying_order_paid_never_creates_a_second_row(): void
    {
        $variant = ServiceVariant::factory()->create();
        $order = Order::factory()->create([
            'order_status' => OrderStatus::Paid,
            'payment_status' => PaymentStatus::Succeeded,
            'total_amount' => 590,
            'paid_at' => now(),
        ])->fresh();
        OrderItem::factory()->create(['order_id' => $order->id, 'service_variant_id' => $variant->id]);
        FulfillmentMapping::factory()->enabled()->create(['service_variant_id' => $variant->id]);

        $prepare = app(PrepareFulfillmentForOrder::class);

        // 事件重播、job 重試、兩個 worker 並行——全部收斂到同一列。
        for ($i = 0; $i < 5; $i++) {
            (new PrepareFulfillmentForPaidOrder($order->id))->handle($prepare);
        }

        $this->assertSame(1, FulfillmentOrder::count());
    }

    public function test_a_retried_prepare_job_does_not_resubmit_a_sent_row(): void
    {
        $row = $this->readyFulfillment();
        $gateway = new FakeFulfillmentGateway;

        (new SubmitFulfillment($gateway))->handle($row);

        // 準備工作重跑一次：不得再排一個會重送的 submit。
        $order = $row->orderItem->order;
        (new PrepareFulfillmentForPaidOrder($order->id))->handle(app(PrepareFulfillmentForOrder::class));

        (new SubmitFulfillment($gateway))->handle($row->fresh());

        $this->assertCount(1, $gateway->submissions);
        $this->assertSame(FulfillmentStatus::Submitted, $row->fresh()->status);
    }

    public function test_both_jobs_declare_the_unique_contract(): void
    {
        /*
         * ⛔ `uniqueId()` 本身不會取得任何鎖。
         *
         * Laravel 只有在 job 實作 ShouldBeUnique 時才會去讀它，所以少了介面
         * 就等於完全沒有鎖，卻看起來像有。
         */
        $this->assertInstanceOf(ShouldBeUnique::class, new PrepareFulfillmentForPaidOrder(1));
        $this->assertInstanceOf(ShouldBeUnique::class, new SubmitFulfillmentOrder(1));
    }

    public function test_the_submit_job_never_retries(): void
    {
        // ⛔ 這個 job 會花錢下單：自動重試等於可能下第二筆。
        $this->assertSame(1, (new SubmitFulfillmentOrder(1))->tries);
    }

    public function test_the_unique_key_is_stable_across_attempts(): void
    {
        // ⛔ 隨嘗試次數變動的鍵等於沒有鎖：兩個 worker 會算出不同的鍵。
        $this->assertSame(
            (new SubmitFulfillmentOrder(42))->uniqueId(),
            (new SubmitFulfillmentOrder(42))->uniqueId(),
        );

        $this->assertNotSame(
            (new SubmitFulfillmentOrder(42))->uniqueId(),
            (new SubmitFulfillmentOrder(43))->uniqueId(),
        );
    }
}
