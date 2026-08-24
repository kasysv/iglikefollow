<?php

namespace Tests\Feature\Fulfillment;

use App\Actions\Fulfillment\PrepareFulfillmentForOrder;
use App\Actions\Fulfillment\SubmitFulfillment;
use App\Actions\Fulfillment\SyncFulfillmentState;
use App\Actions\Orders\RecordPaymentResult;
use App\Contracts\FulfillmentGateway;
use App\Contracts\TheMostPanelDispatchCredentialSource;
use App\Enums\FulfillmentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Jobs\SubmitFulfillmentOrder;
use App\Models\FulfillmentMapping;
use App\Models\FulfillmentOrder;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentAttempt;
use App\Models\ServiceVariant;
use App\Services\Fulfillment\TheMostPanelCurlCapability;
use App\Services\Fulfillment\TheMostPanelFulfillmentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ConfiguresLiveIntegrations;
use Tests\TestCase;

/**
 * The REAL adapter driven through the M3A/M4A pipeline with a fake HTTP
 * layer — both as direct action calls (fast, per-branch) and as the true
 * cross-seam path: trusted payment result → OrderPaid (after commit) →
 * queue listener → prepare → claim → one `add` → provider order id.
 *
 * ⛔ This is the M3A/M4A pipeline driven through TheMostPanelFulfillmentGateway
 * instead of the in-memory Fake — same actions, same jobs, same guards — with
 * every HTTP response faked in-process. It proves our side of the documented
 * contract seam; it proves nothing about the real provider.
 */
class TheMostPanelDispatchAdapterTest extends TestCase
{
    use ConfiguresLiveIntegrations;
    use RefreshDatabase;

    public const ENDPOINT = 'https://themostpanel.com/api/v2';

    public const KEY = 'FAKE-DISPATCH-KEY-MARKER-777001';

    public const TARGET = 'https://example.invalid/fictional-customer-post';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        // testing-only wiring:driver=themostpanel 只有 testing 環境成立。
        config()->set('fulfillment.driver', 'themostpanel');
        $this->enableDispatchSwitch();
        config()->set('integrations.endpoints.themostpanel.production', self::ENDPOINT);

        $this->app->bind(
            TheMostPanelDispatchCredentialSource::class,
            fn () => new class implements TheMostPanelDispatchCredentialSource
            {
                public function apiKey(): ?string
                {
                    return TheMostPanelDispatchAdapterTest::KEY;
                }
            },
        );

