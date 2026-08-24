<?php

namespace Tests\Feature\Operations;

use App\Actions\Fulfillment\QueueFulfillmentStatusSync;
use App\Enums\FulfillmentStatus;
use App\Jobs\SyncFulfillmentStatus;
use App\Models\FulfillmentOrder;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ConfiguresLiveIntegrations;
use Tests\TestCase;

/**
 * The status-polling picker: follows the Owner dispatch switch, eligibility closed.
 *
 * ⛔ It queues sync jobs and nothing else — never a provider call from the
 * picker itself, never anything that could resend an `add`.
 */
class FulfillmentStatusPollingTest extends TestCase
{
    use ConfiguresLiveIntegrations;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Queue::fake();
    }

    /**
     * R1:staging＋Owner 總開關開啟＋runtime 支援=輪詢可跑。
     *
     * ⛔ 沒有任何 env 旗標參與;supported runtime 必須明確描述,不能靠跑
     * 測試那台機器的 libcurl 碰巧通過。
     */
    private function inStaging(callable $callback): mixed
    {
        $this->app['env'] = 'staging';
        $this->enableDispatchSwitch();
        $this->withSupportedDispatchRuntime();

        try {
            return $callback();
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    /** ⛔ R1:Owner 總開關關著,staging 也排入 0——沒有獨立的輪詢開關。 */
    public function test_polling_without_the_owner_switch_queues_nothing(): void
    {
        FulfillmentOrder::factory()->submitted('65001')->create();

        $this->app['env'] = 'staging';
        $this->withSupportedDispatchRuntime();
        // ⛔ 沒有開 Owner 總開關;已 deprecated 的舊旗標開到滿也沒有作用。
        config()->set('fulfillment.status_polling_enabled', true);
        config()->set('fulfillment.dispatch_enabled', true);
        config()->set('fulfillment.staging.themostpanel_dispatch_enabled', true);
        $queued = app(QueueFulfillmentStatusSync::class)->handle();
        $this->app['env'] = 'testing';

        $this->assertSame(0, $queued);
        Queue::assertNothingPushed();
    }

    /** ⛔ Owner 關掉總開關後,下一輪立即排入 0;舊輪詢旗標救不回來。 */
    public function test_switching_the_owner_switch_off_stops_the_next_round(): void
    {
        FulfillmentOrder::factory()->submitted('61001')->create();

        $this->app['env'] = 'staging';
        $this->enableDispatchSwitch();
        $this->withSupportedDispatchRuntime();

        DB::table('integration_settings')->where('provider', 'themostpanel')->update(['is_enabled' => false]);
        config()->set('fulfillment.status_polling_enabled', true);

        $queued = app(QueueFulfillmentStatusSync::class)->handle();
        $this->app['env'] = 'testing';

        $this->assertSame(0, $queued);
        Queue::assertNothingPushed();
    }

    /** ⛔ local／testing 永遠排入 0,即使 Owner 總開關開著。 */
    public function test_local_and_testing_queue_nothing_even_with_the_switch_on(): void
    {
        FulfillmentOrder::factory()->submitted('61002')->create();
        $this->enableDispatchSwitch();
        $this->withSupportedDispatchRuntime();
        config()->set('fulfillment.driver', 'fake');

        foreach (['local', 'testing'] as $env) {
            $this->app['env'] = $env;
            $this->assertSame(0, app(QueueFulfillmentStatusSync::class)->handle(), $env);
        }

        $this->app['env'] = 'testing';
        Queue::assertNothingPushed();
    }

    /** R1:production 依同一個 Owner 開關輪詢——不再無條件拒絕。 */
    public function test_production_polls_under_the_same_owner_switch(): void
    {
        FulfillmentOrder::factory()->submitted('61003')->create();
        $this->enableDispatchSwitch();
        $this->withSupportedDispatchRuntime();

        $this->app['env'] = 'production';
        $queued = app(QueueFulfillmentStatusSync::class)->handle();
        $this->app['env'] = 'testing';

        $this->assertSame(1, $queued);
        Queue::assertPushed(SyncFulfillmentStatus::class, 1);
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
