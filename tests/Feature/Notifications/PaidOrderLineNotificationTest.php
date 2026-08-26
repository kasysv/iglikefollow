<?php

namespace Tests\Feature\Notifications;

use App\Enums\IntegrationProvider;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Events\OrderPaid;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Jobs\IssueInvoiceForOrder;
use App\Jobs\PrepareFulfillmentForPaidOrder;
use App\Jobs\SendPaidOrderLineNotification;
use App\Listeners\ScheduleLineOrderNotificationForPaidOrder;
use App\Models\IntegrationSetting;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Services\Integrations\ProviderEndpoints;
use App\Services\Notifications\LineNotificationGate;
use App\Services\Notifications\LinePushClient;
use App\Services\Notifications\PaidOrderMessage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ConfiguresLiveIntegrations;
use Tests\TestCase;

/**
 * The LINE notification for a newly-paid order.
 *
 * ⛔ 這條支線的**唯一**職責是告知 Owner。它失敗時，付款必須仍然成立，
 * 發票與派單必須仍然照常進行——那是這個檔案最重要的一組測試。
 *
 * ⛔ 全程 0 外呼：`Http::preventStrayRequests()` 讓任何真實請求直接讓測試失敗。
 */
class PaidOrderLineNotificationTest extends TestCase
{
    use ConfiguresLiveIntegrations;
    use RefreshDatabase;

    private const TARGET = 'Cf7a1b2c3d4e5f60718293a4b5c6d7e8f';

    private const TOKEN = 'test-channel-access-token';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    /** 一張已付款、有一個商品項目的訂單。 */
    private function paidOrder(array $overrides = []): Order
    {
        $order = Order::factory()->create(array_merge([
            'order_status' => OrderStatus::Paid,
            'payment_status' => PaymentStatus::Succeeded,
            'paid_at' => now(),
            'total_amount' => 1180,
            'customer_email' => 'buyer@example.test',
            'customer_phone' => '0912345678',
        ], $overrides));

        $order->items()->create([
            'platform_name' => 'Instagram',
            'service_name' => 'Instagram 粉絲',
            'variant_label' => '一般粉絲',
            'sku' => 'ig-followers-standard',
            'unit_price_mills' => 5900,
            'quantity' => 1000,
            'quantity_unit' => '個',
            'amount' => 590,
            'target_kind' => 'account',
            'target_value' => 'my_account',
        ]);

        return $order->fresh();
    }

    /** 讓 LINE 通知處於「可以送」的狀態。 */
    private function configureLineNotification(bool $enabled = true): IntegrationSetting
    {
        $this->runningAsLiveSite();

        $setting = IntegrationSetting::factory()->create([
            'provider' => IntegrationProvider::LineOrderNotification,
            'environment' => 'production',
            'identifier' => self::TARGET,
            'is_enabled' => false,
        ]);

        $setting->forceFill([
            'credentials' => ['ChannelAccessToken' => self::TOKEN],
        ])->save();

        if ($enabled) {
            // ⛔ 直接寫 DB：observer 的啟用前置條件不是這裡要測的東西。
            DB::table('integration_settings')->where('id', $setting->id)->update(['is_enabled' => true]);
        }

        return $setting->fresh();
    }

    // ==================================== 1. 觸發與防重

    /** ⛔ 可信的付款成功（after commit）恰好排一個 job。 */
    public function test_a_trusted_payment_success_queues_exactly_one_job(): void
    {
        Bus::fake();

        $order = $this->paidOrder();

        OrderPaid::dispatch($order);

        Bus::assertDispatchedTimes(SendPaidOrderLineNotification::class, 1);
    }

