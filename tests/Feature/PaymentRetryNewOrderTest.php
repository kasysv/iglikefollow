<?php

namespace Tests\Feature;

use App\Actions\Payments\StartCheckout;
use App\Enums\IntegrationEnvironment;
use App\Enums\IntegrationProvider;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\IntegrationSetting;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\OrderItem;
use App\Models\PaymentAttempt;
use App\Models\ServiceVariant;
use App\Support\CheckoutSession;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ConfiguresLiveIntegrations;
use Tests\TestCase;

/**
 * A customer whose payment failed comes back and submits again.
 *
 * Owner 的產品規則：失敗／取消／逾期的舊訂單保留為歷史，客人下一次真正重新
 * 送出結帳時必須拿到一張**全新的**本站訂單——新的 order reference、新的
 * checkout token、新的 payment attempt 與 attempt reference。
 *
 * ⛔ 之前不是這樣。initiation 失敗時 controller 直接 redirect 回結帳頁，
 * 沒有清掉 session，`CheckoutSession` 的 token 原封不動；下一次送出時
 * `StartCheckout::orderFor()` 用同一個 token 查回**同一張舊訂單**，於是失敗的
 * 那張單被反覆重用，客人與後台都看不出這是第二次嘗試。
 *
 * 另一半同樣重要，而且方向相反：still-payable 的訂單絕不能因此多開一張。
 * pending 代表 provider 那邊可能有活著的付款 session、reconciliation_required
 * 代表錢可能已經扣了，兩者再開新單都是在請客人付第二次。
 *
 * ⛔ 這裡的訂單一律走真正的 `POST /payments/start`，不用 factory 手捏：手捏的
 * 列從來沒經過 session token 這條路，而 token 正是本輪要驗的東西。
 */
class PaymentRetryNewOrderTest extends TestCase
{
    use ConfiguresLiveIntegrations;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // ⛔ 全程 fake-only：任何真實 provider request 都會讓測試失敗。
        Http::preventStrayRequests();
        $this->runningAsLiveSite();
        $this->seed(CatalogSeeder::class);

        $this->configureEcpay();

