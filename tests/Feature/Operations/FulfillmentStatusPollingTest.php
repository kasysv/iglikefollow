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

    /**
     * eligibility 閉集:submitted／pending／processing 且有 provider ID。
     *
     * ⛔⛔ `Pending` 必須在內。這條測試原本的 fixture 從未建立 Pending 列,
     * 所以挑選器漏掉 Pending 時測試照樣綠——那正是「很多訂單不更新」的成因。
     * 停止查詢的只有 terminal:completed、partial、canceled、failed,
     * 以及需要人工的 submission_unknown。
     */
    public function test_only_eligible_rows_are_queued(): void
    {
        $submitted = FulfillmentOrder::factory()->submitted('62001')->create();
        $pending = FulfillmentOrder::factory()->submitted('62009')->create();
        $pending->forceFill(['status' => FulfillmentStatus::Pending])->save();
        $processing = FulfillmentOrder::factory()->submitted('62002')->create();
        $processing->forceFill(['status' => FulfillmentStatus::Processing])->save();

        // 不可排入的:terminal、無 provider ID、unknown、configuration_pending。
        $completed = FulfillmentOrder::factory()->submitted('62003')->create();
        $completed->forceFill(['status' => FulfillmentStatus::Completed])->save();
        $partial = FulfillmentOrder::factory()->submitted('62004')->create();
        $partial->forceFill(['status' => FulfillmentStatus::Partial])->save();
        $canceled = FulfillmentOrder::factory()->submitted('62005')->create();
        $canceled->forceFill(['status' => FulfillmentStatus::Canceled])->save();
        $noId = FulfillmentOrder::factory()->create(); // 預設無 provider_order_id
        // submission_unknown 走合法轉移路徑(observer/DB trigger 都在守)。
        $unknown = FulfillmentOrder::factory()->ready()->create();
        $unknown->forceFill(['status' => FulfillmentStatus::Submitting, 'attempt_count' => 1])->save();
        $unknown->forceFill(['status' => FulfillmentStatus::SubmissionUnknown])->save();

        $queued = $this->inStaging(fn () => app(QueueFulfillmentStatusSync::class)->handle());

        $this->assertSame(3, $queued);
        Queue::assertPushed(SyncFulfillmentStatus::class, 3);
        Queue::assertPushed(fn (SyncFulfillmentStatus $job) => $job->fulfillmentOrderId === $submitted->id);
        Queue::assertPushed(fn (SyncFulfillmentStatus $job) => $job->fulfillmentOrderId === $pending->id);
        Queue::assertPushed(fn (SyncFulfillmentStatus $job) => $job->fulfillmentOrderId === $processing->id);
    }

    /** ⛔ 挑選器與執行者必須讀同一份清單,⛔ 不得各寫一份。 */
    public function test_the_picker_uses_the_same_syncable_list_as_the_sync_action(): void
    {
        $ids = [];

        foreach (FulfillmentStatus::syncableSources() as $i => $status) {
            $row = FulfillmentOrder::factory()->submitted('6410'.$i)->create();

            if ($status !== FulfillmentStatus::Submitted) {
                $row->forceFill(['status' => $status])->save();
            }

            $ids[] = $row->id;
        }

        $queued = $this->inStaging(fn () => app(QueueFulfillmentStatusSync::class)->handle());

        $this->assertSame(count(FulfillmentStatus::syncableSources()), $queued);

        foreach ($ids as $id) {
            Queue::assertPushed(fn (SyncFulfillmentStatus $job) => $job->fulfillmentOrderId === $id);
        }
    }

    /**
     * ⛔⛔ 活躍單超過上限時,多輪之後每一筆都必須被查到。
     *
     * 原本 `orderBy('id')` 每輪都挑同樣 id 最小的那幾筆,第上限＋1 筆以後
     * 永遠輪不到。現在最久沒查的先查;這裡模擬 sync job 每次都會更新
     * `last_synced_at`(實際行為見 SyncFulfillmentState)。
     */
    public function test_every_active_row_is_eventually_polled_when_over_the_batch_limit(): void
    {
        config()->set('fulfillment.status_polling_batch_limit', 3);

        $ids = [];
        for ($i = 1; $i <= 7; $i++) {
            $ids[] = FulfillmentOrder::factory()->submitted('65'.$i)->create()->id;
        }

        $seen = [];

        for ($round = 1; $round <= 3; $round++) {
            Queue::fake();

            $this->travel(10)->minutes();

            $this->inStaging(fn () => app(QueueFulfillmentStatusSync::class)->handle());

            $picked = [];
            Queue::assertPushed(SyncFulfillmentStatus::class, function (SyncFulfillmentStatus $job) use (&$picked) {
                $picked[] = $job->fulfillmentOrderId;

                return true;
            });

            // 模擬 sync 完成:每一筆被查過的都會更新 last_synced_at。
            FulfillmentOrder::query()->whereIn('id', $picked)->update(['last_synced_at' => now()]);

            foreach ($picked as $id) {
                $seen[$id] = true;
            }
        }

        $this->assertSame([], array_values(array_diff($ids, array_keys($seen))), '每一筆活躍單都必須在數輪內被查到。');
    }

    /** 從沒查過的(last_synced_at 為 NULL)排在已查過的前面。 */
    public function test_never_synced_rows_are_polled_before_recently_synced_ones(): void
    {
        config()->set('fulfillment.status_polling_batch_limit', 1);

        $old = FulfillmentOrder::factory()->submitted('66001')->create();
        $old->forceFill(['last_synced_at' => now()->subMinute()])->save();

        $fresh = FulfillmentOrder::factory()->submitted('66002')->create(); // 從沒查過

        $this->inStaging(fn () => app(QueueFulfillmentStatusSync::class)->handle());

        Queue::assertPushed(SyncFulfillmentStatus::class, 1);
        Queue::assertPushed(fn (SyncFulfillmentStatus $job) => $job->fulfillmentOrderId === $fresh->id);
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
