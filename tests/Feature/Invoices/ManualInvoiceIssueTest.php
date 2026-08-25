<?php

namespace Tests\Feature\Invoices;

use App\Actions\Invoices\IssueInvoice;
use App\Actions\Invoices\QueueInvoiceRecoveryForOrder;
use App\Enums\IntegrationEnvironment;
use App\Enums\IntegrationProvider;
use App\Enums\InvoiceAttemptStatus;
use App\Enums\InvoiceFailureReason;
use App\Enums\InvoiceStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\AdminAuditLog;
use App\Models\IntegrationSetting;
use App\Models\Invoice;
use App\Models\InvoiceAttempt;
use App\Models\Order;
use App\Models\User;
use App\Services\Invoices\EcpayInvoiceGateway;
use Ecpay\Sdk\Services\AesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Concerns\ConfiguresLiveIntegrations;
use Tests\TestCase;

/**
 * D-179：Owner 手動開立發票，永遠使用同一個 RelateNumber。
 *
 * Owner 在 staging 遇到的實況：綠界那邊已經開立成功，本站卻停在「需人工對帳」。
 * 舊行為讓這種訂單永遠出不去——全站沒有後續查詢路徑，手動入口又明確拒絕該狀態。
 *
 * D-179 的規則很簡單：付款成功自動開票，成功即 `issued`，其餘一律 `failed`；
 * Owner 可按「手動開立發票」再送一次。安全性不靠「禁止重送」，而靠**同一個
 * `RelateNumber`**：
 *
 *   - 若先前其實沒開成功 → 這次是正常的一次開立。
 *   - 若先前其實已經開出 → 綠界以重複號拒絕，gateway 隨即以同號 GetIssue
 *     查回那張既有發票並收斂為 `issued`。
 *
 * ⛔ 因此重送的最壞情況是把既有發票查回來，不是替同一張訂單開出第二張發票。
 * 這正是綠界官方對 `RelateNumber` 「唯一且不可重複」規定的用法。
 *
 * ⛔ 全程 fake HTTP，0 真實 Issue／GetIssue。
 */
class ManualInvoiceIssueTest extends TestCase
{
    use ConfiguresLiveIntegrations;
    use RefreshDatabase;

    private const MERCHANT = '2000132';

    private const HASH_KEY = 'ejCk326UnaZWKisg';

    private const HASH_IV = 'q9jcZX8Ib9LM8wYk';

    private const ISSUE = 'https://einvoice.ecpay.com.tw/B2CInvoice/Issue';

    private const QUERY = 'https://einvoice.ecpay.com.tw/B2CInvoice/GetIssue';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->runningAsLiveSite();

        $setting = IntegrationSetting::factory()
            ->forProvider(IntegrationProvider::EcpayInvoice, IntegrationEnvironment::Production)
            ->create(['identifier' => self::MERCHANT]);

        $setting->credentials = ['HashKey' => self::HASH_KEY, 'HashIV' => self::HASH_IV];
        $setting->save();

