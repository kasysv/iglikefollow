<?php

namespace Tests\Feature\Operations;

use App\Actions\Fulfillment\QueueFulfillmentStatusSync;
use App\Enums\FulfillmentStatus;
use App\Jobs\SyncFulfillmentStatus;
use App\Models\FulfillmentOrder;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The status-polling picker: staging-only, default-off, eligibility closed.
 *
 * ⛔ It queues sync jobs and nothing else — never a provider call from the
 * picker itself, never anything that could resend an `add`.
 */
class FulfillmentStatusPollingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Queue::fake();
    }

    private function inStaging(callable $callback): mixed
    {
        $this->app['env'] = 'staging';
        config()->set('fulfillment.status_polling_enabled', true);
        // R1:polling 也要求 gateway capability gate 成立。
        config()->set('fulfillment.driver', 'themostpanel');
        config()->set('fulfillment.dispatch_enabled', true);
        config()->set('fulfillment.staging.themostpanel_dispatch_enabled', true);

        try {
            return $callback();
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    /** ⛔ R1:polling flag 單獨打開(dispatch gate 未成立)仍排入 0。 */
    public function test_polling_without_the_dispatch_gate_queues_nothing(): void
    {
        FulfillmentOrder::factory()->submitted('65001')->create();

        $this->app['env'] = 'staging';
        config()->set('fulfillment.status_polling_enabled', true);
        // driver/dispatch/staging flag 全部維持 default off。
        $queued = app(QueueFulfillmentStatusSync::class)->handle();
        $this->app['env'] = 'testing';

        $this->assertSame(0, $queued);
        Queue::assertNothingPushed();
    }

    public function test_flag_off_queues_nothing_even_in_staging(): void
    {
        FulfillmentOrder::factory()->submitted('61001')->create();

        $this->app['env'] = 'staging';
        config()->set('fulfillment.status_polling_enabled', false);
        $queued = app(QueueFulfillmentStatusSync::class)->handle();
        $this->app['env'] = 'testing';

        $this->assertSame(0, $queued);
        Queue::assertNothingPushed();
    }

    public function test_local_and_production_queue_nothing_even_with_the_flag_on(): void
    {
        FulfillmentOrder::factory()->submitted('61002')->create();
        config()->set('fulfillment.status_polling_enabled', true);

        foreach (['local', 'production'] as $env) {
            $this->app['env'] = $env;
            $this->assertSame(0, app(QueueFulfillmentStatusSync::class)->handle(), $env);
        }

        $this->app['env'] = 'testing';
        Queue::assertNothingPushed();
    }

    /** eligibility 閉集:只有 submitted／processing 且有 provider ID。 */
    public function test_only_eligible_rows_are_queued(): void
    {
        $submitted = FulfillmentOrder::factory()->submitted('62001')->create();
        $processing = FulfillmentOrder::factory()->submitted('62002')->create();
        $processing->forceFill(['status' => FulfillmentStatus::Processing])->save();

        // 不可排入的:terminal、無 provider ID、unknown、configuration_pending。
        $completed = FulfillmentOrder::factory()->submitted('62003')->create();
        $completed->forceFill(['status' => FulfillmentStatus::Completed])->save();
        $partial = FulfillmentOrder::factory()->submitted('62004')->create();
        $partial->forceFill(['status' => FulfillmentStatus::Partial])->save();
        $noId = FulfillmentOrder::factory()->create(); // 預設無 provider_order_id
        // submission_unknown 走合法轉移路徑(observer/DB trigger 都在守)。
        $unknown = FulfillmentOrder::factory()->ready()->create();
        $unknown->forceFill(['status' => FulfillmentStatus::Submitting, 'attempt_count' => 1])->save();
        $unknown->forceFill(['status' => FulfillmentStatus::SubmissionUnknown])->save();

        $queued = $this->inStaging(fn () => app(QueueFulfillmentStatusSync::class)->handle());

        $this->assertSame(2, $queued);
        Queue::assertPushed(SyncFulfillmentStatus::class, 2);
        Queue::assertPushed(fn (SyncFulfillmentStatus $job) => $job->fulfillmentOrderId === $submitted->id);
        Queue::assertPushed(fn (SyncFulfillmentStatus $job) => $job->fulfillmentOrderId === $processing->id);
    }

    /** 穩定排序＋固定上限。 */
    public function test_the_batch_is_bounded_and_ordered_by_id(): void
    {
        config()->set('fulfillment.status_polling_batch_limit', 3);

        $ids = [];
        for ($i = 1; $i <= 5; $i++) {
            $ids[] = FulfillmentOrder::factory()->submitted('63'.$i)->create()->id;
        }

        $queued = $this->inStaging(fn () => app(QueueFulfillmentStatusSync::class)->handle());

        $this->assertSame(3, $queued);
        sort($ids);
        foreach (array_slice($ids, 0, 3) as $id) {
            Queue::assertPushed(fn (SyncFulfillmentStatus $job) => $job->fulfillmentOrderId === $id);
        }
    }

    /** ⛔ job 是 ShouldBeUnique:同列同時只會有一個;重跑不重複 provider call 由 sync 冪等性保證。 */
    public function test_the_sync_job_is_unique_per_row(): void
    {
        $this->assertContains(
            ShouldBeUnique::class,
            class_implements(SyncFulfillmentStatus::class),
        );
    }

    /** command:gate 關閉時輸出 0 且成功結束(scheduler no-op 語意)。 */
    public function test_the_command_is_a_safe_noop_when_disabled(): void
    {
        FulfillmentOrder::factory()->submitted('64001')->create();

        $this->artisan('fulfillment:queue-status-sync')
            ->expectsOutputToContain('0')
            ->assertSuccessful();

        Queue::assertNothingPushed();
    }
}
