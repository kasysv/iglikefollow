<?php

namespace Tests\Feature\Fulfillment;

use App\Actions\Fulfillment\SyncFulfillmentState;
use App\Contracts\FulfillmentGateway;
use App\Contracts\TheMostPanelDispatchCredentialSource;
use App\Data\Fulfillment\FulfillmentSubmission;
use App\Data\Fulfillment\FulfillmentSubmissionResult;
use App\Data\Fulfillment\FulfillmentSyncResult;
use App\Enums\FulfillmentStatus;
use App\Models\FulfillmentOrder;
use App\Models\User;
use App\Services\Fulfillment\TheMostPanelCurlCapability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\ConfiguresLiveIntegrations;
use Tests\TestCase;

/**
 * The provider's own words, and its remaining count, kept between syncs.
 *
 * ⭐ Owner 的兩個要求：
 *
 *  1. 後台顯示 TheMostPanel 回傳的**原文**（`In progress`），不再翻譯成
 *     「處理中」——客服在對照 SMM 後台排查時，看到的必須是同一個字串。
 *  2. `Remains` 要能在後台跨頁面看到。它只在每十分鐘的排程同步時取得，
 *     若不落盤，重新整理就消失；而後台頁面**不得**因為被打開就呼叫 provider。
 *
 * ⛔ 內部 `FulfillmentStatus` enum 仍然是唯一控制狀態機、合法轉換與是否停止
 * 輪詢的東西。`provider_status_code` 只保存／顯示原文，⛔ 不參與任何判斷。
 *
 * ⛔ 全程 fake HTTP，0 真實 TheMostPanel request。
 */
class SmmRawStatusAndRemainsTest extends TestCase
{
    use ConfiguresLiveIntegrations;
    use RefreshDatabase;

    public const ENDPOINT = TheMostPanelDispatchAdapterTest::ENDPOINT;

    public const KEY = TheMostPanelDispatchAdapterTest::KEY;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        // testing-only wiring：與 TheMostPanelDispatchAdapterTest 相同。
        config()->set('fulfillment.driver', 'themostpanel');
        $this->enableDispatchSwitch();
        config()->set('integrations.endpoints.themostpanel.production', self::ENDPOINT);

        $this->app->bind(
            TheMostPanelDispatchCredentialSource::class,
            fn () => new class implements TheMostPanelDispatchCredentialSource
            {
                public function apiKey(): ?string
                {
                    return SmmRawStatusAndRemainsTest::KEY;
                }
            },
        );