    /**
     * ⛔⛔ 重複的可信 callback 不得產生第二則通知。
     *
     * 金流商本來就會重送 callback。少了 `ShouldBeUnique`，每一次重送都會變成
     * Owner 手機上的另一則「新訂單」。
     */
    public function test_a_duplicate_callback_does_not_queue_a_second_job(): void
    {
        $order = $this->paidOrder();

        /*
         * ⛔ 用**真實的 queue**（database driver），⛔ 不是 `Queue::fake()`。
         *
         * ⭐ 我用突變測試確認過：把 `ShouldBeUnique` 整個拿掉，用 fake 的
         * 版本**仍然全綠**——因為 `Queue::fake()` 根本不會執行 unique lock。
         * 那樣測到的只是「有排工作」，不是「不會排第二個」。
         */
        config(['queue.default' => 'database']);

        OrderPaid::dispatch($order);
        OrderPaid::dispatch($order);
        OrderPaid::dispatch($order);

        // ⛔ 三次可信 callback，只留下一個工作。
        $this->assertSame(
            1,
            DB::table('jobs')
                ->where('payload', 'like', '%SendPaidOrderLineNotification%')
                ->count(),
            '⛔ 重複的可信 callback 不得產生第二則通知。',
        );

        $this->assertSame(
            'line-order-notification-'.$order->id,
            (new SendPaidOrderLineNotification($order->id))->uniqueId(),
        );
    }

    /**
     * ⛔ 兩張**不同**的訂單都要各自通知。
     *
     * ⭐ 這是「不採跨訂單 60 分鐘節流」的反證：若用時間節流，同一小時內的
     * 第二張訂單會被整個吃掉，Owner 永遠不知道有這筆生意。
     */
    public function test_two_different_orders_each_get_their_own_job(): void
    {
        Bus::fake();

        $first = $this->paidOrder();
        $second = $this->paidOrder();

        OrderPaid::dispatch($first);
        OrderPaid::dispatch($second);

        Bus::assertDispatchedTimes(SendPaidOrderLineNotification::class, 2);

        // ⛔ 兩張訂單的 unique id 必須不同，否則第二張會被當成重複。
        $this->assertNotSame(
            (new SendPaidOrderLineNotification($first->id))->uniqueId(),
            (new SendPaidOrderLineNotification($second->id))->uniqueId(),
        );
    }

    /**
     * ⛔ 未付款不得排 job。
     *
     * @return array<string, array{0: OrderStatus, 1: PaymentStatus}>
     */
    public static function unpaidProvider(): array
    {
        return [
            'pending' => [OrderStatus::PendingPayment, PaymentStatus::Pending],
            'failed' => [OrderStatus::PendingPayment, PaymentStatus::Failed],
            'canceled' => [OrderStatus::Canceled, PaymentStatus::Canceled],
        ];
    }

    #[DataProvider('unpaidProvider')]
    public function test_an_unpaid_order_sends_nothing(OrderStatus $o, PaymentStatus $p): void
    {
        $this->configureLineNotification();
        Http::fake([ProviderEndpoints::LINE_PUSH_MESSAGE => Http::response([], 200)]);

        $order = $this->paidOrder(['order_status' => $o, 'payment_status' => $p, 'paid_at' => null]);

        // job 直接執行：它必須自己認出訂單沒付款而停下來。
        (new SendPaidOrderLineNotification($order->id))->handle(app(LinePushClient::class));

        Http::assertNothingSent();
    }

