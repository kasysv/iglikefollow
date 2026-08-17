<?php

namespace Tests\Feature\Fulfillment;

use App\Actions\Fulfillment\PrepareFulfillmentForOrder;
use App\Actions\Fulfillment\SubmitFulfillment;
use App\Actions\Fulfillment\SyncFulfillmentState;
use App\Data\Fulfillment\FulfillmentSubmissionResult;
use App\Enums\FulfillmentEventCode;
use App\Enums\FulfillmentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\AdminAuditLog;
use App\Models\FulfillmentEvent;
use App\Models\FulfillmentMapping;
use App\Models\FulfillmentOrder;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ServiceVariant;
use App\Models\User;
use App\Services\Fulfillment\FakeFulfillmentGateway;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * The five gaps GPT's probes found in the first M4A build.
 *
 * Each one is reproduced here the way the probe reproduced it — at the raw
 * database level where an observer cannot help — so that a future change which
 * reopens the gap fails loudly.
 */
class FulfillmentIntegrityClosureTest extends TestCase
{
    use RefreshDatabase;

    private const MARKER = 'FAKE-MARKER-SERVICE-77';

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
        OrderItem::factory()->create(['order_id' => $order->id, 'service_variant_id' => $variant->id]);
        FulfillmentMapping::factory()->enabled()->create(['service_variant_id' => $variant->id]);