        // 測試 runtime 沒有 native cap；注入 supported 假能力。
        $this->app->bind(TheMostPanelCurlCapability::class, fn () => TheMostPanelCurlCapability::supported());
    }

    private function submittedRow(string $providerOrderId = '23501'): FulfillmentOrder
    {
        return FulfillmentOrder::factory()->submitted($providerOrderId)->create();
    }

    private function sync(FulfillmentOrder $row): FulfillmentOrder
    {
        return app(SyncFulfillmentState::class)->handle($row->fresh());
    }

    // ==================================== 1. 六個允許 token

    /**
     * ⭐ 六個 exact token：內部 enum 正確，且原文被保存下來。
     *
     * 前四個來自公開文件，`processing` 與 `Cancel` 由 Owner 提供。
     *
     * @return array<string, array{0: string, 1: FulfillmentStatus}>
     */
    public static function allowedTokenProvider(): array
    {
        return [
            'In progress' => ['In progress', FulfillmentStatus::Processing],
            'Completed' => ['Completed', FulfillmentStatus::Completed],
            'Partial' => ['Partial', FulfillmentStatus::Partial],
            'Rejected' => ['Rejected', FulfillmentStatus::Failed],
            'processing (lowercase)' => ['processing', FulfillmentStatus::Processing],
            // ⛔ R1 修正：Owner 指定 `Cancel = 已取消`，不是失敗。
            'Cancel' => ['Cancel', FulfillmentStatus::Canceled],
        ];
    }

    #[DataProvider('allowedTokenProvider')]
    public function test_an_allowed_token_maps_locally_and_keeps_the_raw_text(
        string $token,
        FulfillmentStatus $expected,
    ): void {
        Http::fake([self::ENDPOINT => Http::response(['status' => $token])]);

        $synced = $this->sync($this->submittedRow());

        // 內部 enum 仍由狀態機決定。
        $this->assertSame($expected, $synced->status);
        // ⭐ 原文逐字元保存。
        $this->assertSame($token, $synced->provider_status_code);
        // ⛔ 後台顯示的就是原文，不是本站翻譯。
        $this->assertSame($token, $synced->displayProviderStatus());
        $this->assertNotSame($expected->label(), $synced->displayProviderStatus());

        Http::assertSentCount(1);
    }

    /**
     * ⭐ R1 反證：`Cancel` 與 `Rejected` 是兩個不同的結果，⛔ 不得合併。
     *
     * ⛔ 初版把 `Cancel` 映射成 `Failed`。後果不只是標籤不同：`Canceled` 與
     * `Failed` 的狀態機、事件語意與後台顯示都不一樣，於是後台雖然顯示原文
     * `Cancel`，內部狀態、時間線與任何後續判斷卻記成「失敗」。
     *
     * `Rejected` 是對方**拒絕**了這張單；`Cancel` 是這張單被取消。兩者對客服
     * 的意義完全不同。
     */
    public function test_cancel_and_rejected_map_to_different_internal_states(): void
    {
        Http::fakeSequence(self::ENDPOINT)
            ->push(['status' => 'Cancel'])
            ->push(['status' => 'Rejected']);

        $canceled = $this->sync($this->submittedRow('23501'));
        $rejected = $this->sync($this->submittedRow('23502'));

        $this->assertSame(FulfillmentStatus::Canceled, $canceled->status);
        $this->assertSame('已取消', $canceled->status->label());
        $this->assertSame('Cancel', $canceled->provider_status_code);

        $this->assertSame(FulfillmentStatus::Failed, $rejected->status);
        $this->assertSame('Rejected', $rejected->provider_status_code);

        // ⛔ 兩者不得是同一個內部狀態。
        $this->assertNotSame($canceled->status, $rejected->status);
    }

    /**
     * ⛔ 大小寫與空白差異都是不同的 token，一律 unrecognised。
     *
     * ⛔ 且**不得覆蓋**上一次已保存的原文——一個我們讀不懂的回應，其中的任何
     * 欄位都同樣不可信。
     *
     * @return array<string, array{0: string}>
     */
    public static function rejectedTokenProvider(): array
    {
        return [
            'wrong case' => ['IN PROGRESS'],
            'title case' => ['In Progress'],
            'leading space' => [' In progress'],
            'trailing space' => ['In progress '],
            'completed lowercase' => ['completed'],
            'cancel lowercase' => ['cancel'],
            'cancelled' => ['Cancelled'],
            'unknown' => ['Some Future State'],
            'empty' => [''],
        ];
    }

    #[DataProvider('rejectedTokenProvider')]
    public function test_a_rejected_token_never_overwrites_the_previous_raw_text(string $token): void
    {
        // ⛔ 多回應一律 fakeSequence：先一次成功，再一次讀不懂的回應。
        Http::fakeSequence(self::ENDPOINT)
            ->push(['status' => 'In progress', 'remains' => 500])
            ->push(['status' => $token, 'remains' => 123]);

        $row = $this->submittedRow();
        $this->sync($row);

        $this->assertSame('In progress', $row->fresh()->provider_status_code);
        $this->assertSame(500, $row->fresh()->provider_remains);

        $synced = $this->sync($row);

        // ⛔ 狀態、原文與 remains 全部維持上一次的值。
        $this->assertSame(FulfillmentStatus::Processing, $synced->status);
        $this->assertSame('In progress', $synced->provider_status_code);
        $this->assertSame(500, $synced->provider_remains);
    }

    /**
     * ⛔ 非字串 status 同樣 unrecognised，且不覆蓋既有原文與 remains。
     *
     * ⛔ R1 修正：初版這個測試**實際送出的是字串 `Completed`**——它只驗證了
     * 正常成功路徑，名稱與實作完全相反，沒有任何 non-string 反例。那是一個
     * 假綠燈：測試名稱宣稱涵蓋的路徑，程式從來沒有走過。
     *
     * @return array<string, array{0: mixed}>
     */
    public static function nonStringStatusProvider(): array
    {
        return [
            'int' => [1],
            'zero' => [0],
            'bool true' => [true],
            'bool false' => [false],
            'null' => [null],
            'array' => [['Completed']],
            'object' => [['status' => 'Completed']],
            'float' => [1.5],
        ];
    }

    #[DataProvider('nonStringStatusProvider')]
    public function test_a_non_string_status_is_unrecognised(mixed $status): void
    {
        // ⛔ 先保存一組已知良好的原文與 remains，才驗證得了「不覆蓋」。
        Http::fakeSequence(self::ENDPOINT)
            ->push(['status' => 'In progress', 'remains' => 310])
            ->push(['status' => $status, 'remains' => 999]);

        $row = $this->submittedRow();
        $this->sync($row);

        $this->assertSame('In progress', $row->fresh()->provider_status_code);
        $this->assertSame(310, $row->fresh()->provider_remains);

        $synced = $this->sync($row);

        // ⛔ 讀不懂的回應：狀態、原文與 remains 全部維持上一次的值。
        $this->assertSame(FulfillmentStatus::Processing, $synced->status);
        $this->assertSame('In progress', $synced->provider_status_code);
        $this->assertSame(310, $synced->provider_remains);
    }

    // ==================================== 2. Remains 驗證

    /**
     * 合法的 remains 值。
     *
     * ⭐ `0` 是合法且有意義的——它代表全部補完。
     *
     * @return array<string, array{0: mixed, 1: int}>
     */
    public static function acceptedRemainsProvider(): array
    {
        return [
            'zero int' => [0, 0],
            'zero string' => ['0', 0],
            'positive int' => [1250, 1250],
            'canonical digit string' => ['1250', 1250],
            'large' => [999999999, 999999999],
        ];
    }

    #[DataProvider('acceptedRemainsProvider')]
    public function test_a_valid_remains_is_persisted(mixed $wire, int $expected): void
    {
        Http::fake([self::ENDPOINT => Http::response(['status' => 'In progress', 'remains' => $wire])]);

        $synced = $this->sync($this->submittedRow());

        $this->assertSame($expected, $synced->provider_remains);
    }

    /** ⛔ `0` 必須顯示為 `0`，不得被 placeholder 吞掉。 */
    public function test_zero_remains_displays_as_zero_not_a_placeholder(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['status' => 'Completed', 'remains' => 0])]);

        $synced = $this->sync($this->submittedRow());

        $this->assertSame(0, $synced->provider_remains);
        $this->assertSame('0', $synced->displayRemains());
        $this->assertNotSame('尚未取得', $synced->displayRemains());
    }

    /** 從未取得時維持 null，顯示固定占位。 */
    public function test_remains_starts_null_and_displays_a_placeholder(): void
    {
        $row = $this->submittedRow();

        $this->assertNull($row->provider_remains);
        $this->assertSame('尚未取得', $row->displayRemains());
        $this->assertSame('尚未取得', $row->displayProviderStatus());
    }

    /**
     * ⛔ 不合法的 remains 一律拒絕，並**保留前一次的值**。
     *
     * 一個畸形回應不該讓後台失去先前正確的數字。
     *
     * @return array<string, array{0: mixed}>
     */
    public static function rejectedRemainsProvider(): array
    {
        return [
            'negative int' => [-1],
            'negative string' => ['-5'],
            'float' => [12.5],
            /*
             * ⛔ `100.0` 不列在這裡：`json_encode(100.0)` 產生整數 `100`，
             * 因此整數值的 float 不可能經由 wire 抵達——放進來會變成在測一個
             * 現實中不存在的情況，而且它會（正確地）被當成合法的 int 100。
             *
             * parser 對真正 float 的拒絕由 `12.5` 這一格涵蓋（它保留小數，
             * 確實會以 float 抵達）。
             */
            'bool true' => [true],
            'bool false' => [false],
            'array' => [[100]],
            'empty string' => [''],
            'leading space' => [' 100'],
            'trailing space' => ['100 '],
            'plus sign' => ['+100'],
            'sci notation' => ['1e3'],
            'hex' => ['0x64'],
            'leading zero' => ['0100'],
            'decimal string' => ['100.0'],
            'non numeric' => ['many'],
            'overflow' => ['99999999999999999999'],
        ];
    }

    #[DataProvider('rejectedRemainsProvider')]
    public function test_an_invalid_remains_keeps_the_previous_value(mixed $wire): void
    {
        // ⛔ 多回應一律 fakeSequence：先存已知良好的值，再送不合法的 remains。
        Http::fakeSequence(self::ENDPOINT)
            ->push(['status' => 'In progress', 'remains' => 777])
            ->push(['status' => 'In progress', 'remains' => $wire]);

        $row = $this->submittedRow();
        $this->sync($row);
        $this->assertSame(777, $row->fresh()->provider_remains);

        $synced = $this->sync($row);

        // ⛔ 舊值保留，⛔ 不清成 null。
        $this->assertSame(777, $synced->provider_remains, '⛔ 不合法的 remains 不得清掉舊值：'.var_export($wire, true));
    }

    /** 回應完全沒有 `remains` 欄位時同樣保留舊值。 */
    public function test_a_missing_remains_key_keeps_the_previous_value(): void
    {
        // ⛔ 多回應一律 fakeSequence。
        Http::fakeSequence(self::ENDPOINT)
            ->push(['status' => 'In progress', 'remains' => 42])
            ->push(['status' => 'Partial']);

        $row = $this->submittedRow();
        $this->sync($row);

        $synced = $this->sync($row);

        $this->assertSame(FulfillmentStatus::Partial, $synced->status);
        $this->assertSame(42, $synced->provider_remains);
    }

    // ==================================== 3. 同狀態不同原文

    /**
     * ⭐ 內部狀態沒變、但 provider token 或 remains 變了：仍要更新，
     * ⛔ 但不得灌爆事件表。
     *
     * `In progress` 與 `processing` 都映射到 `Processing`。時間線記錄的是狀態
     * 轉換，不是每十分鐘一筆的數字變化。
     */
    public function test_a_changed_token_under_the_same_status_updates_without_new_events(): void
    {
        /*
         * ⛔ 兩次回應一律用 fakeSequence：`Http::fake()` 疊加時第一個 stub
         * 永遠贏，中途再 fake 一次不會取代它（既有測試檔已記錄這個教訓）。
         */
        Http::fakeSequence(self::ENDPOINT)
            ->push(['status' => 'In progress', 'remains' => 900])
            ->push(['status' => 'processing', 'remains' => 450]);

        $row = $this->submittedRow();
        $this->sync($row);

        $eventsAfterFirst = DB::table('fulfillment_events')->count();

        $synced = $this->sync($row);

        // 內部狀態相同。
        $this->assertSame(FulfillmentStatus::Processing, $synced->status);
        // ⭐ 但原文與 remains 都更新了。
        $this->assertSame('processing', $synced->provider_status_code);
        $this->assertSame(450, $synced->provider_remains);
        // ⛔ 沒有新增狀態事件。
        $this->assertSame($eventsAfterFirst, DB::table('fulfillment_events')->count());
    }

    // ==================================== 4. 終止狀態與競態

    /** 終止狀態保存最後一次值後，維持既有停止輪詢規則。 */
    public function test_a_terminal_status_keeps_its_final_values_and_stops_syncing(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['status' => 'Completed', 'remains' => 0])]);
        $row = $this->submittedRow();
        $synced = $this->sync($row);

        $this->assertSame(FulfillmentStatus::Completed, $synced->status);
        $this->assertSame('Completed', $synced->provider_status_code);
        $this->assertSame(0, $synced->provider_remains);

        $sentSoFar = count(Http::recorded());

        // ⛔ 終止狀態不再輪詢：0 額外 request。
        $again = $this->sync($synced);

        $this->assertSame($sentSoFar, count(Http::recorded()));
        $this->assertSame('Completed', $again->provider_status_code);
        $this->assertSame(0, $again->provider_remains);
    }

    /**
     * ⭐ 真正的 in-flight 競態：provider call **進行中**時，另一個 worker 先
     * 把同一列收斂為 terminal。
     *
     * ⛔ R1 修正：初版先把 DB 推成 Completed，再呼叫 action——而
     * `SyncFulfillmentState::handle()` 開頭的 `fresh()` 會立刻看到 terminal
     * 並直接 return，**第二個 provider response 根本沒被讀取**。那個測試從來
     * 沒有進入它宣稱的競態路徑，是另一個假綠燈。
     *
     * 這次用一個 test-only gateway 建立真正的交錯：`sync()` 被呼叫時（也就是
     * worker A 已經通過 `fresh()` 檢查、正在等 provider 回應的那一刻），
     * 在它**內部**讓 worker B 把該列收斂為 `Completed + remains 0`；等 worker A
     * 拿到較舊的 `In progress + remains 800` 回來時，DB 已經是 terminal。
     *
     * ⛔ 這才是 lock 後重新檢查真正要防的情況。
     */
    public function test_an_in_flight_answer_never_overwrites_terminal_data(): void
    {
        $row = $this->submittedRow();
        $key = $row->getKey();

        // worker B：在 worker A 的 provider call 進行中完成這一列。
        $interleaving = new class($key) implements FulfillmentGateway
        {
            public function __construct(private readonly int $key) {}

            public function submit(FulfillmentSubmission $submission): FulfillmentSubmissionResult
            {
                throw new RuntimeException('不在本測試範圍');
            }

            public function sync(string $providerOrderId): FulfillmentSyncResult
            {
                /*
                 * ⭐ 交錯點：worker A 已經過了 `fresh()` 檢查，正在「等 provider」。
                 * 此刻 worker B 把這一列收斂為 terminal 並寫入 provider 欄位。
                 */
                FulfillmentOrder::query()->whereKey($this->key)->update([
                    'status' => FulfillmentStatus::Completed->value,
                    'provider_status_code' => 'Completed',
                    'provider_remains' => 0,
                    'last_synced_at' => now(),
                ]);

                // worker A 拿到的是較舊的答案。
                return FulfillmentSyncResult::status(
                    FulfillmentStatus::Processing,
                    'In progress',
                    800,
                );
            }
        };

        $eventsBefore = DB::table('fulfillment_events')->count();

        (new SyncFulfillmentState($interleaving))->handle($row->fresh());

        $final = $row->fresh();

        // ⛔ terminal 的內部狀態與 provider 欄位全部原樣保留。
        $this->assertSame(FulfillmentStatus::Completed, $final->status);
        $this->assertSame('Completed', $final->provider_status_code, '⛔ 舊回應不得覆蓋 terminal 原文。');
        $this->assertSame(0, $final->provider_remains, '⛔ 舊回應不得覆蓋 terminal remains。');

        /*
         * ⛔ 事件必須以 lock 後的**現況**記錄，不得假造舊狀態。
         *
         * 這條路徑會寫一筆 unrecognised 事件（我們讀到的答案在此刻已不合法），
         * 而它的 from／to 都必須是 lock 之後看到的 `completed`——時間線是
         * append-only，一筆宣稱「submitted → submitted」的錯誤紀錄永遠改不掉。
         */
        $events = DB::table('fulfillment_events')
            ->where('fulfillment_order_id', $key)
            ->get();

        $this->assertGreaterThan($eventsBefore, $events->count());

        foreach ($events as $event) {
            foreach (['from_status', 'to_status'] as $column) {
                if ($event->{$column} !== null) {
                    $this->assertNotSame(
                        FulfillmentStatus::Submitted->value,
                        $event->{$column},
                        '⛔ 事件不得記錄 lock 之前的舊狀態。',
                    );
                }
            }
        }
    }

    // ==================================== 5. 後台四處顯示

    /**
     * ⭐ 四個位置都顯示原文與 Remains。
     *
     * ⛔ 同時確認打開頁面 **0 provider request**——後台不得因為被瀏覽就外呼。
     */
    public function test_all_four_admin_surfaces_show_the_raw_status_and_remains(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['status' => 'In progress', 'remains' => 1250])]);
        $row = $this->submittedRow();
        $this->sync($row);

        $row = $row->fresh();
        $requestsAfterSync = count(Http::recorded());

        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);

        // ⛔ Order 的 route key 是 `reference`，不是 id（見 getRouteKeyName()）。
        $orderReference = $row->orderItem->order->reference;

        $pages = [
            '訂單詳情' => "/admin/orders/{$orderReference}",
            '履約紀錄列表' => '/admin/fulfillment-orders',
            '履約紀錄詳情' => "/admin/fulfillment-orders/{$row->id}",
        ];

        foreach ($pages as $label => $url) {
            $response = $this->actingAs($owner)->get($url);
            $response->assertOk();

            $html = $response->getContent();

            $this->assertStringContainsString('In progress', (string) $html, "{$label} 應顯示 provider 原文");
            $this->assertStringContainsString('1,250', (string) $html, "{$label} 應顯示 Remains");
            /*
             * ⛔ 欄位標籤：訂單頁依 Owner 指定的欄序改為「剩餘」，
             * 履約列表／詳情維持「剩餘數量（Remains）」。兩者都以「剩餘」開頭，
             * 所以這裡斷言共同前綴即可，不因命名差異誤判。
             */
            $this->assertStringContainsString('剩餘', (string) $html, "{$label} 應有 Remains 欄位標籤");
        }

        // ⛔ 打開後台頁面沒有產生任何 provider request。
        $this->assertSame($requestsAfterSync, count(Http::recorded()), '⛔ 開頁面不得外呼 TheMostPanel。');
    }

    /** ⛔ 後台仍不得顯示 API key、raw body、交付對象或 provider 訊息。 */
    public function test_the_admin_still_hides_secrets_and_customer_data(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['status' => 'In progress', 'remains' => 1250])]);
        $row = $this->submittedRow();
        $this->sync($row);
        $row = $row->fresh();

        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);

        $html = (string) $this->actingAs($owner)
            ->get("/admin/fulfillment-orders/{$row->id}")->getContent();

        foreach ([self::KEY, TheMostPanelDispatchAdapterTest::TARGET] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $html);
        }
    }

    // ==================================== 6. 排程與開關不變

    /** ⛔ scheduler 頻率與開關來源 0 修改。 */
    public function test_the_scheduler_frequency_is_unchanged(): void
    {
        $console = file_get_contents(base_path('routes/console.php'));

        $this->assertStringContainsString('fulfillment:queue-status-sync', (string) $console);
        $this->assertStringContainsString('everyTenMinutes', (string) $console);
    }

    // ==================================== 6b. start_count（A1）

    /**
     * ⭐ `start_count` 與 `remains` 共用完全相同的驗證規則。
     *
     * ⛔ 兩份規則就是兩份會各自漂移的規則，而其中任何一份放寬都會讓未驗證的
     * 數字進入後台顯示。這裡逐項確認兩者行為一致。
     *
     * @return array<string, array{0: mixed, 1: ?int}>
     */
    public static function startCountProvider(): array
    {
        return [
            // 合法。
            'zero int' => [0, 0],
            'zero string' => ['0', 0],
            'positive int' => [4200, 4200],
            'canonical digit string' => ['4200', 4200],
            // ⛔ 不合法：一律回 null，保留舊值。
            'negative' => [-1, null],
            'negative string' => ['-5', null],
            'float' => [12.5, null],
            'bool' => [true, null],
            'array' => [[100], null],
            'empty string' => ['', null],
            'padded' => [' 100', null],
            'plus sign' => ['+100', null],
            'sci notation' => ['1e3', null],
            'leading zero' => ['0100', null],
            'non numeric' => ['many', null],
            'overflow' => ['99999999999999999999', null],
        ];
    }

    #[DataProvider('startCountProvider')]
    public function test_start_count_follows_the_same_rules_as_remains(mixed $wire, ?int $expected): void
    {
        // 先存一個已知良好的值，才驗證得了「不合法時保留舊值」。
        Http::fakeSequence(self::ENDPOINT)
            ->push(['status' => 'In progress', 'start_count' => 999])
            ->push(['status' => 'In progress', 'start_count' => $wire]);

        $row = $this->submittedRow();
        $this->sync($row);
        $this->assertSame(999, $row->fresh()->provider_start_count);

        $synced = $this->sync($row);

        $this->assertSame(
            $expected ?? 999,
            $synced->provider_start_count,
            '⛔ 不合法的 start_count 不得清掉舊值：'.var_export($wire, true),
        );
    }

    /** ⛔ `0` 顯示為 `0`；`null` 顯示「尚未取得」。 */
    public function test_start_count_zero_and_null_display_correctly(): void
    {
        $row = $this->submittedRow();

        // 尚未同步。
        $this->assertNull($row->provider_start_count);
        $this->assertSame('尚未取得', $row->displayStartCount());

        Http::fake([self::ENDPOINT => Http::response(['status' => 'In progress', 'start_count' => 0])]);
        $synced = $this->sync($row);

        $this->assertSame(0, $synced->provider_start_count);
        $this->assertSame('0', $synced->displayStartCount());
        $this->assertNotSame('尚未取得', $synced->displayStartCount());
    }

    /** 缺少 `start_count` 欄位時保留舊值。 */
    public function test_a_missing_start_count_keeps_the_previous_value(): void
    {
        Http::fakeSequence(self::ENDPOINT)
            ->push(['status' => 'In progress', 'start_count' => 88])
            ->push(['status' => 'Partial']);

        $row = $this->submittedRow();
        $this->sync($row);
        $synced = $this->sync($row);

        $this->assertSame(FulfillmentStatus::Partial, $synced->status);
        $this->assertSame(88, $synced->provider_start_count);
    }

    /** ⛔ unrecognised 回應不得覆蓋既有 start_count。 */
    public function test_an_unrecognised_response_keeps_the_previous_start_count(): void
    {
        Http::fakeSequence(self::ENDPOINT)
            ->push(['status' => 'In progress', 'start_count' => 500, 'remains' => 100])
            ->push(['status' => 'Some Future State', 'start_count' => 7, 'remains' => 7]);

        $row = $this->submittedRow();
        $this->sync($row);
        $synced = $this->sync($row);

        $this->assertSame(500, $synced->provider_start_count);
        $this->assertSame(100, $synced->provider_remains);
    }

    /** ⛔ terminal 之後不再輪詢，最後一次的 start_count 保留。 */
    public function test_a_terminal_row_keeps_its_final_start_count(): void
    {
        Http::fake([self::ENDPOINT => Http::response([
            'status' => 'Completed', 'start_count' => 1000, 'remains' => 0,
        ])]);

        $row = $this->submittedRow();
        $synced = $this->sync($row);

        $this->assertSame(FulfillmentStatus::Completed, $synced->status);
        $this->assertSame(1000, $synced->provider_start_count);

        $sent = count(Http::recorded());
        $again = $this->sync($synced);

        $this->assertSame($sent, count(Http::recorded()), '⛔ terminal 不再輪詢。');
        $this->assertSame(1000, $again->provider_start_count);
    }

    /**
     * ⭐ Owner 指定的八欄順序，⛔ 逐項固定。
     *
     * 已送出時間 → SMM 訂單編號 → SMM 服務名稱 → 起始值 → 數量 → 狀態
     * → 剩餘 → 最後同步時間
     */
    public function test_the_order_page_shows_the_eight_columns_in_the_specified_order(): void
    {
        Http::fake([self::ENDPOINT => Http::response([
            'status' => 'In progress', 'start_count' => 4200, 'remains' => 1250,
        ])]);

        $row = $this->submittedRow();
        $this->sync($row);
        $row = $row->fresh();

        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $reference = $row->orderItem->order->reference;

        $full = (string) $this->actingAs($owner)->get("/admin/orders/{$reference}")->getContent();

        /*
         * ⛔ 只在「SMM 履約進度」區塊內比對位置。
         *
         * 「數量」「狀態」等字樣在商品快照、交易流程等其他區塊也會出現，
         * 用整頁的 first-occurrence 位置會比到別的區塊，得到假的順序結論。
         */
        $sectionStart = strpos($full, 'SMM 履約進度');
        $this->assertNotFalse($sectionStart, '找不到 SMM 履約進度區塊');

        $sectionEnd = strpos($full, '訂單時間線', $sectionStart);
        $html = substr($full, $sectionStart, ($sectionEnd ?: strlen($full)) - $sectionStart);

        $labels = ['已送出時間', 'SMM 訂單編號', 'SMM 服務名稱', '起始值', '數量', '狀態', '剩餘', '最後同步時間'];

        $previous = -1;

        foreach ($labels as $label) {
            $position = strpos($html, $label);

            $this->assertNotFalse($position, "缺少欄位：{$label}");
            $this->assertGreaterThan($previous, $position, "欄序不符：{$label} 出現位置早於前一欄");
            $previous = $position;
        }

        // 值本身也要顯示。
        $this->assertStringContainsString('4,200', $html);
        $this->assertStringContainsString('1,250', $html);
    }

    // ==================================== 7. migration rollback guard

    /**
     * ⭐ `down()` 在已有同步資料時必須 fail closed。
     *
     * 這是 additive migration，code rollback 只要 revert commit——欄位留著不
     * 影響舊程式。真正危險的是有人在已經同步過的環境跑 `migrate:rollback`：
     * ⛔ 那會把每一筆已取得的 remains 永久刪除，而那些值來自 provider，
     * 本站無法重建。
     *
     * ⛔ 所以只有全部為 null 時才允許 drop；否則明確拋出，讓人先決定怎麼處理。
     * ⛔ 不自動備份、不自動清空——那都是替使用者做決定。
     */
    public function test_the_migration_refuses_to_drop_a_column_holding_data(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['status' => 'In progress', 'remains' => 640])]);
        $row = $this->submittedRow();
        $this->sync($row);

        $this->assertSame(640, $row->fresh()->provider_remains);

        $migration = require database_path(
            'migrations/2026_08_26_100000_add_provider_remains_to_fulfillment_orders_table.php'
        );

        $this->expectException(RuntimeException::class);
        $migration->down();
    }

    /** 全部為 null 時才允許 drop。 */
    public function test_the_migration_drops_the_column_when_no_data_exists(): void
    {
        // 建立一列但從未同步 → provider_remains 維持 null。
        $this->submittedRow();

        $this->assertSame(0, DB::table('fulfillment_orders')->whereNotNull('provider_remains')->count());

        $migration = require database_path(
            'migrations/2026_08_26_100000_add_provider_remains_to_fulfillment_orders_table.php'
        );

        $migration->down();

        $this->assertFalse(Schema::hasColumn('fulfillment_orders', 'provider_remains'));

        // 還原，避免影響同一個測試程序中的其他測試。
        $migration->up();
        $this->assertTrue(Schema::hasColumn('fulfillment_orders', 'provider_remains'));
    }
}