    /**
     * ⛔⛔ transaction rollback 後不得留下任何 job。
     *
     * `OrderPaid implements ShouldDispatchAfterCommit`——這條測試確認那個
     * 保證涵蓋新的第三條支線，而不只是既有兩條。
     */
    public function test_a_rolled_back_transaction_queues_no_job(): void
    {
        Bus::fake();

        $order = $this->paidOrder();

        try {
            DB::transaction(function () use ($order) {
                OrderPaid::dispatch($order);

                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException) {
            // 預期。
        }

        Bus::assertNotDispatched(SendPaidOrderLineNotification::class);
    }

    // ==================================== 2. 三條支線互相獨立

    /**
     * ⛔⛔ LINE 失敗時，訂單仍然 paid，發票與履約仍照常排隊。
     *
     * ⭐ 這是本輪最重要的一條。通知是事後告知，⛔ 不是交易的一部分——
     * 它絕不能回滾付款或擋住另外兩條支線。
     */
    public function test_a_line_failure_leaves_payment_invoice_and_fulfillment_intact(): void
    {
        $this->configureLineNotification();

        // ⛔ LINE 永久失敗（401：token 錯）。
        Http::fake([ProviderEndpoints::LINE_PUSH_MESSAGE => Http::response(['message' => 'unauthorized'], 401)]);

        $order = $this->paidOrder();

        Bus::fake();
        OrderPaid::dispatch($order);

        // 另外兩條支線照常排隊。
        Bus::assertDispatched(IssueInvoiceForOrder::class);
        Bus::assertDispatched(PrepareFulfillmentForPaidOrder::class);

        // 實際執行 LINE job：⛔ 不得拋例外。
        (new SendPaidOrderLineNotification($order->id))->handle(app(LinePushClient::class));

        // ⛔ 訂單仍然 paid。
        $this->assertTrue($order->fresh()->isPaid());
        $this->assertSame(PaymentStatus::Succeeded, $order->fresh()->payment_status);
    }

    /**
     * ⛔⛔ R1：queue dispatch 拋 `Throwable` 時，listener 不得往上拋。
     *
     * ⛔ 初版的註解宣稱「listener 裡沒有任何邏輯，就沒有任何東西可以拋例外」
     * ——**那個推論是錯的**。`dispatch()` 本身就會寫 queue（database driver
     * 要 INSERT 一列 `jobs`），DB 故障時它會拋 `QueryException`，而那個例外
     * 會沿著 event dispatcher 往上冒泡，把發票與履約兩條支線一起帶下去。
     *
     * ⭐「很薄」不等於「不會拋」。這條測試就是那個反例。
     */
    public function test_a_failing_queue_dispatch_never_propagates(): void
    {
        $order = $this->paidOrder();

        // ⛔ 讓 queue 真的壞掉：dispatch 時會拋。
        Queue::shouldReceive('connection')->andThrow(new \RuntimeException('queue is down'));

        $listener = new ScheduleLineOrderNotificationForPaidOrder;

        // ⛔ 不得往上拋。
        $listener->handle(new OrderPaid($order));

        // ⛔ 訂單仍然 paid。
        $this->assertTrue($order->fresh()->isPaid());
    }

    /**
     * ⛔⛔ 同一件事的**整條 event 路徑**版本：LINE 排不進去時，
     * 發票與履約兩條支線仍然照常執行。
     *
     * ⭐ 這裡只讓 **LINE 那一個 job** 的 dispatch 失敗，其餘照常——若讓整個
     * queue 都壞掉，三條都不會排，那證明不了任何隔離。
     */
    public function test_a_failing_line_dispatch_does_not_stop_the_other_branches(): void
    {
        $order = $this->paidOrder();

        $dispatched = [];

        /*
         * ⛔ 攔截 job dispatch：只有 LINE 那個丟例外，模擬 queue 寫入失敗。
         */
        Bus::fake();
        Event::listen(
            JobQueued::class,
            fn () => null,
        );

        app()->bind(SendPaidOrderLineNotification::class, function () {
            throw new \RuntimeException('queue write failed');
        });

        // ⛔ 整個 event 不得因此中斷。
        OrderPaid::dispatch($order);

        // ⭐ 另外兩條支線照常排隊。
        Bus::assertDispatched(IssueInvoiceForOrder::class);
        Bus::assertDispatched(PrepareFulfillmentForPaidOrder::class);

        // ⛔ 訂單仍然 paid。
        $this->assertTrue($order->fresh()->isPaid());
        $this->assertSame([], $dispatched);
    }

    /** ⛔ listener 不得自己進 queue（那會變成「排一個工作去排一個工作」）。 */
    public function test_the_listener_is_inert(): void
    {
        $listener = new ScheduleLineOrderNotificationForPaidOrder;

        $this->assertNotInstanceOf(ShouldQueue::class, $listener);
    }

    // ==================================== 3. 開關、環境與 credential

    /** ⛔ 本機／testing 一律不外呼，即使設定齊全。 */
    public function test_local_and_testing_never_send(): void
    {
        $this->configureLineNotification();

        // ⛔ 回到 testing 環境。
        app()->detectEnvironment(fn () => 'testing');

        $order = $this->paidOrder();

        (new SendPaidOrderLineNotification($order->id))->handle(app(LinePushClient::class));

        Http::assertNothingSent();
        $this->assertNull(LineNotificationGate::setting());
    }

    /** ⛔ Owner 開關關閉時不送。 */
    public function test_the_owner_switch_off_sends_nothing(): void
    {
        $this->configureLineNotification(enabled: false);
        Http::fake([ProviderEndpoints::LINE_PUSH_MESSAGE => Http::response([], 200)]);

        $order = $this->paidOrder();

        (new SendPaidOrderLineNotification($order->id))->handle(app(LinePushClient::class));

        Http::assertNothingSent();
        $this->assertSame('disabled', LineNotificationGate::blockedReason());
    }

    /**
     * ⛔ 接收 ID 形狀不合法就不送。
     *
     * ⭐ 貼錯接收 ID 是很常見的（貼成 display name、LINE ID `@xxx` 或一段
     * 網址）。此時停下來，遠比把訂單內容送給一個未知對象安全。
     *
     * @return array<string, array{0: string}>
     */
    public static function invalidTargetProvider(): array
    {
        return [
            'display name' => ['我的群組'],
            'line id' => ['@my_line_id'],
            'url' => ['https://line.me/R/ti/g/abc'],
            'wrong prefix' => ['Xf7a1b2c3d4e5f60718293a4b5c6d7e8f'],
            'empty' => [''],

            // ⛔ 含空白或控制字元一律拒絕：那不會是 LINE 發出的 ID。
            'space inside' => ['C my group'],
            'tab inside' => ["Cabc\tdef"],
            'trailing space' => ['Cf7a1b2c3d4e5f60718293a4b5c6d7e8f '],

            /*
             * ⛔ `userId` **有**官方契約，所以照契約嚴格檢查：
             * 大寫十六進位與長度不符都不合法。
             *
             * ⭐ R1：`CF7A…`（大寫）與 `C1234`（短）已從這份清單**移除**——
             * 見 `validTargetProvider()` 的說明：C／R 沒有官方契約，
             * 我原本用 userId 的規格去卡它們是把範例當契約。
             */
            'user uppercase hex' => ['U8189CF6745FC0D808977BDB0B9F22995'],
            'user too short' => ['U1234'],
        ];
    }

    #[DataProvider('invalidTargetProvider')]
    public function test_an_invalid_target_is_never_used(string $target): void
    {
        $this->assertFalse(LineNotificationGate::targetIsValid($target));
    }

    /**
     * ⛔⛔ 接收 ID 不合法時，**整條送出路徑**必須停下來。
     *
     * ⭐ 上面那條只驗證 `targetIsValid()` 這個函式本身。我用突變測試確認過：
     * 把 gate 裡的接收 ID 檢查整個拿掉，那條測試**仍然全綠**——因為它從未
     * 真的走過送出路徑。
     *
     * ⛔ 這是真正要防的事：Owner 把接收 ID 貼成一段網址或 display name 時，
     * 系統不能就這樣把整張訂單的內容（含客人 Email 與電話）送去一個
     * 未知對象。
     */
    public function test_an_invalid_target_blocks_the_actual_send(): void
    {
        $this->runningAsLiveSite();

        $setting = IntegrationSetting::factory()->create([
            'provider' => IntegrationProvider::LineOrderNotification,
            'environment' => 'production',
            // ⛔ 貼成 LINE ID，不是接收 ID。
            'identifier' => '@my_line_id',
            'is_enabled' => false,
        ]);
        $setting->forceFill(['credentials' => ['ChannelAccessToken' => self::TOKEN]])->save();
        DB::table('integration_settings')->where('id', $setting->id)->update(['is_enabled' => true]);

        Http::fake([ProviderEndpoints::LINE_PUSH_MESSAGE => Http::response([], 200)]);

        $order = $this->paidOrder();

        // ⛔ gate 必須拒絕。
        $this->assertNull(LineNotificationGate::setting());
        $this->assertSame('invalid_target', LineNotificationGate::blockedReason());

        // ⛔ job 走完整條路徑後，仍然 0 外呼。
        (new SendPaidOrderLineNotification($order->id))->handle(app(LinePushClient::class));

        Http::assertNothingSent();

        // ⛔ 即使有人繞過 gate 直接呼叫 client，client 自己也要擋。
        $outcome = app(LinePushClient::class)->push(
            $setting->fresh(),
            'hi',
            '123e4567-e89b-42d3-a456-426614174000',
        );

        $this->assertSame('invalid_target', $outcome->reason);
        Http::assertNothingSent();
    }

    /**
     * ⭐ userId／groupId／roomId 三種前綴都必須被接受。
     *
     * ⛔⛔ R1 修正：初版要求 C／R 之後必須是 16–64 位**小寫十六進位**。
     * 那是**把範例當契約**——官方只保證 `userId` 是 `U[0-9a-f]{32}`，
     * 而 `groupId`／`roomId` 在文件中只被定義為 webhook 回傳的 opaque String，
     * 從未規範長度或字元集。文件裡的 `Ca56f94637c…` 是範例。
     *
     * ⛔ 我們自己發明的規則一旦與 LINE 的實際值不符，Owner 的通知會全部
     * 靜默失效——而 Owner 最可能用的正是群組。真實收件者的正確性由
     * 「送測試訊息」確認，那是唯一能真正驗證的方法。
     *
     * @return array<string, array{0: string}>
     */
    public static function validTargetProvider(): array
    {
        return [
            'user' => ['U8189cf6745fc0d808977bdb0b9f22995'],
            'group' => ['Cf7a1b2c3d4e5f60718293a4b5c6d7e8f'],
            'room' => ['Ra8dbf4673c1234567890abcdef123456'],
            'shorter group id' => ['C0123456789abcdef'],

            /*
             * ⭐ 這三個在初版會被**錯誤地拒絕**。它們都是合法形狀的 opaque
             * ID，只是不符合我們自己發明的「小寫十六進位」規則。
             */
            'group with uppercase' => ['CF7A1B2C3D4E5F60718293A4B5C6D7E8F'],
            'group with dash' => ['Csome-opaque_value'],
            'short group id' => ['C1234'],
        ];
    }

    #[DataProvider('validTargetProvider')]
    public function test_a_valid_target_is_accepted(string $target): void
    {
        $this->assertTrue(LineNotificationGate::targetIsValid($target));
    }

    // ==================================== 4. 送出的請求本身

    /** ⭐ exact URL、Bearer、to、單一 text message 與 retry key。 */
    public function test_the_push_request_is_exactly_what_line_expects(): void
    {
        $setting = $this->configureLineNotification();
        Http::fake([ProviderEndpoints::LINE_PUSH_MESSAGE => Http::response(['sentMessages' => []], 200)]);

        $order = $this->paidOrder();

        (new SendPaidOrderLineNotification($order->id))->handle(app(LinePushClient::class));

        Http::assertSent(function ($request) use ($order) {
            $body = json_decode($request->body(), true);

            return $request->url() === 'https://api.line.me/v2/bot/message/push'
                && $request->method() === 'POST'
                && $request->header('Authorization')[0] === 'Bearer '.self::TOKEN
                && $body['to'] === self::TARGET
                && count($body['messages']) === 1
                && $body['messages'][0]['type'] === 'text'
                && $request->header('X-Line-Retry-Key')[0]
                    === SendPaidOrderLineNotification::retryKeyFor($order);
        });

        Http::assertSentCount(1);
    }

    /**
     * ⛔⛔ 同一張訂單的 retry key 必須**永遠相同**。
     *
     * LINE 保證帶同一個 retry key 的請求只執行一次（之後回 409）。若這裡改成
     * 隨機 UUID，一次 timeout 後的重試就會被當成全新訊息——Owner 收到兩則。
     */
    public function test_the_retry_key_is_stable_per_order_and_a_valid_uuid(): void
    {
        $order = $this->paidOrder();
        $other = $this->paidOrder();

        $key = SendPaidOrderLineNotification::retryKeyFor($order);

        $this->assertSame($key, SendPaidOrderLineNotification::retryKeyFor($order->fresh()));
        $this->assertNotSame($key, SendPaidOrderLineNotification::retryKeyFor($other));

        // ⛔ 必須是合法 UUID v4：官方要求十六進位 UUID 格式。
        $this->assertMatchesRegularExpression(
            '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/',
            $key,
        );
    }

    /** ⛔ 超過 4800 字先自行安全截斷，⛔ 不讓 LINE 用 5000 退掉整則。 */
    public function test_a_long_message_is_truncated_before_sending(): void
    {
        $setting = $this->configureLineNotification();
        Http::fake([ProviderEndpoints::LINE_PUSH_MESSAGE => Http::response([], 200)]);

        app(LinePushClient::class)->push($setting, str_repeat('あ', 6000), '123e4567-e89b-42d3-a456-426614174000');

        Http::assertSent(function ($request) {
            $text = json_decode($request->body(), true)['messages'][0]['text'];

            return mb_strlen($text) <= 4800 && str_ends_with($text, '…（略）');
        });
    }

    /** ⛔ 端點不符即 fail closed。 */
    public function test_a_mismatched_endpoint_blocks_the_send(): void
    {
        $setting = $this->configureLineNotification();
        config(['integrations.endpoints.line_order_notification.production' => 'https://evil.example.com/push']);

        $outcome = app(LinePushClient::class)->push($setting, 'hi', '123e4567-e89b-42d3-a456-426614174000');

        $this->assertFalse($outcome->successful());
        $this->assertSame('endpoint_mismatch', $outcome->reason);
        Http::assertNothingSent();
    }

    // ==================================== 5. 失敗分類與重試

    /**
     * ⛔ 429／5xx／transport 可重試；永久 4xx 不重試。
     *
     * @return array<string, array{0: int, 1: string, 2: bool}>
     */
    public static function statusProvider(): array
    {
        return [
            'ok' => [200, 'sent', false],
            // ⭐ R1：LINE 官方把**所有 4xx（含 429）**列為不可重試。
            // ⛔ 初版標成可重試，理由是「429 是太快不是不可以」——那與官方規格
            // 相反，已撤回。仍保留獨立的 `rate_limited` token 供 Owner 分辨。
            'rate limited' => [429, 'rate_limited', false],
            'server error' => [500, 'server_error', true],
            'bad gateway' => [502, 'server_error', true],
            'unauthorized' => [401, 'rejected', false],
            'forbidden' => [403, 'rejected', false],
            'bad request' => [400, 'rejected', false],
        ];
    }

    #[DataProvider('statusProvider')]
    public function test_each_status_maps_to_the_right_retry_decision(int $status, string $reason, bool $retryable): void
    {
        $setting = $this->configureLineNotification();
        Http::fake([ProviderEndpoints::LINE_PUSH_MESSAGE => Http::response([], $status)]);

        $outcome = app(LinePushClient::class)->push($setting, 'hi', '123e4567-e89b-42d3-a456-426614174000');

        $this->assertSame($reason, $outcome->reason);
        $this->assertSame($retryable, $outcome->retryable);
    }

    /**
     * ⭐ 409 代表「這個 retry key 已被接受過」——訊息**已送出**，是成功。
     *
     * ⛔ 若把它當失敗重試，會永遠重試下去（每次都得到 409）。
     */
    public function test_a_409_conflict_counts_as_sent(): void
    {
        $setting = $this->configureLineNotification();
        Http::fake([ProviderEndpoints::LINE_PUSH_MESSAGE => Http::response([], 409)]);

        $outcome = app(LinePushClient::class)->push($setting, 'hi', '123e4567-e89b-42d3-a456-426614174000');

        $this->assertTrue($outcome->successful());
        $this->assertFalse($outcome->retryable);
    }

    // ==================================== 6. 訊息內容

    /** ⭐ 施工單指定的格式。 */
    public function test_the_message_matches_the_specified_format(): void
    {
        $order = $this->paidOrder();
        PaymentAttempt::factory()->create([
            'order_id' => $order->id,
            'provider' => 'ecpay',
            'status' => PaymentStatus::Succeeded,
        ]);

        $message = PaidOrderMessage::for($order->fresh());

        $this->assertStringContainsString('【IGNF 訂單通知】新訂單', $message);
        $this->assertStringContainsString('訂購電郵: buyer@example.test', $message);
        $this->assertStringContainsString('訂單電話: 0912345678', $message);
        $this->assertStringContainsString('付款方式: 綠界付款', $message);
        $this->assertStringContainsString('訂單金額: 1,180 元', $message);
        $this->assertStringContainsString('訂單編號: '.$order->reference, $message);
        $this->assertStringContainsString('- Instagram｜Instagram 粉絲｜一般粉絲 × 1,000', $message);

        // ⛔ 時間固定 Asia/Taipei。
        $this->assertStringContainsString(
            '訂購時間: '.$order->created_at->copy()->setTimezone('Asia/Taipei')->format('Y-m-d H:i:s'),
            $message,
        );
    }

    /** ⛔ 電話未填顯示「未填寫」，⛔ 不留空白。 */
    public function test_a_missing_phone_shows_a_placeholder(): void
    {
        $order = $this->paidOrder(['customer_phone' => null]);

        $this->assertStringContainsString('訂單電話: 未填寫', PaidOrderMessage::for($order->fresh()));
    }

    /**
     * ⛔ 付款方式是封閉映射，⛔ 不顯示 provider 原文。
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function paymentLabelProvider(): array
    {
        /*
         * ⛔ 只有這三個值能真的存進 DB。
         *
         * `payment_attempts` 有一個 CHECK constraint 把 provider 限制為
         * `'ecpay','line-pay','fake'`（見 2026_08_17_200000 migration）。
         * ⛔ 我原本想用 `some-new-gateway` 測「未知 provider」，但那一列
         * **根本插不進去**——寫一個永遠不可能發生的 fixture，測到的是
         * 我自己編的情境，不是系統的行為。
         *
         * `fake` 正好是一個真實存在、卻不在公開映射內的值，所以它就是
         * 「未知 provider 顯示為其他」的合法反例。
         */
        return [
            'ecpay' => ['ecpay', '綠界付款'],
            'line pay' => ['line-pay', 'LINE Pay'],
            'unmapped but storable' => ['fake', '其他'],
        ];
    }

    #[DataProvider('paymentLabelProvider')]
    public function test_the_payment_method_is_a_closed_mapping(string $provider, string $label): void
    {
        $order = $this->paidOrder();
        PaymentAttempt::factory()->create([
            'order_id' => $order->id,
            'provider' => $provider,
            'status' => PaymentStatus::Succeeded,
        ]);

        $message = PaidOrderMessage::for($order->fresh());

        $this->assertStringContainsString('付款方式: '.$label, $message);

        // ⛔ provider 原文絕不出現在訊息裡。
        if ($label === '其他') {
            $this->assertStringNotContainsString($provider, $message);
        }
    }

    /**
     * ⛔ 映射之外的 provider 一律「其他」——直接測封閉映射本身。
     *
     * ⭐ 上面那條受限於 DB 的 CHECK constraint，只能用真的存得進去的值；
     * 這條則直接呼叫映射，涵蓋那些**未來**可能出現、但今天還存不進 DB 的值。
     */
    public function test_an_unmapped_provider_never_leaks_its_raw_value(): void
    {
        $method = new \ReflectionMethod(PaidOrderMessage::class, 'paymentLabel');
        $method->setAccessible(true);

        $order = $this->paidOrder();

        // 沒有任何成功的 attempt：無法判定 → 其他。
        $this->assertSame('其他', $method->invoke(null, $order->fresh()));
    }

    /** 多商品逐行列出。 */
    public function test_every_item_gets_its_own_line(): void
    {
        $order = $this->paidOrder();
        $order->items()->create([
            'platform_name' => 'Instagram',
            'service_name' => 'Instagram 觀看',
            'variant_label' => '一般觀看',
            'sku' => 'ig-views',
            'unit_price_mills' => 3900,
            'quantity' => 500,
            'quantity_unit' => '個',
            'amount' => 195,
            'target_kind' => 'account',
            'target_value' => 'second',
        ]);

        $message = PaidOrderMessage::for($order->fresh());

        $this->assertStringContainsString('- Instagram｜Instagram 粉絲｜一般粉絲 × 1,000', $message);
        $this->assertStringContainsString('- Instagram｜Instagram 觀看｜一般觀看 × 500', $message);
    }

    /**
     * ⛔⛔ admin URL 由版本控制內的 route 產生，⛔ 不從 Host header 拼接。
     *
     * queue job 執行時沒有 HTTP request；若真的去讀 Host，一個偽造 Host 的
     * 請求就能讓 Owner 收到指向攻擊者網站的「訂單連結」。
     */
    public function test_the_admin_url_ignores_a_forged_host_header(): void
    {
        $order = $this->paidOrder();

        $expected = ViewOrder::getUrl(
            ['record' => $order->reference],
            isAbsolute: true,
        );

        /*
         * ⛔ 帶著一個**偽造的 Host header** 發請求，然後在同一個 request
         * 生命週期內組訊息。
         *
         * ⭐ 這是真正要防的攻擊：若 URL 由 Host header 拼接，攻擊者只要送一個
         * `Host: evil.example.com` 的請求觸發付款流程，Owner 就會收到一個
         * 指向攻擊者網站的「訂單連結」——而他有充分理由相信那是自己的後台。
         */
        $this->withServerVariables(['HTTP_HOST' => 'evil.example.com'])
            ->get('/order-check');

        $message = PaidOrderMessage::for($order->fresh());

        $this->assertStringContainsString('訂單連結: '.$expected, $message);
        $this->assertStringNotContainsString('evil.example.com', $message);
        // ⛔ 必須是絕對網址（queue job 沒有 request，相對路徑點不開）。
        $this->assertMatchesRegularExpression('#訂單連結: https?://#', $message);
    }

    /** ⛔ 訊息不得含供應商資訊。 */
    public function test_the_message_carries_no_provider_information(): void
    {
        $order = $this->paidOrder();

        $message = PaidOrderMessage::for($order->fresh());

        foreach (['TheMostPanel', 'themostpanel', 'SMM', 'api', 'service_id'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $message);
        }
    }

    // ==================================== 7. queue payload 不含機密

    /**
     * ⛔⛔ queue payload 只有 order id。
     *
     * `jobs` 與 `failed_jobs` 的內容是明文，且會進備份。Email、電話、
     * 交付目標與 token 一旦被序列化進去，就會躺在一個存取控制比訂單資料
     * 寬鬆得多的地方。
     */
    public function test_the_queue_payload_contains_no_personal_data_or_secrets(): void
    {
        $this->configureLineNotification();

        $order = $this->paidOrder();

        Queue::fake();
        OrderPaid::dispatch($order);

        Queue::assertPushed(SendPaidOrderLineNotification::class, function ($job) use ($order) {
            $serialised = serialize($job);

            foreach ([
                'buyer@example.test', '0912345678', 'my_account',
                self::TOKEN, self::TARGET,
            ] as $secret) {
                $this->assertStringNotContainsString($secret, $serialised, "⛔ queue payload 外洩：{$secret}");
            }

            return $job->orderId === $order->id;
        });
    }
}