        DB::table('integration_settings')->where('id', $setting->id)->update(['is_enabled' => true]);
    }

    // ==================================== helpers

    private function aes(): AesService
    {
        return new AesService(self::HASH_KEY, self::HASH_IV);
    }

    private function owner(): User
    {
        return User::factory()->create(['role' => 'owner', 'is_active' => true]);
    }

    private function paidOrder(int $amount = 590): Order
    {
        return Order::factory()->create([
            'order_status' => OrderStatus::Paid,
            'payment_status' => PaymentStatus::Succeeded,
            'total_amount' => $amount,
            'paid_at' => now(),
            'customer_email' => 'invoice-test@example.test',
            'invoice_kind' => 'personal',
            'personal_invoice_mode' => 'email',
        ])->fresh();
    }

    private function invoiceFor(Order $order, InvoiceStatus $status = InvoiceStatus::Pending): Invoice
    {
        return Invoice::factory()->create([
            'order_id' => $order->id,
            'amount' => (int) $order->total_amount,
            'status' => $status,
        ]);
    }

    /** @param array<string, mixed> $inner */
    private function reply(array $inner, mixed $transCode = 1): array
    {
        return [
            'MerchantID' => self::MERCHANT,
            'RpHeader' => ['Timestamp' => 1755400000],
            'TransCode' => $transCode,
            'TransMsg' => '',
            'Data' => $this->aes()->encrypt($inner),
        ];
    }

    /** @param array<string, mixed> $overrides */
    private function successInner(array $overrides = []): array
    {
        return array_merge([
            'RtnCode' => 1,
            'RtnMsg' => '開立發票成功',
            'InvoiceNo' => 'AB12345678',
            'InvoiceDate' => '2026-08-17 10:30:00',
            'RandomNumber' => '1234',
        ], $overrides);
    }

    /**
     * 官方 GetIssue 成功欄位——與 Issue 完全不同的 schema。
     *
     * @param  array<string, mixed>  $overrides
     */
    private function queryInner(Order $order, array $overrides = []): array
    {
        return array_merge([
            'RtnCode' => 1,
            'RtnMsg' => '查詢成功',
            'IIS_Mer_ID' => self::MERCHANT,
            'IIS_Number' => 'AB12345678',
            'IIS_Relate_Number' => $this->relateNumber($order),
            'IIS_Create_Date' => '2026-08-17 10:30:00',
            'IIS_Random_Number' => '1234',
            'IIS_Issue_Status' => '1',
            'IIS_Invalid_Status' => '0',
        ], $overrides);
    }

    private function relateNumber(Order $order): string
    {
        return preg_replace('/[^A-Za-z0-9]/', '', (string) $order->reference) ?? '';
    }

    /** 解出某次 request 送出的 RelateNumber。 */
    private function sentRelateNumber(Request $request): ?string
    {
        return $this->aes()->decrypt($request->data()['Data'])['RelateNumber'] ?? null;
    }

    /** @return list<string> 每一次 Issue request 送出的 RelateNumber。 */
    private function issuedRelateNumbers(): array
    {
        $numbers = [];

        foreach (Http::recorded() as [$request]) {
            if (str_contains($request->url(), '/Issue')) {
                $numbers[] = $this->sentRelateNumber($request);
            }
        }

        return $numbers;
    }

    // ==================================== 1. 自動開票成功

    public function test_a_successful_automatic_issue_records_the_invoice(): void
    {
        $order = $this->paidOrder();
        $invoice = $this->invoiceFor($order);

        Http::fake([self::ISSUE => Http::response($this->reply($this->successInner()))]);

        $invoice = app(IssueInvoice::class)->handle($invoice);

        $this->assertSame(InvoiceStatus::Issued, $invoice->status);
        $this->assertSame('AB12345678', $invoice->invoice_number);
        $this->assertSame('1234', $invoice->random_code);
        // ⛔ provider reference 就是 RelateNumber：唯一在重送與查詢時都不變的鍵。
        $this->assertSame($this->relateNumber($order), $invoice->provider_reference);
        // ⛔ 使用綠界自己的開立時間，不是本站的時鐘。
        $this->assertSame('2026-08-17 10:30:00', $invoice->issued_at->format('Y-m-d H:i:s'));

        Http::assertSentCount(1);
        $this->assertSame([$this->relateNumber($order)], $this->issuedRelateNumbers());
    }

    // ==================================== 2. 不明結果收斂為 failed（非 reconciliation）

    /**
     * ⭐ D-179 的核心改變。
     *
     * ⛔ 舊行為停在 `reconciliation_required` 而全站沒有出口。現在收斂為
     * `failed`，Owner 才能用手動入口再走一次同號流程。
     */
    public function test_an_unknown_issue_result_converges_to_failed_not_reconciliation(): void
    {
        $order = $this->paidOrder();
        $invoice = $this->invoiceFor($order);

        Http::fake([
            // 綠界回一個我們無法判定為成功的結果。
            self::ISSUE => Http::response($this->reply(['RtnCode' => 9999, 'RtnMsg' => 'x'])),
            // 同號查詢當下也查不到。
            self::QUERY => Http::response(['MerchantID' => self::MERCHANT, 'TransCode' => 0], 200),
        ]);

        $invoice = app(IssueInvoice::class)->handle($invoice);

        $this->assertSame(InvoiceStatus::Failed, $invoice->status);
        $this->assertNull($invoice->reconciliation_required_at);
        $this->assertNull($invoice->invoice_number);
        $this->assertSame(InvoiceAttemptStatus::Failed, $invoice->attempts()->first()->status);

        // ⛔ 恰好一次 Issue、一次 GetIssue；不得自動重送。
        Http::assertSentCount(2);
        $this->assertSame([$this->relateNumber($order)], $this->issuedRelateNumbers());
    }

    /** ⛔ 收斂為 failed 之後不得自動再送：重送只能由 Owner 觸發。 */
    public function test_a_failed_invoice_is_never_resent_automatically(): void
    {
        $order = $this->paidOrder();
        $invoice = $this->invoiceFor($order);

        Http::fake([
            self::ISSUE => Http::response($this->reply(['RtnCode' => 9999, 'RtnMsg' => 'x'])),
            self::QUERY => Http::response(['MerchantID' => self::MERCHANT, 'TransCode' => 0], 200),
        ]);

        app(IssueInvoice::class)->handle($invoice);
        $countAfterFirst = count(Http::recorded());

        // 再呼叫一次同一個 action：狀態已是 failed，compare-and-set 不會認領。
        app(IssueInvoice::class)->handle($invoice->fresh());

        $this->assertSame($countAfterFirst, count(Http::recorded()), '⛔ 不得自動重送。');
        $this->assertSame(1, InvoiceAttempt::count());
    }

    // ==================================== 3. Owner 手動重送成功

    /**
     * 手動重送成功：同一張 invoice 改 `issued`、新增一筆 attempt、
     * ⛔ RelateNumber 與首次完全相同。
     */
    public function test_a_manual_retry_succeeds_with_the_same_relate_number(): void
    {
        $order = $this->paidOrder();
        $invoice = $this->invoiceFor($order);

        /*
         * ⛔ 一次把兩個階段都設定好，不在中途重新 `Http::fake()`。
         *
         * 重新呼叫 `Http::fake()` 會把先前錄到的 request 一併清掉，於是
         * `issuedRelateNumbers()` 只看得到後半段——那正是「所有嘗試都用同一個
         * RelateNumber」這條斷言最需要看到全部的地方。改用一個有狀態的 closure：
         * 第一次 Issue 回不明結果，第二次（Owner 手動重送）才回成功。
         */
        $issueCalls = 0;

        Http::fake([
            self::ISSUE => function () use (&$issueCalls) {
                $issueCalls++;

                return $issueCalls === 1
                    ? Http::response($this->reply(['RtnCode' => 9999, 'RtnMsg' => 'x']))
                    : Http::response($this->reply($this->successInner()));
            },
            // 第一次的同號查詢當下查不到。
            self::QUERY => Http::response(['MerchantID' => self::MERCHANT, 'TransCode' => 0], 200),
        ]);

        app(IssueInvoice::class)->handle($invoice);
        $this->assertSame(InvoiceStatus::Failed, $invoice->fresh()->status);

        /*
         * ⛔ 只呼叫 action，不另外手動呼叫 IssueInvoice：測試環境的 queue 是
         * `sync`，dispatch 當下 job 就跑完了。再呼叫一次等於測一個現實中不會
         * 發生的第三次嘗試。
         */
        $outcome = app(QueueInvoiceRecoveryForOrder::class)->handle($this->owner(), $order);
        $this->assertSame('queued', $outcome);

        $invoice = $invoice->fresh();

        $this->assertSame(InvoiceStatus::Issued, $invoice->status);
        $this->assertSame('AB12345678', $invoice->invoice_number);

        // ⛔ 只有一張 invoice，兩筆 attempt（歷史保留）。
        $this->assertSame(1, Invoice::count());
        $this->assertSame(2, $invoice->attempts()->count());

        // ⛔ 兩次送出的 RelateNumber 完全相同。
        $this->assertSame(
            [$this->relateNumber($order)],
            array_unique($this->issuedRelateNumbers()),
            '⛔ 所有嘗試必須使用同一個 RelateNumber。'
        );
    }

    /**
     * ⭐ Owner 實測情境：第一次其實已在綠界開出，本站卻沒採信。
     *
     * 手動重送時綠界以重複號拒絕，gateway 隨即以**同一個號** GetIssue 查詢，
     * 查到既有發票並收斂為 `issued`。
     *
     * ⛔ 全程只有一張 invoice、同一個 RelateNumber；⛔ 不得為了繞過重複錯誤
     * 而產生新號——那正是同一張訂單開出兩張發票的方法。
     */
    public function test_a_manual_retry_recovers_an_invoice_that_was_already_issued_at_ecpay(): void
    {
        $order = $this->paidOrder();
        $invoice = $this->invoiceFor($order);

        /*
         * ⛔ 一次設定完兩個階段，不中途重新 `Http::fake()`（那會清掉已錄到的
         * request，讓「所有嘗試同號」的斷言只看得到後半段）。
         *
         * Issue 兩次都不成功：第一次是無法判讀的結果，第二次是綠界以重複的
         * RelateNumber 拒絕——因為那個號實際上已經開出去了。
         *
         * GetIssue 則模擬綠界的查詢可見性延遲：第一次查不到，第二次查得到。
         */
        $queryCalls = 0;

        Http::fake([
            self::ISSUE => Http::response($this->reply([
                'RtnCode' => 9999,
                'RtnMsg' => 'duplicate relate number',
            ])),
            self::QUERY => function () use (&$queryCalls, $order) {
                $queryCalls++;

                return $queryCalls === 1
                    ? Http::response(['MerchantID' => self::MERCHANT, 'TransCode' => 0], 200)
                    : Http::response($this->reply($this->queryInner($order)));
            },
        ]);

        app(IssueInvoice::class)->handle($invoice);
        $this->assertSame(InvoiceStatus::Failed, $invoice->fresh()->status);

        // Owner 手動重送；queue 為 sync，dispatch 當下就完成這一次同號重送。
        app(QueueInvoiceRecoveryForOrder::class)->handle($this->owner(), $order);
        $invoice = $invoice->fresh();

        // ⛔ 收斂為已開立，資料來自綠界那張既有發票。
        $this->assertSame(InvoiceStatus::Issued, $invoice->status);
        $this->assertSame('AB12345678', $invoice->invoice_number);
        $this->assertSame('1234', $invoice->random_code);
        $this->assertSame($this->relateNumber($order), $invoice->provider_reference);
        $this->assertSame('2026-08-17 10:30:00', $invoice->issued_at->format('Y-m-d H:i:s'));

        // ⛔ 全程只有一張 invoice。
        $this->assertSame(1, Invoice::count());

        // ⛔ 每一次送往綠界的 RelateNumber 都相同。
        $this->assertSame(
            [$this->relateNumber($order)],
            array_unique($this->issuedRelateNumbers())
        );
    }

    /** 手動再次失敗仍是 `failed`，且可以再由 Owner 操作一次。 */
    public function test_a_manual_retry_that_fails_again_stays_failed_and_remains_actionable(): void
    {
        $order = $this->paidOrder();
        $invoice = $this->invoiceFor($order);

        Http::fake([
            self::ISSUE => Http::response($this->reply(['RtnCode' => 9999, 'RtnMsg' => 'x'])),
            self::QUERY => Http::response(['MerchantID' => self::MERCHANT, 'TransCode' => 0], 200),
        ]);

        app(IssueInvoice::class)->handle($invoice);

        // queue 為 sync：這一次重送當下就跑完，結果仍是失敗。
        app(QueueInvoiceRecoveryForOrder::class)->handle($this->owner(), $order);
        $invoice = $invoice->fresh();

        $this->assertSame(InvoiceStatus::Failed, $invoice->status);
        $this->assertSame(2, $invoice->attempts()->count());

        // ⛔ 仍然可以再操作一次：不得把 Owner 鎖死。
        Queue::fake();
        $this->assertSame(
            'queued',
            app(QueueInvoiceRecoveryForOrder::class)->handle($this->owner(), $order)
        );
    }

    // ==================================== 4. 冪等鍵與雙擊

    /**
     * 每一輪至多一筆 attempt，且鍵是穩定推導的。
     *
     * ⛔ 不得用時間或隨機值：同一次重送的兩個 worker 會各拿到一個不同的鍵，
     * unique index 兩個都收，於是兩者都真的呼叫綠界。
     */
    public function test_attempt_keys_are_derived_from_the_attempt_ordinal(): void
    {
        $order = $this->paidOrder();
        $invoice = $this->invoiceFor($order);

        $this->assertSame(
            $invoice->initialIdempotencyKey(),
            $invoice->idempotencyKeyForNextAttempt()
        );

        InvoiceAttempt::create([
            'invoice_id' => $invoice->id,
            'idempotency_key' => $invoice->initialIdempotencyKey(),
            'status' => InvoiceAttemptStatus::Failed,
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        $second = $invoice->fresh()->idempotencyKeyForNextAttempt();

        $this->assertSame(sprintf('inv-%d-%d-manual-2', $invoice->id, $invoice->amount), $second);
        // ⛔ 穩定：同一個狀態連算兩次必須相同。
        $this->assertSame($second, $invoice->fresh()->idempotencyKeyForNextAttempt());
    }

    /** Owner 雙擊只排入一個有效重試。 */
    public function test_a_double_click_queues_only_one_retry(): void
    {
        Queue::fake();

        $order = $this->paidOrder();
        $invoice = $this->invoiceFor($order, InvoiceStatus::Failed);
        InvoiceAttempt::create([
            'invoice_id' => $invoice->id,
            'idempotency_key' => $invoice->initialIdempotencyKey(),
            'status' => InvoiceAttemptStatus::Failed,
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        $owner = $this->owner();
        $action = app(QueueInvoiceRecoveryForOrder::class);

        $this->assertSame('queued', $action->handle($owner, $order));

        /*
         * 第二次點擊：invoice 已被原子轉成 pending 且已有 attempt，
         * ⛔ 因此不再合格——雙擊不會排入第二個有效重試。
         */
        $this->assertSame('blocked_not_eligible', $action->handle($owner, $order));
    }

    /** ⛔ 兩個 worker 同時處理同一張 pending invoice，只有一個能呼叫綠界。 */
    public function test_two_workers_yield_exactly_one_provider_call(): void
    {
        $order = $this->paidOrder();
        $invoice = $this->invoiceFor($order);

        Http::fake([self::ISSUE => Http::response($this->reply($this->successInner()))]);

        app(IssueInvoice::class)->handle($invoice);
        // 第二個 worker 拿到的是同一列，但狀態已不是 pending。
        app(IssueInvoice::class)->handle($invoice->fresh());

        Http::assertSentCount(1);
        $this->assertSame(1, InvoiceAttempt::count());
    }

    // ==================================== 5. 授權與稽核

    public function test_a_non_owner_cannot_trigger_a_manual_issue(): void
    {
        Queue::fake();

        $order = $this->paidOrder();
        $this->invoiceFor($order, InvoiceStatus::Failed);

        $editor = User::factory()->create(['role' => 'editor', 'is_active' => true]);

        $this->assertSame(
            'blocked_not_owner',
            app(QueueInvoiceRecoveryForOrder::class)->handle($editor, $order)
        );
        Queue::assertNothingPushed();
    }

    public function test_an_unpaid_order_cannot_be_issued(): void
    {
        Queue::fake();

        $order = Order::factory()->create([
            'order_status' => OrderStatus::PendingPayment,
            'total_amount' => 590,
        ]);

        $this->assertSame(
            'blocked_unpaid',
            app(QueueInvoiceRecoveryForOrder::class)->handle($this->owner(), $order)
        );
        Queue::assertNothingPushed();
    }

    /** ⛔ 稽核寫不進去就不排：沒有紀錄的手動開票不得發生。 */
    public function test_an_audit_failure_blocks_the_manual_issue(): void
    {
        Queue::fake();

        $order = $this->paidOrder();
        $this->invoiceFor($order, InvoiceStatus::Failed);

        // ⛔ 先取得 owner，再註冊會丟例外的 listener：User::factory() 本身
        // 會寫一筆 audit，順序顛倒會讓例外在參數求值時就炸開。
        $owner = $this->owner();

        AdminAuditLog::creating(function (): void {
            throw new RuntimeException('audit unavailable');
        });

        $this->assertSame(
            'blocked_audit_unavailable',
            app(QueueInvoiceRecoveryForOrder::class)->handle($owner, $order)
        );
        Queue::assertNothingPushed();
    }

    /**
     * ⭐ R1 反證：稽核失敗必須把**發票狀態**也一起回滾。
     *
     * ⛔ 初版 `34513a6` 在這裡卡死：狀態轉換先 commit，之後才寫 audit。audit
     * 失敗時 action 回傳 blocked 且 0 dispatch，看起來像安全地擋下了——但發票
     * 已經是 `pending` 而且**已經有 attempt**，於是 `isRecoverable()` 的
     * 「pending 必須 0 attempt」永遠不成立，Owner 再也按不動那個按鈕。
     *
     * 結果是最糟的一種組合：沒有稽核、沒有 job、沒有 provider call，發票卻
     * 永久卡住。⛔ 舊測試只驗 `Queue::assertNothingPushed()`，那個綠燈完全
     * 證明不了 fail closed。
     *
     * fail closed 的正確定義是「什麼都沒發生」，不只是「沒有排 job」。
     */
    public function test_an_audit_failure_leaves_a_failed_invoice_completely_unchanged(): void
    {
        Queue::fake();
        Http::fake();

        $order = $this->paidOrder();
        $invoice = $this->invoiceFor($order, InvoiceStatus::Failed);
        $invoice->forceFill([
            'failure_code' => InvoiceFailureReason::Unknown->value,
            'failure_message' => InvoiceFailureReason::Unknown->message(),
        ])->save();

        InvoiceAttempt::create([
            'invoice_id' => $invoice->id,
            'idempotency_key' => $invoice->initialIdempotencyKey(),
            'status' => InvoiceAttemptStatus::Failed,
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        $owner = $this->owner();

        // 呼叫前的完整快照。
        $before = $invoice->fresh()->toArray();
        $attemptsBefore = $invoice->attempts()->get()->toArray();
        $auditsBefore = AdminAuditLog::count();

        AdminAuditLog::creating(function (): void {
            throw new RuntimeException('audit unavailable');
        });

        $outcome = app(QueueInvoiceRecoveryForOrder::class)->handle($owner, $order);

        $this->assertSame('blocked_audit_unavailable', $outcome);
        Queue::assertNothingPushed();
        Http::assertNothingSent();

        // ⛔ 逐欄位原樣：狀態、failure 欄位、時間戳全部不得被改動。
        $this->assertSame($before, $invoice->fresh()->toArray(), '⛔ 稽核失敗時發票不得被改動。');
        $this->assertSame($attemptsBefore, $invoice->attempts()->get()->toArray());
        $this->assertSame($auditsBefore, AdminAuditLog::count(), '⛔ 不得留下半筆稽核。');

        /*
         * ⭐ 最關鍵的一條：Owner 仍然按得動。
         *
         * 這正是初版失敗的地方——發票被留在「pending＋已有 attempt」，資格判定
         * 永久拒絕，Owner 永遠再也按不動。回滾之後它仍是 `failed`，所以仍然
         * 合格；⛔ 這裡必須實際再呼叫一次證明，不能只斷言狀態看起來還對。
         *
         * 移除會丟例外的 listener，模擬「稽核系統恢復之後」。
         */
        AdminAuditLog::flushEventListeners();

        $this->assertSame(
            'queued',
            app(QueueInvoiceRecoveryForOrder::class)->handle($owner, $order),
            '⛔ 稽核失敗之後 Owner 必須仍能再次操作，不得被永久鎖死。'
        );
    }

    /**
     * ⭐ R1 反證：`reconciliation_required` 同樣必須完整回滾。
     *
     * ⛔ 這個狀態的回滾比 `failed` 更容易做錯：初版是「先轉 failed 再轉
     * pending」兩步，所以一個只回滾了一半的實作可能把發票留在 `failed`，
     * 並且已經清掉 `reconciliation_required_at`。那會靜默地改寫 staging 上
     * 那筆真實資料的狀態，而 Owner 什麼都沒做成。
     */
    public function test_an_audit_failure_leaves_a_reconciliation_required_invoice_completely_unchanged(): void
    {
        Queue::fake();
        Http::fake();

        $order = $this->paidOrder();
        $invoice = $this->invoiceFor($order, InvoiceStatus::ReconciliationRequired);
        $invoice->forceFill([
            'failure_code' => InvoiceFailureReason::Unknown->value,
            'failure_message' => InvoiceFailureReason::Unknown->message(),
            'reconciliation_required_at' => now(),
        ])->save();

        InvoiceAttempt::create([
            'invoice_id' => $invoice->id,
            'idempotency_key' => $invoice->initialIdempotencyKey(),
            'status' => InvoiceAttemptStatus::Ambiguous,
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        $owner = $this->owner();

        $before = $invoice->fresh()->toArray();
        $attemptsBefore = $invoice->attempts()->get()->toArray();
        $auditsBefore = AdminAuditLog::count();

        AdminAuditLog::creating(function (): void {
            throw new RuntimeException('audit unavailable');
        });

        $outcome = app(QueueInvoiceRecoveryForOrder::class)->handle($owner, $order);

        $this->assertSame('blocked_audit_unavailable', $outcome);
        Queue::assertNothingPushed();
        Http::assertNothingSent();

        $after = $invoice->fresh();

        // ⛔ 不得被留在中途的 `failed`，也不得已經是 `pending`。
        $this->assertSame(InvoiceStatus::ReconciliationRequired, $after->status);
        // ⛔ 時間戳不得被清掉：那是這筆資料為何需要人工處理的唯一線索。
        $this->assertNotNull($after->reconciliation_required_at);
        $this->assertSame($before, $after->toArray(), '⛔ 稽核失敗時發票不得被改動。');
        $this->assertSame($attemptsBefore, $invoice->attempts()->get()->toArray());
        $this->assertSame($auditsBefore, AdminAuditLog::count());
    }

    // ==================================== 6. 安全：不落盤 provider 原文

    /**
     * ⛔ 綠界回應中的任何原文都不得落盤。
     *
     * failure code／message 一律來自本地 allowlist，型別就是保證。
     */
    public function test_no_provider_text_reaches_the_database(): void
    {
        $poison = 'MerchantID=2000132 HashKey='.self::HASH_KEY.' secret-buyer@example.test';

        $order = $this->paidOrder();
        $invoice = $this->invoiceFor($order);

        Http::fake([
            self::ISSUE => Http::response($this->reply(['RtnCode' => 9999, 'RtnMsg' => $poison])),
            self::QUERY => Http::response(['MerchantID' => self::MERCHANT, 'TransCode' => 0], 200),
        ]);

        app(IssueInvoice::class)->handle($invoice);

        $raw = json_encode([
            DB::table('invoices')->get(),
            DB::table('invoice_attempts')->get(),
        ], JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString($poison, (string) $raw);
        $this->assertStringNotContainsString(self::HASH_KEY, (string) $raw);
        $this->assertStringNotContainsString('secret-buyer@example.test', (string) $raw);
    }

    /** RelateNumber 由 order reference 推導，⛔ 每次呼叫都必須相同。 */
    public function test_the_relate_number_is_stable_for_one_order(): void
    {
        $order = $this->paidOrder();
        $invoice = $this->invoiceFor($order);

        $a = EcpayInvoiceGateway::relateNumberFor($invoice);
        $b = EcpayInvoiceGateway::relateNumberFor($invoice->fresh());

        $this->assertSame($a, $b);
        $this->assertSame($this->relateNumber($order), $a);
        // ⛔ 綠界限制 30 個英數字。
        $this->assertLessThanOrEqual(30, strlen($a));
        $this->assertMatchesRegularExpression('/\A[A-Za-z0-9]+\z/', $a);
    }
}