        return app(PrepareFulfillmentForOrder::class)->handle($order)[0];
    }

    private function submitted(): FulfillmentOrder
    {
        return (new SubmitFulfillment(new FakeFulfillmentGateway))->handle($this->readyFulfillment());
    }

    // ============================ 缺口 1：mapping 稽核存在且不洩漏

    public function test_creating_a_mapping_is_audited(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $this->actingAs($owner);

        $mapping = FulfillmentMapping::factory()->create();

        $log = AdminAuditLog::query()
            ->where('auditable_type', FulfillmentMapping::class)
            ->where('auditable_id', $mapping->id)
            ->sole();

        $this->assertSame('created', $log->action);
        // 操作者身分必須留下來。
        $this->assertSame($owner->id, $log->user_id);
    }

    public function test_updating_a_mapping_is_audited(): void
    {
        $mapping = FulfillmentMapping::factory()->create();

        $mapping->update(['is_enabled' => true]);

        $this->assertSame(2, AdminAuditLog::query()
            ->where('auditable_type', FulfillmentMapping::class)->count());
    }

    public function test_the_audit_records_the_safe_fields(): void
    {
        $mapping = FulfillmentMapping::factory()->create();

        // ⛔ 限定 auditable_type：其他 model 的稽核也用同一個 id 空間。
        $log = AdminAuditLog::query()
            ->where('auditable_type', FulfillmentMapping::class)
            ->where('auditable_id', $mapping->id)
            ->sole();

        $after = is_array($log->after) ? $log->after : json_decode((string) $log->after, true);

        // 這些欄位有留下來，稽核才有意義。
        $this->assertArrayHasKey('service_variant_id', $after);
        $this->assertArrayHasKey('provider', $after);
        $this->assertArrayHasKey('is_enabled', $after);
    }

    public function test_the_service_id_never_reaches_the_audit_log(): void
    {
        $mapping = FulfillmentMapping::factory()->create(['provider_service_id' => self::MARKER]);
        $mapping->update(['provider_service_id' => 'FAKE-MARKER-CHANGED-88']);

        $dump = json_encode(AdminAuditLog::all()->toArray(), JSON_UNESCAPED_UNICODE);

        // ⛔ 稽核紀錄在後台可讀；供應商代碼不得因為稽核而擴散。
        $this->assertStringNotContainsString(self::MARKER, (string) $dump);
        $this->assertStringNotContainsString('FAKE-MARKER-CHANGED-88', (string) $dump);
        // 但「有變更過」這件事必須看得出來。
        $this->assertStringContainsString('[redacted]', (string) $dump);
    }

    public function test_the_service_id_is_not_in_the_raw_audit_table(): void
    {
        FulfillmentMapping::factory()->create(['provider_service_id' => self::MARKER]);

        $raw = json_encode(DB::table('admin_audit_logs')->get(), JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString(self::MARKER, (string) $raw);
    }

    // ============================ 缺口 2：sync 不得倒退

    public static function impossibleProviderStatusProvider(): array
    {
        return [
            'ready' => [FulfillmentStatus::Ready],
            'submitting' => [FulfillmentStatus::Submitting],
            'configuration pending' => [FulfillmentStatus::ConfigurationPending],
            'submission unknown' => [FulfillmentStatus::SubmissionUnknown],
        ];
    }

    /**
     * ⛔ 這四個狀態是我們描述自己處境的詞，供應商不可能回報。
     *
     * 接受其中之一會讓畸形回應把已送出的列倒退回可再次送出的狀態——那正是
     * 同一筆商品被下第二次單的路徑。
     */
    #[DataProvider('impossibleProviderStatusProvider')]
    public function test_a_provider_cannot_rewind_the_row(FulfillmentStatus $status): void
    {
        $row = $this->submitted();

        $gateway = (new FakeFulfillmentGateway)->willSync($status);
        $synced = (new SyncFulfillmentState($gateway))->handle($row);

        $this->assertSame(FulfillmentStatus::Submitted, $synced->status);

        $codes = $synced->events->pluck('event_code')->map(fn ($c) => $c->value)->all();
        $this->assertContains(FulfillmentEventCode::StatusUnrecognised->value, $codes);
    }

    public function test_a_legal_forward_sync_still_works(): void
    {
        $row = $this->submitted();

        $synced = (new SyncFulfillmentState((new FakeFulfillmentGateway)->willSync(FulfillmentStatus::Processing)))
            ->handle($row);

        $this->assertSame(FulfillmentStatus::Processing, $synced->status);
    }

    public function test_syncing_the_same_status_adds_no_event(): void
    {
        $row = $this->submitted();
        $before = $row->events()->count();

        $synced = (new SyncFulfillmentState((new FakeFulfillmentGateway)->willSync(FulfillmentStatus::Submitted)))
            ->handle($row);

        $this->assertSame(FulfillmentStatus::Submitted, $synced->status);
        $this->assertSame($before, $synced->events()->count());
        $this->assertNotNull($synced->last_synced_at);
    }

    /**
     * ⛔ A slow worker must not overwrite a decision made while it waited.
     */
    public function test_a_stale_worker_cannot_overwrite_a_terminal_state(): void
    {
        $row = $this->submitted();

        // 這個 worker 手上是舊的快照。
        $stale = FulfillmentOrder::find($row->id);

        // 期間另一個 worker 已經把它同步成完成。
        (new SyncFulfillmentState((new FakeFulfillmentGateway)->willSync(FulfillmentStatus::Completed)))
            ->handle($row);

        // 舊 worker 現在才帶著「處理中」回來。
        $result = (new SyncFulfillmentState((new FakeFulfillmentGateway)->willSync(FulfillmentStatus::Processing)))
            ->handle($stale);

        $this->assertSame(FulfillmentStatus::Completed, $result->fresh()->status);
    }

    // ============================ 缺口 3：DB 拒絕不可逆倒退

    public static function illegalTransitionProvider(): array
    {
        return [
            'completed to ready' => [FulfillmentStatus::Completed, FulfillmentStatus::Ready],
            'completed to submitting' => [FulfillmentStatus::Completed, FulfillmentStatus::Submitting],
            'failed to ready' => [FulfillmentStatus::Failed, FulfillmentStatus::Ready],
            'canceled to ready' => [FulfillmentStatus::Canceled, FulfillmentStatus::Ready],
            'partial to processing' => [FulfillmentStatus::Partial, FulfillmentStatus::Processing],
            'submitted to ready' => [FulfillmentStatus::Submitted, FulfillmentStatus::Ready],
            'submitted to configuration' => [FulfillmentStatus::Submitted, FulfillmentStatus::ConfigurationPending],
            'processing to submitting' => [FulfillmentStatus::Processing, FulfillmentStatus::Submitting],
        ];
    }

    /** ⛔ raw SQL 也必須被擋：observer 可以被繞過，trigger 不行。 */
    #[DataProvider('illegalTransitionProvider')]
    public function test_the_database_refuses_an_illegal_transition(
        FulfillmentStatus $from,
        FulfillmentStatus $to,
    ): void {
        $row = $this->submitted();
        DB::table('fulfillment_orders')->where('id', $row->id)->update(['status' => $from->value]);

        $this->expectException(QueryException::class);

        DB::table('fulfillment_orders')->where('id', $row->id)->update(['status' => $to->value]);
    }

    #[DataProvider('illegalTransitionProvider')]
    public function test_the_model_refuses_an_illegal_transition(
        FulfillmentStatus $from,
        FulfillmentStatus $to,
    ): void {
        $row = $this->submitted();
        DB::table('fulfillment_orders')->where('id', $row->id)->update(['status' => $from->value]);

        $this->expectException(RuntimeException::class);

        $row->fresh()->forceFill(['status' => $to])->save();
    }

    public static function legalTransitionProvider(): array
    {
        return [
            'submitted to processing' => [FulfillmentStatus::Submitted, FulfillmentStatus::Processing],
            'submitted to completed' => [FulfillmentStatus::Submitted, FulfillmentStatus::Completed],
            'processing to completed' => [FulfillmentStatus::Processing, FulfillmentStatus::Completed],
            'processing to partial' => [FulfillmentStatus::Processing, FulfillmentStatus::Partial],
            'processing to failed' => [FulfillmentStatus::Processing, FulfillmentStatus::Failed],
        ];
    }

    /** 合法的前進必須照常通過：守衛不能把正常流程也擋掉。 */
    #[DataProvider('legalTransitionProvider')]
    public function test_a_legal_transition_is_allowed(
        FulfillmentStatus $from,
        FulfillmentStatus $to,
    ): void {
        $row = $this->submitted();
        DB::table('fulfillment_orders')->where('id', $row->id)->update(['status' => $from->value]);

        DB::table('fulfillment_orders')->where('id', $row->id)->update(['status' => $to->value]);

        $this->assertSame($to, $row->fresh()->status);
    }

    /**
     * ⛔ 最危險的一種倒退：不明 → 可再次送出。
     *
     * `submission_unknown` 的意思是「對方可能已經成立了」。讓它回到 ready
     * 就是讓系統再下一次單。這裡從真正走到該狀態的列開始測，而不是用 raw
     * update 硬造一個。
     */
    public function test_an_unknown_row_can_never_be_made_submittable_again(): void
    {
        $row = $this->readyFulfillment();
        $result = (new SubmitFulfillment((new FakeFulfillmentGateway)->willBeUnknown()))->handle($row);

        $this->assertSame(FulfillmentStatus::SubmissionUnknown, $result->status);

        foreach ([FulfillmentStatus::Ready, FulfillmentStatus::Submitting] as $target) {
            try {
                DB::table('fulfillment_orders')->where('id', $row->id)
                    ->update(['status' => $target->value]);
                $this->fail("不明狀態不得回到 {$target->value}");
            } catch (QueryException) {
                // 期望如此。
            }
        }

        $this->assertSame(FulfillmentStatus::SubmissionUnknown, $row->fresh()->status);
    }

    public function test_staying_in_the_same_status_is_allowed(): void
    {
        $row = $this->submitted();

        DB::table('fulfillment_orders')->where('id', $row->id)
            ->update(['status' => FulfillmentStatus::Submitted->value]);

        $this->assertSame(FulfillmentStatus::Submitted, $row->fresh()->status);
    }

    // ============================ 缺口 4：空的 provider order ID

    public static function unusableIdentifierProvider(): array
    {
        return [
            'empty' => [''],
            'whitespace' => ['   '],
            'null' => [null],
            'with spaces' => ['ORDER 123'],
            'html' => ['<html>error</html>'],
            'sentence' => ['Something went wrong, please retry'],
            'too long' => [str_repeat('A', 65)],
        ];
    }

    /**
     * ⛔ 沒有可用單號的「成立」不是成立。
     *
     * 記成 submitted 會留下一筆宣稱已派單、卻沒有任何東西可以拿去問對方的
     * 紀錄——永遠無法對帳。但呼叫確實發生過，對方那邊可能真的成立了，
     * 所以安全答案是不明，不是失敗，更不是重送。
     */
    #[DataProvider('unusableIdentifierProvider')]
    public function test_an_unusable_identifier_is_not_an_acceptance(?string $id): void
    {
        $result = FulfillmentSubmissionResult::accepted($id);

        $this->assertFalse($result->isAccepted());
        $this->assertTrue($result->isUnknown());
        $this->assertFalse($result->isRejected());
    }

    public function test_a_usable_identifier_is_still_accepted(): void
    {
        $result = FulfillmentSubmissionResult::accepted('FAKE-123_ab.c');

        $this->assertTrue($result->isAccepted());
        $this->assertSame('FAKE-123_ab.c', $result->providerOrderId);
    }

    public function test_an_identifier_is_trimmed(): void
    {
        $this->assertSame('FAKE-9', FulfillmentSubmissionResult::accepted('  FAKE-9  ')->providerOrderId);
    }

    public function test_the_database_refuses_a_blank_identifier_on_a_submitted_row(): void
    {
        $row = $this->submitted();

        $this->expectException(QueryException::class);

        DB::table('fulfillment_orders')->where('id', $row->id)->update(['provider_order_id' => '   ']);
    }

    public function test_the_database_refuses_a_submitted_row_with_no_identifier(): void
    {
        $row = FulfillmentOrder::factory()->ready()->create();

        $this->expectException(QueryException::class);

        DB::table('fulfillment_orders')->where('id', $row->id)->update([
            'status' => FulfillmentStatus::Submitted->value,
            'provider_order_id' => '',
        ]);
    }

    // ============================ 缺口 5：回寫失敗後安全收斂

    /**
     * ⛔ 對方已經接受，但我們寫不進去。
     *
     * 這是最危險的一種情況：例外直接逃出會讓列永遠停在 submitting，看起來
     * 像「還在送」，實際上供應商可能已經成立並且要收費。
     */
    public function test_a_persistence_failure_after_acceptance_converges_to_unknown(): void
    {
        $row = $this->readyFulfillment();
        $gateway = new FakeFulfillmentGateway;

        // 讓寫入 SUBMITTED 事件的那一步失敗。
        FulfillmentEvent::creating(function (FulfillmentEvent $event) {
            // ⛔ event_code 已 cast 成 enum：與字串比對永遠不成立，
            // 那會讓這個測試安靜地什麼都沒模擬到。
            if ($event->event_code === FulfillmentEventCode::Submitted) {
                throw new RuntimeException('simulated write failure');
            }
        });

        try {
            $result = (new SubmitFulfillment($gateway))->handle($row);
        } finally {
            FulfillmentEvent::flushEventListeners();
        }

        // ⛔ gateway 只被呼叫一次，絕不重送。
        $this->assertCount(1, $gateway->submissions);
        // ⛔ 收斂成需要人工對帳，而不是卡在 submitting。
        $this->assertSame(FulfillmentStatus::SubmissionUnknown, $result->fresh()->status);
    }

    public function test_the_converged_row_keeps_no_raw_exception_text(): void
    {
        $row = $this->readyFulfillment();

        FulfillmentEvent::creating(function (FulfillmentEvent $event) {
            if ($event->event_code === FulfillmentEventCode::Submitted) {
                throw new RuntimeException('provider_key=SECRET simulated failure');
            }
        });

        try {
            (new SubmitFulfillment(new FakeFulfillmentGateway))->handle($row);
        } finally {
            FulfillmentEvent::flushEventListeners();
        }

        $dump = json_encode(DB::table('fulfillment_orders')->get(), JSON_UNESCAPED_UNICODE);

        // ⛔ 例外訊息不得落盤。
        $this->assertStringNotContainsString('provider_key=SECRET', (string) $dump);
        $this->assertStringNotContainsString('simulated failure', (string) $dump);
    }

    // ============================ 缺口 5b：timeline 真正 append-only

    public function test_the_database_refuses_to_delete_an_event(): void
    {
        $row = $this->submitted();
        $event = $row->events()->first();

        // ⛔ 初版只擋 UPDATE，DELETE 是敞開的。
        $this->expectException(QueryException::class);

        DB::table('fulfillment_events')->where('id', $event->id)->delete();
    }

    public function test_the_database_refuses_to_update_an_event(): void
    {
        $row = $this->submitted();
        $event = $row->events()->first();

        $this->expectException(QueryException::class);

        DB::table('fulfillment_events')->where('id', $event->id)
            ->update(['event_code' => FulfillmentEventCode::Created->value]);
    }

    public function test_the_model_refuses_to_delete_an_event(): void
    {
        $row = $this->submitted();

        $this->expectException(RuntimeException::class);

        $row->events()->first()->delete();
    }

    public function test_the_model_refuses_to_update_an_event(): void
    {
        $row = $this->submitted();

        $this->expectException(RuntimeException::class);

        $row->events()->first()->update(['to_status' => FulfillmentStatus::Completed->value]);
    }

    /**
     * ⛔ 父列不得把時間線悄悄帶走。
     *
     * FK 已由 cascade 改為 restrict：有時間線的履約列根本刪不掉，這正是
     * M4A 想要的結果——沒有任何合法路徑會刪除履約列。
     */
    public function test_a_fulfillment_row_with_a_timeline_cannot_be_deleted(): void
    {
        $row = $this->submitted();

        $this->expectException(QueryException::class);

        DB::table('fulfillment_orders')->where('id', $row->id)->delete();
    }

    public function test_every_integrity_trigger_is_present(): void
    {
        $expected = [
            'fulfillment_orders_transition_guard',
            'fulfillment_orders_identifier_guard_insert',
            'fulfillment_orders_identifier_guard_update',
            'fulfillment_events_append_only_update',
            'fulfillment_events_append_only_delete',
            'fulfillment_events_values_check_insert',
            'fulfillment_orders_values_check_insert',
            'fulfillment_orders_values_check_update',
            'fulfillment_mappings_values_check_insert',
            'fulfillment_mappings_values_check_update',
        ];

        $present = DB::table('sqlite_master')->where('type', 'trigger')->pluck('name')->all();

        /*
         * ⛔ SQLite 改 FK 會重建整張表，連帶把它的 trigger 全部丟掉。
         *
         * R1 施工時就真的發生過：加上 DELETE 守衛的同時，靜靜弄丟了既有的
         * UPDATE 與 values_check 守衛。這個測試讓那件事不可能再悄悄通過。
         */
        foreach ($expected as $trigger) {
            $this->assertContains($trigger, $present, "缺少保護：{$trigger}");
        }
    }
}