        // 測試 runtime(libcurl 7.85)沒有 native cap;注入 supported 假能力。
        $this->app->bind(TheMostPanelCurlCapability::class, fn () => TheMostPanelCurlCapability::supported());
    }

    /**
     * 一張已付款、mapping 齊全(enabled)、開關開啟的訂單。
     *
     * ⛔ direct action seam:直接 factory Paid＋呼叫 Prepare,適合逐分支
     * 測試;真正跨付款事件的證據見 cross-seam 測試區。
     */
    private function readyFulfillment(string $providerServiceId = '4501'): FulfillmentOrder
    {
        $variant = ServiceVariant::factory()->create();

        FulfillmentMapping::factory()->create([
            'service_variant_id' => $variant->id,
            'provider_service_id' => $providerServiceId,
            'is_enabled' => true,
        ]);

        $order = Order::factory()->create([
            'order_status' => OrderStatus::Paid,
            'payment_status' => PaymentStatus::Succeeded,
            'total_amount' => 590,
            'paid_at' => now(),
        ]);

        OrderItem::factory()->create([
            'order_id' => $order->id,
            'service_variant_id' => $variant->id,
            'target_value' => self::TARGET,
            'quantity' => 1000,
        ]);

        [$fulfillment] = app(PrepareFulfillmentForOrder::class)->handle($order->fresh());

        return $fulfillment->fresh();
    }

    private function submit(FulfillmentOrder $fulfillment): FulfillmentOrder
    {
        return app(SubmitFulfillment::class)->handle($fulfillment);
    }

    // ==================================== 綁定本身

    public function test_the_container_resolves_the_real_adapter_in_this_wiring(): void
    {
        $this->assertInstanceOf(TheMostPanelFulfillmentGateway::class, app(FulfillmentGateway::class));
    }

    // ==================================== happy path

    public function test_a_paid_order_submits_one_add_and_stores_only_the_provider_order_id(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['order' => 23501])]);

        $fulfillment = $this->readyFulfillment();
        $result = $this->submit($fulfillment);

        $this->assertSame(FulfillmentStatus::Submitted, $result->status);
        $this->assertSame('23501', $result->provider_order_id);
        Http::assertSentCount(1);

        /*
         * ⛔ target 只在送出的那個 request 存在;不進 fulfillment tables、
         * events 或其他持久層。
         */
        foreach (['fulfillment_orders', 'fulfillment_events'] as $table) {
            foreach (DB::table($table)->get() as $row) {
                $this->assertStringNotContainsString(self::TARGET, json_encode((array) $row));
                $this->assertStringNotContainsString(self::KEY, json_encode((array) $row));
            }
        }
    }

    // ==================================== 不該送的都不送

    public function test_an_unpaid_order_produces_no_fulfillment_and_no_http(): void
    {
        Http::fake();

        $variant = ServiceVariant::factory()->create();
        FulfillmentMapping::factory()->create([
            'service_variant_id' => $variant->id,
            'provider_service_id' => '4501',
            'is_enabled' => true,
        ]);

        $order = Order::factory()->create([
            'order_status' => OrderStatus::PendingPayment,
            'payment_status' => PaymentStatus::Pending,
            'total_amount' => 590,
        ]);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'service_variant_id' => $variant->id,
            'target_value' => self::TARGET,
        ]);

        $rows = app(PrepareFulfillmentForOrder::class)->handle($order->fresh());

        $this->assertSame([], $rows);
        $this->assertSame(0, FulfillmentOrder::query()->count());
        Http::assertNothingSent();
    }

    public function test_a_disabled_mapping_never_reaches_the_network(): void
    {
        Http::fake();

        // ⛔ M4A 語意:mapping 在 prepare 時檢查;disabled → configuration_pending。
        $variant = ServiceVariant::factory()->create();
        FulfillmentMapping::factory()->create([
            'service_variant_id' => $variant->id,
            'provider_service_id' => '4501',
            'is_enabled' => false,
        ]);

        $order = Order::factory()->create([
            'order_status' => OrderStatus::Paid,
            'payment_status' => PaymentStatus::Succeeded,
            'total_amount' => 590,
            'paid_at' => now(),
        ]);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'service_variant_id' => $variant->id,
            'target_value' => self::TARGET,
        ]);

        app(PrepareFulfillmentForOrder::class)->handle($order->fresh());

        $row = FulfillmentOrder::query()->firstOrFail();
        $this->assertSame(FulfillmentStatus::ConfigurationPending, $row->status);

        // 即使有人硬把這列丟去 submit,claim 也擋下,一個 request 都不送。
        $result = $this->submit($row);

        $this->assertNotSame(FulfillmentStatus::Submitted, $result->status);
        Http::assertNothingSent();
    }

    public function test_dispatch_off_never_reaches_the_network(): void
    {
        Http::fake();

        // ⛔ claim 之後、送出之前開關被關掉:收斂回 configuration_pending。
        $fulfillment = $this->readyFulfillment();
        // ⛔ R1:關閉派單＝Owner 在後台停用總開關,不是改已 deprecated 的 env 旗標。
        DB::table('integration_settings')->where('provider', 'themostpanel')->update(['is_enabled' => false]);

        $result = $this->submit($fulfillment);

        $this->assertSame(FulfillmentStatus::ConfigurationPending, $result->status);
        Http::assertNothingSent();
    }

    // ==================================== 冪等:永不第二次 add

    public function test_a_duplicate_submit_never_sends_a_second_add(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['order' => 23501])]);

        $fulfillment = $this->readyFulfillment();
        $this->submit($fulfillment);
        $this->submit($fulfillment->fresh());

        Http::assertSentCount(1);
    }

    public function test_a_duplicate_queue_job_never_sends_a_second_add(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['order' => 23501])]);

        $fulfillment = $this->readyFulfillment();

        (new SubmitFulfillmentOrder($fulfillment->id))->handle(app(SubmitFulfillment::class));
        (new SubmitFulfillmentOrder($fulfillment->id))->handle(app(SubmitFulfillment::class));

        Http::assertSentCount(1);
        $this->assertSame('23501', $fulfillment->fresh()->provider_order_id);
    }

    /** ⛔ add 是花錢的 job:tries 必須是 1,失敗永不自動重試。 */
    public function test_the_submit_job_never_retries(): void
    {
        $this->assertSame(1, (new SubmitFulfillmentOrder(1))->tries);
    }

    // ==================================== unknown 收斂

    public function test_a_timeout_converges_to_submission_unknown_without_resend(): void
    {
        Http::fake(fn () => throw new ConnectionException('fictional timeout'));

        $fulfillment = $this->readyFulfillment();
        $result = $this->submit($fulfillment);

        $this->assertSame(FulfillmentStatus::SubmissionUnknown, $result->status);
        $this->assertNull($result->provider_order_id);

        // ⛔ unknown 之後再 submit:狀態已不是 ready,一個 request 都不再送。
        Http::fake();
        $this->submit($result->fresh());
        Http::assertNothingSent();
    }

    public function test_a_malformed_response_converges_to_submission_unknown(): void
    {
        Http::fake([self::ENDPOINT => Http::response('<html>maintenance</html>')]);

        $fulfillment = $this->readyFulfillment();
        $result = $this->submit($fulfillment);

        $this->assertSame(FulfillmentStatus::SubmissionUnknown, $result->status);
    }

    /** provider 明確 error object → failed;⛔ message 原文不落任何持久層。 */
    public function test_a_provider_error_object_fails_without_storing_the_message(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['error' => 'FICTIONAL-REFUSAL-MARKER-556677'])]);

        $fulfillment = $this->readyFulfillment();
        $result = $this->submit($fulfillment);

        $this->assertSame(FulfillmentStatus::Failed, $result->status);

        foreach (['fulfillment_orders', 'fulfillment_events'] as $table) {
            foreach (DB::table($table)->get() as $row) {
                $this->assertStringNotContainsString('FICTIONAL-REFUSAL-MARKER-556677', json_encode((array) $row));
            }
        }
    }

    /** credential echo → unknown;⛔ key 不進任何持久層。 */
    public function test_a_credential_echo_converges_to_unknown_and_never_persists(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['order' => 23501, 'echo' => self::KEY])]);

        $fulfillment = $this->readyFulfillment();
        $result = $this->submit($fulfillment);

        $this->assertSame(FulfillmentStatus::SubmissionUnknown, $result->status);

        foreach (DB::table('fulfillment_events')->get() as $row) {
            $this->assertStringNotContainsString(self::KEY, json_encode((array) $row));
        }
    }

    // ==================================== status sync

    /** submitted row 直接由 factory 建立,sync 路徑與 add 路徑各自隔離。 */
    private function submittedRow(string $providerOrderId = '23501'): FulfillmentOrder
    {
        return FulfillmentOrder::factory()->submitted($providerOrderId)->create();
    }

    public function test_the_four_documented_statuses_transition_locally(): void
    {
        $expectations = [
            'In progress' => FulfillmentStatus::Processing,
            'Partial' => FulfillmentStatus::Partial,
            'Completed' => FulfillmentStatus::Completed,
            'Rejected' => FulfillmentStatus::Failed,
        ];

        /*
         * ⛔ Http::fake 疊加時第一個 stub 永遠贏(B1 教訓):多回應一律
         * fakeSequence,依序一 push 對一 sync。
         */
        $sequence = Http::fakeSequence(self::ENDPOINT);
        foreach (array_keys($expectations) as $token) {
            $sequence->push(['status' => $token]);
        }

        $nextId = 23501;

        foreach ($expectations as $token => $local) {
            $row = $this->submittedRow((string) $nextId++);

            $synced = app(SyncFulfillmentState::class)->handle($row->fresh());

            $this->assertSame($local, $synced->status, $token);
        }

        Http::assertSentCount(4);
    }

    /** ⛔ 未知 token:維持原狀,只留本地事件,不猜 completed。 */
    public function test_an_unknown_status_token_keeps_the_row_unchanged(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['status' => 'Some Future State'])]);

        $row = $this->submittedRow();
        $synced = app(SyncFulfillmentState::class)->handle($row->fresh());

        $this->assertSame(FulfillmentStatus::Submitted, $synced->status);
        Http::assertSentCount(1);

        // ⛔ provider 原文不落本地事件。
        foreach (DB::table('fulfillment_events')->get() as $event) {
            $this->assertStringNotContainsString('Some Future State', json_encode((array) $event));
        }
    }

    // ==================================== R1:blocked 四分法

    /** ⛔ 0 request 的設定問題 → configuration_pending,不是 failed。 */
    public function test_a_pre_network_block_converges_to_configuration_pending(): void
    {
        Http::fake();

        $fulfillment = $this->readyFulfillment();
        // claim 之後 endpoint 設定消失:adapter 網路前 blocked。
        config()->set('integrations.endpoints.themostpanel.production', '');

        $result = $this->submit($fulfillment);

        $this->assertSame(FulfillmentStatus::ConfigurationPending, $result->status);
        Http::assertNothingSent();
    }

    /** ⛔ credential source 意外 throw:仍是 request 前 → configuration_pending,訊息不落地。 */
    public function test_a_throwing_credential_source_converges_to_configuration_pending(): void
    {
        Http::fake();

        $this->app->bind(
            TheMostPanelDispatchCredentialSource::class,
            fn () => new class implements TheMostPanelDispatchCredentialSource
            {
                public function apiKey(): ?string
                {
                    throw new \RuntimeException('SECRET-EXCEPTION-MARKER-133731');
                }
            },
        );
        $this->app->forgetInstance(FulfillmentGateway::class);

        $result = $this->submit($this->readyFulfillment());

        $this->assertSame(FulfillmentStatus::ConfigurationPending, $result->status);
        Http::assertNothingSent();

        foreach (DB::table('fulfillment_events')->get() as $event) {
            $this->assertStringNotContainsString('SECRET-EXCEPTION-MARKER-133731', json_encode((array) $event));
        }
    }

    /** 四分法一次並列:blocked／rejected／unknown／accepted 各收斂到正確狀態。 */
    public function test_the_four_outcome_semantics_map_to_four_states(): void
    {
        // ⛔ 同測試多回應必用 fakeSequence(fake 疊加時第一個 stub 永遠贏)。
        Http::fakeSequence(self::ENDPOINT)
            ->push(['order' => 61001])
            ->push(['error' => 'fictional'])
            ->push('not-json{');

        // accepted → submitted
        $this->assertSame(FulfillmentStatus::Submitted, $this->submit($this->readyFulfillment())->status);

        // provider rejected → failed
        $this->assertSame(FulfillmentStatus::Failed, $this->submit($this->readyFulfillment())->status);

        // ambiguous → submission_unknown
        $this->assertSame(FulfillmentStatus::SubmissionUnknown, $this->submit($this->readyFulfillment())->status);

        // blocked → configuration_pending(sequence 已空:再有 request 會炸,本身就是 0-request 證明)
        $row = $this->readyFulfillment();
        config()->set('integrations.endpoints.themostpanel.production', '');
        $this->assertSame(FulfillmentStatus::ConfigurationPending, $this->submit($row)->status);
        config()->set('integrations.endpoints.themostpanel.production', self::ENDPOINT);
        Http::assertSentCount(3);
    }

    // ==================================== R1:真正 cross-seam(付款事件 → queue)

    /** pending_payment 訂單＋付款嘗試;⛔ 不以 factory 直接冒充 Paid。 */
    private function pendingOrderWithAttempt(): array
    {
        $variant = ServiceVariant::factory()->create();

        FulfillmentMapping::factory()->create([
            'service_variant_id' => $variant->id,
            'provider_service_id' => '4501',
            'is_enabled' => true,
        ]);

        $order = Order::factory()->create();

        OrderItem::factory()->create([
            'order_id' => $order->id,
            'service_variant_id' => $variant->id,
            'target_value' => self::TARGET,
            'quantity' => 1000,
        ]);

        $attempt = PaymentAttempt::factory()->create(['order_id' => $order->id]);

        return [$order, $attempt];
    }

    /**
     * ⛔ 完整 seam:可信付款成功 → OrderPaid(after commit)→ queue listener
     * → prepare → claim → 恰一次 add → 只保存 provider order ID。
     */
    public function test_a_trusted_payment_success_drives_one_add_through_the_event_and_queue_seam(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['order' => 23501])]);

        [$order, $attempt] = $this->pendingOrderWithAttempt();

        // 付款成功前:fulfillment 0、HTTP 0。
        $this->assertSame(0, FulfillmentOrder::query()->count());
        Http::assertNothingSent();

        app(RecordPaymentResult::class)
            ->handle($attempt, PaymentStatus::Succeeded, 'TXN-E2E-OK');

        // sync queue＋after-commit event 已把整條 seam 跑完。
        $row = FulfillmentOrder::query()->sole();
        $this->assertSame(FulfillmentStatus::Submitted, $row->status);
        $this->assertSame('23501', $row->provider_order_id);
        Http::assertSentCount(1);
    }

    /** ⛔ 同一可信成功重播:不得第二次 add。 */
    public function test_a_replayed_payment_success_never_sends_a_second_add(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['order' => 23501])]);

        [$order, $attempt] = $this->pendingOrderWithAttempt();

        $action = app(RecordPaymentResult::class);
        $action->handle($attempt, PaymentStatus::Succeeded, 'TXN-E2E-DUP');
        $action->handle($attempt->fresh(), PaymentStatus::Succeeded, 'TXN-E2E-DUP');

        $this->assertSame(1, FulfillmentOrder::query()->count());
        $this->assertSame('23501', FulfillmentOrder::query()->sole()->provider_order_id);
        Http::assertSentCount(1);
    }

    /** 失敗／取消付款:不產生 fulfillment,不碰網路。 */
    public function test_a_failed_or_canceled_payment_produces_no_fulfillment_and_no_http(): void
    {
        Http::fake();

        foreach ([PaymentStatus::Failed, PaymentStatus::Canceled] as $status) {
            [$order, $attempt] = $this->pendingOrderWithAttempt();

            app(RecordPaymentResult::class)
                ->handle($attempt, $status, 'TXN-E2E-'.$status->value);
        }

        $this->assertSame(0, FulfillmentOrder::query()->count());
        Http::assertNothingSent();
    }

    /** terminal 狀態不再查詢。 */
    public function test_a_terminal_row_is_not_queried_again(): void
    {
        // sequence 只有一個回應:若 terminal 後仍查詢,sequence 會空掉而失敗。
        Http::fakeSequence(self::ENDPOINT)->push(['status' => 'Completed']);

        $row = $this->submittedRow();
        $completed = app(SyncFulfillmentState::class)->handle($row->fresh());
        $this->assertSame(FulfillmentStatus::Completed, $completed->status);

        $again = app(SyncFulfillmentState::class)->handle($completed->fresh());

        $this->assertSame(FulfillmentStatus::Completed, $again->status);
        Http::assertSentCount(1);
    }
}