        /*
         * ⛔ 讓 initiation 在「送出任何東西之前」失敗，且不影響建單。
         *
         * 通道是開著的、credential 齊全，所以 controller 照常建立訂單並 claim
         * attempt；但端點不符白名單，adapter 會在任何 HTTP 之前放棄，
         * attempt 收斂為 `failed`，controller redirect 回結帳頁而**不清掉
         * session 選購**。
         *
         * 這重現的正是 Owner 回報的實況：客人停在結帳頁、選購還在、可以再按
         * 一次。⛔ 若讓 initiation 成功，session 會被清掉，第二次送出根本
         * 到不了輪替判斷，測試就會因為錯誤的理由而「通過」。
         */
        config()->set('integrations.endpoints.ecpay_payment.production', '');
    }

    // ==================================== helpers

    private function configureEcpay(bool $enabled = true): void
    {
        $setting = IntegrationSetting::factory()
            ->forProvider(IntegrationProvider::EcpayPayment, IntegrationEnvironment::Production)
            ->create(['identifier' => '3000001']);

        $setting->credentials = ['HashKey' => 'test-hash-key-0001', 'HashIV' => 'test-hash-iv-0001'];
        $setting->save();

        DB::table('integration_settings')->where('id', $setting->id)
            ->update(['is_enabled' => $enabled]);
    }

    private function configureLine(bool $enabled = true): void
    {
        $setting = IntegrationSetting::factory()
            ->forProvider(IntegrationProvider::LinePay, IntegrationEnvironment::Production)
            ->create(['identifier' => 'channel-0001']);

        $setting->credentials = ['ChannelSecret' => 'test-channel-secret-0001'];
        $setting->save();

        DB::table('integration_settings')->where('id', $setting->id)
            ->update(['is_enabled' => $enabled]);
    }

    /** 把選購放進 session，之後的 POST /payments/start 才有東西可結帳。 */
    private function select(): ServiceVariant
    {
        $variant = ServiceVariant::where('sku', 'ig-followers-standard')->firstOrFail();

        $this->post('/checkout/start', [
            'variant' => $variant->id,
            'quantity' => $variant->default_quantity,
        ]);

        return $variant;
    }

    /** @return array<string, mixed> */
    private function form(string $payment = 'ecpay'): array
    {
        return [
            'target' => 'example_account',
            'payment' => $payment,
            'customer_email' => 'buyer@example.com',
            'invoice_kind' => 'personal',
            'personal_invoice_mode' => 'email',
        ];
    }

    /**
     * 一次完整的「客人按下前往付款」，而且 initiation 會失敗。
     *
     * ⭐ 這正是 Owner 在 staging 遇到的情況：LINE Pay 回「付款結果驗證失敗」，
     * controller 直接 redirect 回結帳頁。
     *
     * ⛔ 失敗這條路徑**不會**清掉 session 選購——`forget()` 只在成功交給
     * provider 之後才呼叫。選購還在，token 也還在，所以下一次送出會沿用同一個
     * token；那就是本輪要修的東西。成功交手之後客人得重新選購，那是另一條路徑，
     * 由 `test_a_successful_handoff_clears_the_selection` 單獨釘住。
     */
    private function submit(string $payment = 'ecpay'): void
    {
        $this->post('/payments/start', $this->form($payment));
    }

    /**
     * session 裡目前的 checkout token。
     *
     * ⛔ 讀 session helper，不讀 `request()`：測試裡的全域 request 不是剛才那次
     * HTTP 呼叫用的那一個，`token(request())` 會拿不到 session 而炸掉。
     */
    private function currentToken(): ?string
    {
        $stored = session(CheckoutSession::KEY);

        return is_array($stored) ? ($stored['token'] ?? null) : null;
    }

    /**
     * 把訂單目前那筆嘗試推進指定的終局狀態。
     *
     * ⛔ 直接寫 status，不呼叫 RecordPaymentResult：本輪要驗的是「收斂之後
     * 下一次送出會怎樣」，不是收斂本身是怎麼發生的——那由既有的付款結果測試
     * 負責。這裡只需要一個確定處於該狀態的起點。
     */
    private function settleLatestAttempt(Order $order, PaymentStatus $status): void
    {
        $order->paymentAttempts()->latest('id')->firstOrFail()
            ->forceFill(['status' => $status, 'completed_at' => now()])->save();
    }

    // ==================================== 1. terminal unpaid → 下一次送出建新單

    public static function terminalUnpaidProvider(): array
    {
        return [
            'failed' => [PaymentStatus::Failed],
            'canceled' => [PaymentStatus::Canceled],
            'expired' => [PaymentStatus::Expired],
        ];
    }

    /**
     * ⭐ 本輪的核心行為。
     *
     * 五個識別值全部必須不同：order id、order reference、checkout token、
     * attempt id、attempt reference。少了任何一個「新訂單」都只是名義上的：
     * 例如 reference 相同，客服與客人就分不出這是哪一次；attempt reference
     * 相同，綠界的 MerchantTradeNo 會撞單。
     */
    #[DataProvider('terminalUnpaidProvider')]
    public function test_a_terminal_unpaid_order_yields_a_brand_new_order_on_the_next_submit(
        PaymentStatus $terminal
    ): void {
        $this->select();
        $this->submit();

        $first = Order::latest('id')->firstOrFail();
        $firstToken = $this->currentToken();
        $firstAttempt = $first->paymentAttempts()->latest('id')->firstOrFail();

        $this->settleLatestAttempt($first, $terminal);

        // 客人回到結帳頁再按一次。
        $this->submit();

        $this->assertSame(2, Order::count(), '收斂之後的下一次送出必須建立第二張訂單。');

        $second = Order::latest('id')->firstOrFail();
        $secondAttempt = $second->paymentAttempts()->latest('id')->firstOrFail();

        // ⛔ 五個識別值逐一不同。
        $this->assertNotSame($first->id, $second->id);
        $this->assertNotSame($first->reference, $second->reference);
        $this->assertNotSame($first->checkout_token, $second->checkout_token);
        $this->assertNotSame($firstAttempt->id, $secondAttempt->id);
        $this->assertNotSame($firstAttempt->reference, $secondAttempt->reference);

        // session 也真的換了 token，而不是只有 DB 看起來像新的。
        $this->assertNotSame($firstToken, $this->currentToken());
    }

    /**
     * 舊訂單是歷史，不是暫存區。
     *
     * ⛔ 舊 order、items、attempts、events 與聯絡／發票輸入都不得被改寫、
     * 刪除或搬到新訂單上。客服日後要能回答「那一次到底發生什麼事」。
     */
    #[DataProvider('terminalUnpaidProvider')]
    public function test_the_old_order_is_left_exactly_as_it_was(PaymentStatus $terminal): void
    {
        $this->select();
        $this->submit();

        $first = Order::latest('id')->firstOrFail();
        $this->settleLatestAttempt($first, $terminal);

        $before = $first->fresh()->toArray();
        $itemsBefore = $first->items()->get()->toArray();
        $attemptsBefore = $first->paymentAttempts()->get()->toArray();
        $eventsBefore = $first->events()->get()->toArray();

        $this->submit();

        $this->assertSame($before, $first->fresh()->toArray(), '舊訂單本身不得被改寫。');
        $this->assertSame($itemsBefore, $first->items()->get()->toArray(), '舊訂單的商品項目不得被搬走。');
        $this->assertSame($attemptsBefore, $first->paymentAttempts()->get()->toArray(), '舊付款嘗試不得被改寫。');
        $this->assertSame($eventsBefore, $first->events()->get()->toArray(), '舊訂單事件不得被改寫。');

        // 收斂後的狀態原樣保留，⛔ 不因為開了新單就被清成別的值。
        $this->assertSame($terminal, $first->paymentAttempts()->latest('id')->firstOrFail()->status);

        // 新訂單有自己的項目與事件，不是共用舊的。
        $second = Order::latest('id')->firstOrFail();
        $this->assertSame(1, $second->items()->count());
        $this->assertSame(
            1,
            $second->events()->where('type', OrderEvent::TYPE_ORDER_CREATED)->count()
        );
    }

    /**
     * 收斂當下不得預建下一張訂單。
     *
     * ⛔ 只有下一次有效 POST 才建立。取消之後就先開一張，等於為一個可能永遠
     * 不會回來的客人在後台留下幽靈訂單。
     */
    #[DataProvider('terminalUnpaidProvider')]
    public function test_reaching_a_terminal_state_creates_no_ghost_order(PaymentStatus $terminal): void
    {
        $this->select();
        $this->submit();

        $order = Order::latest('id')->firstOrFail();
        $this->settleLatestAttempt($order, $terminal);

        // ⛔ 還沒有下一次送出：訂單數與嘗試數都不得增加。
        $this->assertSame(1, Order::count());
        $this->assertSame(1, PaymentAttempt::count());
    }

    // ==================================== 2. still payable → 不得建新單

    public static function stillPayableProvider(): array
    {
        return [
            'pending' => [PaymentStatus::Pending],
            'reconciliation required' => [PaymentStatus::ReconciliationRequired],
            'succeeded' => [PaymentStatus::Succeeded],
        ];
    }

    /**
     * ⛔ 防重複扣款的既有保證不得被本輪放寬。
     *
     * pending：provider 那邊可能有活著的付款 session。
     * reconciliation_required：錢可能已經扣了，結果不明。
     * succeeded：已經付過了。
     *
     * 三者再開一張新訂單都是在請客人付第二次。
     */
    #[DataProvider('stillPayableProvider')]
    public function test_a_still_payable_order_never_rotates_into_a_new_order(PaymentStatus $status): void
    {
        $this->select();
        $this->submit();

        $order = Order::latest('id')->firstOrFail();
        $tokenBefore = $this->currentToken();
        $this->settleLatestAttempt($order, $status);

        $this->submit();

        $this->assertSame(1, Order::count(), "狀態 {$status->value} 時不得建立第二張訂單。");
        $this->assertSame($tokenBefore, $this->currentToken(), 'token 不得被輪替。');
        $this->assertSame($order->checkout_token, Order::latest('id')->firstOrFail()->checkout_token);
    }

    /**
     * 已付款的訂單更是絕對不得再開一張。
     *
     * 上面那個測試只改了 attempt；這裡把 order 本身也推到 paid，模擬真正付完
     * 款之後客人又按了一次上一頁重送。
     */
    public function test_a_paid_order_never_yields_a_second_order(): void
    {
        $this->select();
        $this->submit();

        $order = Order::latest('id')->firstOrFail();
        $this->settleLatestAttempt($order, PaymentStatus::Succeeded);
        $order->forceFill([
            'order_status' => OrderStatus::Paid,
            'payment_status' => PaymentStatus::Succeeded,
            'paid_at' => now(),
        ])->save();

        $this->submit();

        $this->assertSame(1, Order::count());
        $this->assertSame(OrderStatus::Paid, $order->fresh()->order_status);
    }

    /**
     * ⛔ 混合狀態一律不得輪替。
     *
     * 一筆已失敗、另一筆還 pending 時，這張訂單並沒有「全部收斂」——那筆
     * pending 仍可能在 provider 那邊活著。判定必須是「每一筆都是 terminal
     * unpaid」，不是「最新那一筆是」。
     */
    public function test_a_mix_of_failed_and_pending_attempts_does_not_rotate(): void
    {
        $this->select();
        $this->submit();

        $order = Order::latest('id')->firstOrFail();
        $this->settleLatestAttempt($order, PaymentStatus::Failed);

        // 再補一筆仍在進行中的嘗試。
        $order->paymentAttempts()->create([
            'provider' => 'line-pay',
            'reference' => PaymentAttempt::newReference(),
            'status' => PaymentStatus::Pending,
            'amount' => $order->total_amount,
            'currency' => $order->currency,
        ]);

        $this->submit();

        $this->assertSame(1, Order::count());
    }

    // ==================================== 3. 重送次數：第一張、第二張，沒有第三張

    /**
     * ⭐ 真正的雙擊：訂單還活著（attempt 仍 pending）時連續送出，只有一張。
     *
     * ⛔ 本輪新增的輪替絕不能讓雙擊各建一張。第一次送出之後那筆 attempt 是
     * pending（`ResolvePaymentAttempt` claim 過了），不是 terminal unpaid，
     * 所以判定為「訂單還活著」，不輪替。
     *
     * 這裡 initiation 必須是成功的那種——端點正常、attempt 留在 pending，
     * 才是「雙擊」的實況；⛔ 用會失敗的 initiation 測雙擊等於測錯東西：那時
     * 每一次送出都已經收斂，本來就該各開一張。
     */
    public function test_a_double_submit_while_the_order_is_alive_creates_only_one_order(): void
    {
        // 端點恢復正常：initiation 會成功，attempt 停在 pending。
        config()->set(
            'integrations.endpoints.ecpay_payment.production',
            config('integrations.endpoints.ecpay_payment.production') ?:
                'https://payment.ecpay.com.tw/Cashier/AioCheckOut/V5'
        );

        $this->select();

        $this->post('/payments/start', $this->form());

        $order = Order::latest('id')->firstOrFail();
        $this->assertSame(
            PaymentStatus::Pending,
            $order->paymentAttempts()->latest('id')->firstOrFail()->status,
            '前置條件：第一次送出之後 attempt 必須是 pending。'
        );

        /*
         * ⛔ 直接對同一張活著的訂單再判定一次，不重送 HTTP：成功交手之後
         * session 已被清掉，再 POST 只會因為沒有選購而提早返回，那證明不了
         * 「輪替判斷擋下了雙擊」。這裡直接問判定方法本身。
         */
        $this->assertFalse(
            app(StartCheckout::class)->shouldStartNewOrder($order->checkout_token),
            '⛔ attempt 還 pending 時不得輪替成新訂單。'
        );

        $this->assertSame(1, Order::count());
    }

    /**
     * 成功交手之後選購會被清掉，⛔ 這是既有行為，本輪不得改變。
     *
     * 客人已經被送到 provider 的付款頁；他若要買第二次，必須從商品頁重新
     * 選購。這條路徑不經過本輪的輪替判斷。
     */
    public function test_a_successful_handoff_clears_the_selection(): void
    {
        config()->set(
            'integrations.endpoints.ecpay_payment.production',
            'https://payment.ecpay.com.tw/Cashier/AioCheckOut/V5'
        );

        $this->select();
        $this->assertNotNull($this->currentToken());

        $this->post('/payments/start', $this->form());

        $this->assertNull($this->currentToken());
        $this->assertSame(1, Order::count());
    }

    /**
     * ⭐ 完整的重試曲線：一張 → 收斂 → 第二張 → 不得有第三張。
     *
     * 第二張進入 pending 之後再重送，就跟第一張進入 pending 之後重送一樣，
     * 必須被同一道 still-payable 判斷擋下。
     */
    public function test_the_new_order_itself_does_not_spawn_a_third_while_pending(): void
    {
        $this->select();
        $this->submit();

        $first = Order::latest('id')->firstOrFail();
        $this->assertSame(PaymentStatus::Failed, $first->paymentAttempts()->latest('id')->firstOrFail()->status);

        // 第二張。
        $this->submit();
        $this->assertSame(2, Order::count());

        $second = Order::latest('id')->firstOrFail();

        /*
         * 讓第二張真正進入 pending。
         *
         * ⛔ 這個測試的前置條件不是「又送了一次」，而是「第二張現在是活的」。
         * 本測試類別預設讓 initiation 失敗（那才是重試情境），所以要驗
         * 「pending 時不得再開第三張」就必須明確把它推進 pending——否則測到的
         * 只是另一個已收斂的訂單，那本來就該開新單。
         */
        $this->settleLatestAttempt($second, PaymentStatus::Pending);

        // ⛔ 再重送兩次都不得出現第三張。
        $this->submit();
        $this->submit();

        $this->assertSame(2, Order::count());
        $this->assertFalse(
            app(StartCheckout::class)->shouldStartNewOrder($second->checkout_token)
        );
    }

    /**
     * 連續兩輪重試各建一張，且每一張的識別值都是新的。
     *
     * 一失敗 → 二 → 二失敗 → 三，總共恰好三張；中間插入的「此時仍活著」那次
     * 重送⛔ 不得增加數量。
     */
    public function test_each_terminal_round_yields_exactly_one_more_order(): void
    {
        $this->select();

        $this->submit();
        $this->assertSame(1, Order::count());

        // 第一張已收斂（initiation 失敗）→ 開第二張。
        $this->submit();
        $this->assertSame(2, Order::count());

        // ⛔ 把第二張推回活著的狀態，這一次重送不得再開。
        $second = Order::latest('id')->firstOrFail();
        $this->settleLatestAttempt($second, PaymentStatus::Pending);
        $this->submit();
        $this->assertSame(2, Order::count());

        // 第二張收斂為取消 → 開第三張。
        $this->settleLatestAttempt($second, PaymentStatus::Canceled);
        $this->submit();
        $this->assertSame(3, Order::count());

        // 三張全部有各自唯一的 reference 與 token。
        $this->assertSame(3, Order::query()->distinct()->count('reference'));
        $this->assertSame(3, Order::query()->distinct()->count('checkout_token'));
        $this->assertSame(3, PaymentAttempt::query()->distinct()->count('reference'));
    }

    // ==================================== 4. 換 provider 重試仍在新訂單

    /**
     * 綠界失敗之後改用 LINE Pay，⛔ 新的 attempt 必須落在**新訂單**上。
     *
     * 這是最容易做錯的一格：既有的 `ResolvePaymentAttempt` 本來就允許換
     * provider 再開一筆 attempt，但那是在同一張訂單裡。Owner 的規則是收斂之後
     * 連訂單都要換新。
     */
    public function test_retrying_with_a_different_provider_lands_on_the_new_order(): void
    {
        $this->configureLine();

        $this->select();
        $this->submit('ecpay');

        $first = Order::latest('id')->firstOrFail();
        $this->settleLatestAttempt($first, PaymentStatus::Failed);

        Http::fake([
            'https://api-pay.line.me/v4/payments/request' => Http::response([
                'returnCode' => '1124',
                'returnMessage' => 'Amount information error',
            ], 200),
        ]);

        $this->submit('line-pay');

        $second = Order::latest('id')->firstOrFail();

        $this->assertSame(2, Order::count());
        $this->assertNotSame($first->id, $second->id);

        // 新的 line-pay attempt 屬於新訂單，舊訂單只有原本那一筆 ecpay。
        $this->assertSame(1, $first->paymentAttempts()->count());
        $this->assertSame('ecpay', $first->paymentAttempts()->latest('id')->value('provider'));
        $this->assertSame('line-pay', $second->paymentAttempts()->latest('id')->value('provider'));
    }

    // ==================================== 5. selection 失效時 0 新訂單

    /**
     * ⛔ 沒有有效選購就不建單，即使舊訂單已經收斂。
     *
     * 輪替只發生在「這一次是有效 POST」的前提下。selection 不見時
     * `StartCheckout::handle()` 在輪替之前就回 null。
     */
    public function test_a_missing_selection_creates_no_new_order(): void
    {
        $this->select();
        $this->submit();

        $order = Order::latest('id')->firstOrFail();
        $this->settleLatestAttempt($order, PaymentStatus::Failed);

        // 清掉 session 選購：模擬 session 過期。
        $this->flushSession();

        $this->post('/payments/start', $this->form())->assertRedirect();

        $this->assertSame(1, Order::count());
    }

    /** 商品下架之後重送，⛔ 一樣不得建立新訂單。 */
    public function test_an_unpublished_variant_creates_no_new_order(): void
    {
        $variant = $this->select();
        $this->submit();

        $order = Order::latest('id')->firstOrFail();
        $this->settleLatestAttempt($order, PaymentStatus::Failed);

        // 下架：⛔ 用真正的 status 欄位，不是不存在的 is_published。
        $variant->forceFill(['status' => 'draft'])->save();

        $this->post('/payments/start', $this->form())->assertRedirect();

        $this->assertSame(1, Order::count());
    }

    /**
     * 數量落到界線外之後重送，⛔ 一樣不得建立新訂單。
     *
     * ⛔ 直接改 DB 的 min_quantity，不走 model save：`VariantIntegrityObserver`
     * 會擋下 default_quantity 落在界線外的儲存，那是後台的正確保護，但這裡要
     * 模擬的是「session 存的數量事後變得不合法」，必須繞過後台驗證才做得出來。
     */
    public function test_an_out_of_bounds_quantity_creates_no_new_order(): void
    {
        $variant = $this->select();
        $this->submit();

        $order = Order::latest('id')->firstOrFail();
        $this->settleLatestAttempt($order, PaymentStatus::Failed);

        DB::table('service_variants')->where('id', $variant->id)
            ->update(['min_quantity' => $variant->default_quantity + 1]);

        $this->post('/payments/start', $this->form())->assertRedirect();

        $this->assertSame(1, Order::count());
    }

    // ==================================== 6. 判定方法本身

    /**
     * 判定集中在一個可直接測試的方法，⛔ 不在 Blade 或 JavaScript：那兩個地方
     * 客人都改得到，而且測不到。
     */
    public function test_the_decision_method_is_false_for_an_unknown_token(): void
    {
        // 沒有對應訂單 → 這是第一次送出，不得輪替（否則雙擊會各建一張）。
        $this->assertFalse(app(StartCheckout::class)->shouldStartNewOrder('no-such-token'));
    }

    public function test_the_decision_method_is_false_for_an_order_with_no_attempts(): void
    {
        $order = Order::factory()->create(['checkout_token' => 'token-without-attempts']);

        // ⛔ 一筆嘗試都沒有 ≠ 全部收斂。
        $this->assertSame(0, $order->paymentAttempts()->count());
        $this->assertFalse(app(StartCheckout::class)->shouldStartNewOrder('token-without-attempts'));
    }

    #[DataProvider('terminalUnpaidProvider')]
    public function test_the_decision_method_is_true_only_when_every_attempt_is_terminal_unpaid(
        PaymentStatus $terminal
    ): void {
        $order = Order::factory()->create(['checkout_token' => 'token-terminal']);

        foreach ([PaymentStatus::Failed, $terminal] as $status) {
            $order->paymentAttempts()->create([
                'provider' => 'ecpay',
                'reference' => PaymentAttempt::newReference(),
                'status' => $status,
                'amount' => $order->total_amount,
                'currency' => 'TWD',
            ]);
        }

        $this->assertTrue(app(StartCheckout::class)->shouldStartNewOrder('token-terminal'));
    }

    /**
     * ⭐ 已付款這道閘門必須自己站得住，⛔ 不得只靠 attempt 狀態順便擋下。
     *
     * 這是刻意構造的不一致狀態：order 已經是 paid，但它每一筆 attempt 都是
     * failed／canceled／expired。真實世界會這樣：綠界的成功通知把訂單推成
     * paid，而稍早那筆逾時的嘗試後來才被排程標記為 expired。
     *
     * 此時「所有嘗試都已收斂為 unpaid terminal」是**成立**的，所以只看 attempt
     * 的判斷會放行，開出第二張訂單——對一個已經付過錢的客人。唯一擋下它的是
     * `order_status === Paid` 那一行。
     *
     * ⛔ 沒有這個測試，把該行刪掉整組測試仍然全綠（實測 mutation 存活）。
     */
    public function test_a_paid_order_is_refused_even_when_every_attempt_is_terminal_unpaid(): void
    {
        $order = Order::factory()->create([
            'checkout_token' => 'token-paid-but-attempts-terminal',
            'order_status' => OrderStatus::Paid,
        ]);

        foreach ([PaymentStatus::Failed, PaymentStatus::Expired] as $status) {
            $order->paymentAttempts()->create([
                'provider' => 'ecpay',
                'reference' => PaymentAttempt::newReference(),
                'status' => $status,
                'amount' => $order->total_amount,
                'currency' => 'TWD',
            ]);
        }

        // 前置條件：只看 attempt 的話這張訂單「看起來」完全收斂了。
        $this->assertTrue(
            $order->paymentAttempts()->get()->pluck('status')
                ->every(fn (PaymentStatus $s) => in_array(
                    $s,
                    [PaymentStatus::Failed, PaymentStatus::Canceled, PaymentStatus::Expired],
                    true
                ))
        );

        // ⛔ 但它已經付款了，永遠不得再開一張。
        $this->assertFalse(
            app(StartCheckout::class)->shouldStartNewOrder('token-paid-but-attempts-terminal')
        );
    }

    /**
     * ⛔ initiated 不算收斂。
     *
     * 那筆嘗試還沒被 claim、也還沒結束，`ResolvePaymentAttempt` 會直接接手它。
     * 這張訂單還活著，不是歷史。
     */
    public function test_the_decision_method_is_false_while_an_attempt_is_only_initiated(): void
    {
        $order = Order::factory()->create(['checkout_token' => 'token-initiated']);

        $order->paymentAttempts()->create([
            'provider' => 'ecpay',
            'reference' => PaymentAttempt::newReference(),
            'status' => PaymentStatus::Initiated,
            'amount' => $order->total_amount,
            'currency' => 'TWD',
        ]);

        $this->assertFalse(app(StartCheckout::class)->shouldStartNewOrder('token-initiated'));
    }

    // ==================================== 7. session 輪替方法本身

    /**
     * 輪替只換 token，⛔ variant／quantity／return_url 一律保留：客人沒有重選
     * 商品，把選購一起清掉會把他踢回商品頁重來一次。
     */
    public function test_rotating_the_token_keeps_the_selection_intact(): void
    {
        $this->select();

        $before = session(CheckoutSession::KEY);

        // ⛔ 用一個真的帶著這個 session 的 request，不是測試裡的全域 request。
        $request = Request::create('/payments/start', 'POST');
        $request->setLaravelSession(app('session.store'));

        $rotated = app(CheckoutSession::class)->rotateToken($request);
        $after = session(CheckoutSession::KEY);

        $this->assertNotSame($before['token'], $rotated);
        $this->assertSame($rotated, $after['token']);
        $this->assertSame($before['variant_id'], $after['variant_id']);
        $this->assertSame($before['quantity'], $after['quantity']);
        $this->assertSame($before['return_url'], $after['return_url']);
    }

    /**
     * ⛔ 沒有選購資料時不得憑空造 token。
     *
     * `token()` 的契約是「沒有選購就沒有 token」；輪替若在這裡回傳一個新值，
     * 就會讓一個沒有商品的 session 看起來像一次有效結帳。
     */
    public function test_rotating_without_a_selection_produces_nothing(): void
    {
        $this->flushSession();

        $request = Request::create('/payments/start', 'POST');
        $request->setLaravelSession(app('session.store'));

        $this->assertNull(app(CheckoutSession::class)->rotateToken($request));
        $this->assertNull(session(CheckoutSession::KEY));
    }

    // ==================================== 8. 資料完整性

    /**
     * 新訂單的內容是這一次重新驗證的結果，⛔ 不是從舊訂單複製過來的。
     *
     * 金額與商品快照都必須來自當下的 published catalogue；若中間改了價，新單
     * 要用新價。這同時證明本輪沒有偷偷走「複製舊單」的捷徑。
     */
    public function test_the_new_order_is_built_from_the_live_catalogue_not_the_old_order(): void
    {
        $variant = $this->select();
        $this->submit();

        $first = Order::latest('id')->firstOrFail();
        $this->settleLatestAttempt($first, PaymentStatus::Failed);

        // 管理者調高單價。
        $variant->forceFill(['unit_price' => $variant->unit_price * 2])->save();

        $this->submit();

        $second = Order::latest('id')->firstOrFail();

        $this->assertSame(2, Order::count());
        // ⛔ 新單用新價，舊單保留原價。
        $this->assertNotSame((int) $first->total_amount, (int) $second->total_amount);
        $this->assertSame(
            (int) $variant->fresh()->amountFor((int) $first->items()->value('quantity')),
            (int) $second->total_amount
        );
    }

    /** 每一張訂單的商品項目各自獨立，⛔ 不得指到同一列。 */
    public function test_order_items_are_never_shared_between_the_old_and_new_order(): void
    {
        $this->select();
        $this->submit();

        $first = Order::latest('id')->firstOrFail();
        $this->settleLatestAttempt($first, PaymentStatus::Failed);

        $this->submit();

        $second = Order::latest('id')->firstOrFail();

        $this->assertSame(2, OrderItem::count());
        $this->assertNotSame(
            (int) $first->items()->value('id'),
            (int) $second->items()->value('id')
        );
    }

    // ==================================== 9. 並行 terminal retry（R1）

    /**
     * 造出兩個「已經各自讀到同一份舊 session snapshot」的獨立 request。
     *
     * ⛔ 這是本節的關鍵手法。用同一個測試 session 連送兩次 HTTP 不算並行：
     * 第一次的寫入會被第二次讀到，race 根本不會發生。真正要模擬的是兩個
     * request 都**在對方寫回之前**就讀到了同一份舊資料。
     *
     * 每個 request 拿到自己的 session store，但兩者被填入完全相同的
     * selection＋token snapshot——這正是「客人連按兩下」在兩個 PHP worker
     * 上同時發生時的狀態。
     *
     * @param  array<string, mixed>  $snapshot
     */
    private function staleRequest(array $snapshot): Request
    {
        $request = Request::create('/payments/start', 'POST');

        // ⛔ 各自獨立的 store，不共用：共用就不是兩個並行 request 了。
        $store = new Store('stale-'.Str::random(16), new ArraySessionHandler(120));
        $store->start();
        $store->put(CheckoutSession::KEY, $snapshot);

        $request->setLaravelSession($store);

        return $request;
    }

    /**
     * ⭐ R1 的反證測試：兩個並行 terminal retry 只能建立一張新訂單。
     *
     * ⛔ 這個測試在初版 `b69b8d8` 必定失敗。初版的 `rotateToken()` 對每個
     * request 各產生一個新的隨機 UUID，於是：
     *
     *   request A → token A → 建立新 order A
     *   request B → token B → 建立新 order B
     *
     * 兩個 token 不同，`orders.checkout_token` 的 unique constraint 完全不會
     * 衝突，客人一次雙擊就得到**兩張**新訂單。
     *
     * R1 讓輪替以「前代 token」為唯一輸入做 deterministic derivation，兩個
     * 持有相同前代 token 的 request 因此算出**同一個**新 token，最終由既有的
     * DB unique constraint 收斂成一張。
     *
     * ⛔ 收斂必須發生在「兩個 request 都已讀到相同 stale snapshot」之後，
     * 不能倚賴 session lock：session lock 擋不住兩個 worker 已經各自讀完的
     * 情況，而那正是這裡構造出來的狀態。
     */
    public function test_two_concurrent_terminal_retries_create_exactly_one_new_order(): void
    {
        $this->select();
        $this->submit();

        $old = Order::latest('id')->firstOrFail();
        $oldToken = $old->checkout_token;
        $this->assertSame(PaymentStatus::Failed, $old->paymentAttempts()->latest('id')->firstOrFail()->status);

        // 兩個 worker 各自讀到的同一份舊 snapshot。
        $snapshot = session(CheckoutSession::KEY);
        $this->assertSame($oldToken, $snapshot['token']);

        $a = $this->staleRequest($snapshot);
        $b = $this->staleRequest($snapshot);

        $action = app(StartCheckout::class);
        $resultA = $action->handle($a, $this->form());
        $resultB = $action->handle($b, $this->form());

        $this->assertNotNull($resultA);
        $this->assertNotNull($resultB);

        // ⛔ 恰好兩張：一張舊的（歷史）＋一張新的。不得是三張。
        $this->assertSame(2, Order::count(), '兩個並行 terminal retry 不得各建一張新訂單。');

        // 兩個 request 收斂到同一個新 token 與同一張新 order。
        $this->assertSame($resultA['token'], $resultB['token'], '相同前代 token 必須推導出相同新 token。');
        $this->assertSame($resultA['order']->id, $resultB['order']->id);

        $new = $resultA['order'];
        $this->assertNotSame($old->id, $new->id);
        $this->assertNotSame($oldToken, $new->checkout_token, '新 token 必須與前代不同。');
        $this->assertSame($resultA['token'], $new->checkout_token);

        // 欄位長度限制：⛔ 超過 64 會被 DB 截斷或拒絕。
        $this->assertLessThanOrEqual(64, strlen($new->checkout_token));

        // 新訂單只有一筆 attempt，⛔ 不是兩個 request 各塞一筆。
        $this->assertSame(1, $new->paymentAttempts()->count());
        $this->assertSame(2, PaymentAttempt::count());
    }

    /** 並行 retry 之後，舊訂單仍然逐欄位原樣。 */
    public function test_a_concurrent_retry_leaves_the_old_order_untouched(): void
    {
        $this->select();
        $this->submit();

        $old = Order::latest('id')->firstOrFail();
        $before = $old->fresh()->toArray();
        $itemsBefore = $old->items()->get()->toArray();
        $attemptsBefore = $old->paymentAttempts()->get()->toArray();
        $eventsBefore = $old->events()->get()->toArray();

        $snapshot = session(CheckoutSession::KEY);
        $action = app(StartCheckout::class);
        $action->handle($this->staleRequest($snapshot), $this->form());
        $action->handle($this->staleRequest($snapshot), $this->form());

        $this->assertSame($before, $old->fresh()->toArray());
        $this->assertSame($itemsBefore, $old->items()->get()->toArray());
        $this->assertSame($attemptsBefore, $old->paymentAttempts()->get()->toArray());
        $this->assertSame($eventsBefore, $old->events()->get()->toArray());
    }

    /**
     * ⭐ 第二個 terminal 週期必須產生**再下一個**不同 token。
     *
     * ⛔ deterministic derivation 最容易做錯的方向：若新 token 只由某個固定值
     * 推導（例如 order id 或一個常數），第二次收斂後就會永遠算回同一個 token，
     * 客人再也拿不到第三張訂單——重試功能等於在第二次之後靜默失效。
     *
     * 正確的做法是「以前一輪的 token 推導下一輪」，形成一條每次都前進的鏈。
     */
    public function test_a_second_terminal_cycle_derives_a_further_different_token(): void
    {
        $this->select();
        $this->submit();

        $first = Order::latest('id')->firstOrFail();
        $snapshot = session(CheckoutSession::KEY);

        // 第一輪輪替：兩個並行 request 收斂成第二張訂單。
        $action = app(StartCheckout::class);
        $r1a = $action->handle($this->staleRequest($snapshot), $this->form());
        $r1b = $action->handle($this->staleRequest($snapshot), $this->form());
        $this->assertSame($r1a['token'], $r1b['token']);

        $second = $r1a['order'];
        $this->assertSame(2, Order::count());

        // 第二張也收斂為 terminal unpaid。
        $this->settleLatestAttempt($second, PaymentStatus::Canceled);

        // 第二輪：持有「第二張的 token」的兩個並行 request。
        $snapshot2 = array_merge($snapshot, ['token' => $r1a['token']]);
        $r2a = $action->handle($this->staleRequest($snapshot2), $this->form());
        $r2b = $action->handle($this->staleRequest($snapshot2), $this->form());

        $this->assertSame($r2a['token'], $r2b['token']);
        $this->assertSame(3, Order::count(), '第二個 terminal 週期必須能再開一張。');

        // ⛔ 三個 token 兩兩不同：鏈是往前走的，不是繞回原點。
        $tokens = [$first->checkout_token, $r1a['token'], $r2a['token']];
        $this->assertCount(3, array_unique($tokens));
        $this->assertSame(3, Order::query()->distinct()->count('checkout_token'));
        $this->assertSame(3, Order::query()->distinct()->count('reference'));
    }

    /**
     * 推導必須綁定 server secret，⛔ 不得只是前代 token 的公開函數。
     *
     * 前代 token 會出現在客人自己的 cookie／session 裡。若新 token 只是它的
     * `sha256` 之類的公開變換，任何拿到舊 token 的人都能算出下一個 token，
     * 進而預測或搶佔下一張訂單的識別碼。
     *
     * 這裡以 `APP_KEY` 改變後推導結果必須改變來證明 secret 真的參與運算。
     */
    public function test_the_derived_token_depends_on_the_application_secret(): void
    {
        $this->select();
        $snapshot = session(CheckoutSession::KEY);

        $session = app(CheckoutSession::class);

        $first = $session->rotateToken($this->staleRequest($snapshot));

        // 換一把 APP_KEY 再推導同一個前代 token。
        config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        $second = $session->rotateToken($this->staleRequest($snapshot));

        $this->assertNotSame(
            $first,
            $second,
            '⛔ 推導必須包含 server secret；否則任何人拿到舊 token 都能算出下一個。'
        );

        // ⛔ 也不得等於前代 token 的裸雜湊——那同樣是公開可算的。
        $this->assertNotSame(hash('sha256', $snapshot['token']), $first);
    }

    /** 相同前代 token 在同一把 APP_KEY 下必須穩定重現，否則並行收斂不成立。 */
    public function test_the_derivation_is_stable_for_the_same_previous_token(): void
    {
        $this->select();
        $snapshot = session(CheckoutSession::KEY);

        $session = app(CheckoutSession::class);

        $a = $session->rotateToken($this->staleRequest($snapshot));
        $b = $session->rotateToken($this->staleRequest($snapshot));
        $c = $session->rotateToken($this->staleRequest($snapshot));

        $this->assertSame($a, $b);
        $this->assertSame($b, $c);
        $this->assertNotSame($snapshot['token'], $a);
        $this->assertLessThanOrEqual(64, strlen($a));
    }

    /** 不同的前代 token 必須推導出不同的新 token。 */
    public function test_different_previous_tokens_derive_different_new_tokens(): void
    {
        $this->select();
        $snapshot = session(CheckoutSession::KEY);

        $session = app(CheckoutSession::class);

        $a = $session->rotateToken($this->staleRequest($snapshot));
        $b = $session->rotateToken($this->staleRequest(
            array_merge($snapshot, ['token' => (string) Str::uuid()])
        ));

        $this->assertNotSame($a, $b);
    }
}
