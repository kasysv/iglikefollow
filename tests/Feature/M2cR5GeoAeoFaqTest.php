<?php

namespace Tests\Feature;

use App\Models\Faq;
use App\Models\Platform;
use App\Models\Service;
use App\Models\ServiceVariant;
use App\Support\FaqPageContent;
use App\Support\ProductSlugMap;
use Database\Seeders\CatalogSeeder;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\IsolatesSnapshotStorage;
use Tests\Concerns\SeedsThreadsCatalog;
use Tests\TestCase;

/**
 * M2-C R5:GEO／AEO FAQ 與 `/faq` 導航頁。
 *
 * 核心不變式:
 * - 每題只有唯一完整 owner 頁(global→/faq、platform→Hub、service→商品頁);
 *   首頁只允許核准的 3 題精選重複,其他跨 scope 完整重複 0。
 * - draft(訂單查詢功能未驗收)在任何公開頁 0 命中。
 * - R5 只碰 `faqs` 表的 r5.* exact keys;R3 managed 與 Owner 自建列不動。
 * - 原 13 頁 SEO 表面不因 R5 改變。
 */
class M2cR5GeoAeoFaqTest extends TestCase
{
    use IsolatesSnapshotStorage;
    use RefreshDatabase;
    use SeedsThreadsCatalog;

    /** `/faq` 的固定 SEO(逐字取自 R5 規格)。 */
    private const FAQ_TITLE = '常見問題｜付款、電子發票與訂單說明｜IGLIKEFOLLOW';

    private const FAQ_DESCRIPTION = '選購 Instagram、Facebook、Threads 粉絲、貼文讚、影片觀看與瀏覽服務前，快速了解免會員下單、帳號或網址填寫、付款方式、電子發票、處理時間、重複下單與異常訂單處理。';

    private const FAQ_H1 = 'IGLIKEFOLLOW 購買與訂單常見問題';

    private const FAQ_INTRO = '整理購買 IG 粉絲、買讚、影片觀看與 Threads 瀏覽等服務前最常見的問題。從下單資料、付款與電子發票，到同一連結重複下單與異常訂單處理，都可在此快速找到答案。';

    /** 同題就地更新的既有 R3 global key(⛔ 不得再建平行重複列)。 */
    private const REUSED_R3_KEYS = ['r3.global.membership', 'r3.global.prepare', 'r3.global.price'];

    /** ⛔ 訂單查詢功能未驗收前,這段文字不得出現在任何公開頁。 */
    private const DRAFT_ANSWER_FRAGMENT = '將提供公開訂單查詢入口';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        FaqPageContent::flush();

        // ⛔ 快照一律寫進拋棄式目錄,不得污染 Owner 的真實還原資產。
        $this->isolateSnapshotStorage();

        $this->seed(CatalogSeeder::class);
        $this->seedThreadsCatalog();
        Artisan::call('m2c:apply-copy');
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedSnapshotStorage();

        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function fixture(): array
    {
        return json_decode((string) file_get_contents(database_path('fixtures/m2c-r5-faq.json')), true);
    }

    private function apply(): string
    {
        $this->assertSame(0, Artisan::call('m2c:apply-r5-faq'));
        preg_match('/snapshot=(\S+\.json)/u', Artisan::output(), $m);
        $this->assertNotEmpty($m[1] ?? '');

        return $m[1];
    }

    /** R5 fixture 擁有的全部 managed key(r5.* + 同題沿用的 R3 key)。 @return list<string> */
    private function managedKeys(): array
    {
        $fixture = $this->fixture();

        return array_merge(
            array_column($fixture['faqs']['global'], 'managed_key'),
            array_column($fixture['faqs']['platform'], 'managed_key'),
            array_column($fixture['faqs']['service'], 'managed_key'),
        );
    }

    /** R5 擁有的列數(⛔ 不能只數 r5.*,同題沿用 R3 key 的 3 列也算)。 */
    private function managedRowCount(): int
    {
        return Faq::withTrashed()->whereIn('managed_key', $this->managedKeys())->count();
    }

    /** 受控列的逐欄指紋,用來證明衝突時 0 writes。 @return array<int, mixed> */
    private function managedRowsFingerprint(): array
    {
        return Faq::withTrashed()->whereIn('managed_key', $this->managedKeys())->orderBy('id')
            ->get()
            ->map
            ->only(['managed_key', 'scope', 'platform_id', 'service_id', 'question', 'answer', 'status', 'sort_order', 'deleted_at'])
            ->toArray();
    }

    /**
     * 隔離目錄內的快照檔案集合(檔名→sha256)。
     *
     * @return array<string, string>
     */
    private function snapshotFiles(): array
    {
        $files = glob($this->snapshotDirectory().'/*.json') ?: [];
        $out = [];

        foreach ($files as $file) {
            $out[basename($file)] = (string) hash_file('sha256', $file);
        }

        ksort($out);

        return $out;
    }

    /** 所有公開頁面(13 頁 + /faq)的 HTML。 @return array<string, string> */
    private function publicPages(): array
    {
        $pages = [
            '/' => $this->get('/')->assertOk()->getContent(),
            '/faq' => $this->get('/faq')->assertOk()->getContent(),
        ];

        foreach (['instagram', 'facebook', 'threads'] as $slug) {
            $pages["/services/{$slug}"] = $this->get("/services/{$slug}")->assertOk()->getContent();
        }

        foreach (Service::query()->whereNotNull('product_slug')->get() as $service) {
            $pages[$service->primaryUrl()] = $this->get($service->primaryUrl())->assertOk()->getContent();
        }

        return $pages;
    }

    // ------------------------------------------------------------------
    // 1. fixture schema / stable key / scope owner / draft gate
    // ------------------------------------------------------------------

    public function test_the_fixture_carries_exact_schema_stable_keys_and_scope_owners(): void
    {
        $fixture = $this->fixture();

        $this->assertSame(['_meta', 'page', 'faqs'], array_keys($fixture));
        $this->assertSame(['global', 'platform', 'service'], array_keys($fixture['faqs']));

        // 21 published + 1 draft。
        $published = 0;
        $draft = 0;
        $keys = [];

        foreach (['global', 'platform', 'service'] as $scope) {
            foreach ($fixture['faqs'][$scope] as $item) {
                /*
                 * ⛔ 每列都要有 stable key 且全域唯一:新題用 `r5.*`,
                 * 3 題與 R3 同題者沿用精確 R3 key 就地更新(不建平行重複列)。
                 */
                $this->assertTrue(
                    str_starts_with($item['managed_key'], 'r5.')
                        || in_array($item['managed_key'], self::REUSED_R3_KEYS, true),
                    "未預期的 managed key:{$item['managed_key']}",
                );
                $this->assertNotContains($item['managed_key'], $keys);
                $keys[] = $item['managed_key'];

                $this->assertNotSame('', trim($item['question']));
                $this->assertNotSame('', trim($item['answer']));
                $this->assertIsInt($item['sort_order']);

                $item['status'] === 'published' ? $published++ : $draft++;

                // scope owner 欄位:global 無 owner、platform 帶 platform_slug、service 帶 product_slug。
                match ($scope) {
                    'global' => $this->assertSame(['managed_key', 'question', 'answer', 'status', 'sort_order'], array_keys($item)),
                    'platform' => $this->assertContains($item['platform_slug'], ['instagram', 'facebook', 'threads']),
                    'service' => $this->assertContains($item['product_slug'], array_values(ProductSlugMap::MAP)),
                };
            }
        }

        $this->assertSame(21, $published);
        $this->assertSame(1, $draft);
        $this->assertSame(9, count($fixture['faqs']['global']) - 1);
        $this->assertCount(3, $fixture['faqs']['platform']);
        $this->assertCount(9, $fixture['faqs']['service']);

        // 首頁精選必須是 published global key(⛔ 不得指到 draft)。
        $this->assertCount(3, $fixture['page']['home_featured_keys']);

        foreach ($fixture['page']['home_featured_keys'] as $key) {
            $row = collect($fixture['faqs']['global'])->firstWhere('managed_key', $key);
            $this->assertNotNull($row);
            $this->assertSame('published', $row['status']);
        }

        // 訂單查詢題必須是 draft。
        $lookup = collect($fixture['faqs']['global'])->firstWhere('managed_key', 'r5.global.order-lookup');
        $this->assertSame('draft', $lookup['status']);
    }

    public function test_the_importer_refuses_unknown_scope_owners_and_non_r5_keys(): void
    {
        $path = database_path('fixtures/m2c-r5-faq.json');
        $original = (string) file_get_contents($path);

        $mutations = [
            // 未知 platform。
            fn (array $f) => tap($f, function (&$f) {
                $f['faqs']['platform'][0]['platform_slug'] = 'tiktok';
            }),
            // 未知 product slug。
            fn (array $f) => tap($f, function (&$f) {
                $f['faqs']['service'][0]['product_slug'] = 'ig買不存在';
            }),
            // ⛔ 不在允許清單內的 R3 key(如商品頁 FAQ)不得被 R5 改寫。
            fn (array $f) => tap($f, function (&$f) {
                $f['faqs']['global'][0]['managed_key'] = 'r3.ig買粉絲.faq.data';
            }),
            // 重複 key。
            fn (array $f) => tap($f, function (&$f) {
                $f['faqs']['global'][1]['managed_key'] = $f['faqs']['global'][0]['managed_key'];
            }),
            // 空值。
            fn (array $f) => tap($f, function (&$f) {
                $f['faqs']['global'][0]['answer'] = '   ';
            }),
            // 額外欄位。
            fn (array $f) => tap($f, function (&$f) {
                $f['faqs']['global'][0]['extra'] = 'x';
            }),
            // 精選指向 draft。
            fn (array $f) => tap($f, function (&$f) {
                $f['page']['home_featured_keys'][0] = 'r5.global.order-lookup';
            }),
        ];

        try {
            foreach ($mutations as $i => $mutate) {
                file_put_contents($path, json_encode($mutate(json_decode($original, true)), JSON_UNESCAPED_UNICODE));

                $this->assertSame(1, Artisan::call('m2c:apply-r5-faq'), "mutation {$i} 應被拒絕");
                $this->assertSame(0, $this->managedRowCount(), "mutation {$i} 不得留下寫入");
            }
        } finally {
            file_put_contents($path, $original);
        }
    }

    // ------------------------------------------------------------------
    // 2. dry-run 0 writes / apply / idempotency
    // ------------------------------------------------------------------

    public function test_dry_run_writes_nothing_and_leaves_no_snapshot(): void
    {
        $before = Faq::query()->orderBy('id')->get()->toArray();
        $snapshotsBefore = $this->snapshotFiles();

        $this->assertSame(0, Artisan::call('m2c:apply-r5-faq', ['--dry-run' => true]));
        $this->assertStringContainsString('0 writes', Artisan::output());

        $this->assertSame(0, $this->managedRowCount());
        $this->assertEquals($before, Faq::query()->orderBy('id')->get()->toArray());

        // dry-run ⛔ 不得產生快照檔。
        $this->assertSame($snapshotsBefore, $this->snapshotFiles());
    }

    public function test_apply_is_idempotent(): void
    {
        $this->apply();

        $this->assertSame(22, $this->managedRowCount());

        $rows = Faq::query()->whereIn('managed_key', $this->managedKeys())->orderBy('id')
            ->get()->map->only(['managed_key', 'question', 'answer', 'status', 'sort_order'])->toArray();

        $this->assertSame(0, Artisan::call('m2c:apply-r5-faq'));
        $output = Artisan::output();

        $this->assertStringContainsString('created=0', $output);
        $this->assertStringContainsString('updated=0', $output);
        $this->assertStringContainsString('unchanged=22', $output);

        $this->assertSame($rows, Faq::query()->whereIn('managed_key', $this->managedKeys())->orderBy('id')
            ->get()->map->only(['managed_key', 'question', 'answer', 'status', 'sort_order'])->toArray());
    }

    public function test_apply_never_touches_r3_managed_or_owner_authored_faqs(): void
    {
        // Owner 自建列(managed_key IS NULL)+R3 managed 列。
        Artisan::call('m2c:apply-r3');

        $ownerFaq = Faq::query()->create([
            'scope' => 'global', 'question' => 'Owner 自己加的問題？', 'answer' => 'Owner 自己寫的答案。',
            'status' => 'published', 'sort_order' => 99,
        ]);

        /*
         * ⛔ 除了 3 個核准的同題 R3 key(就地改寫)以外,其餘 R3 managed
         * 與 Owner 自建列都必須逐欄不動。
         */
        $snapshot = fn () => Faq::query()
            ->where(fn ($q) => $q->whereNull('managed_key')->orWhere('managed_key', 'like', 'r3.%'))
            ->whereNotIn('managed_key', self::REUSED_R3_KEYS)
            ->orderBy('id')->get()->map->only(['id', 'scope', 'question', 'answer', 'status', 'sort_order', 'managed_key'])->toArray();

        $before = $snapshot();

        $this->apply();

        $this->assertSame($before, $snapshot());
        $this->assertDatabaseHas('faqs', ['id' => $ownerFaq->id, 'answer' => 'Owner 自己寫的答案。']);
    }

    // ------------------------------------------------------------------
    // 2b. Owner conflict guard(R1):受控列被後台改過即 fail closed
    // ------------------------------------------------------------------

    /**
     * 每個受控欄位被 Owner 後改後,apply 都必須整批拒絕。
     *
     * ⛔ 這是 R5 首次交付的缺口:原本只用 exact key 定位後直接覆寫,
     * Owner 在後台改過的答案會被靜默蓋掉。
     *
     * @return array<string, array{string, array<string, mixed>}>
     */
    public static function ownerEditProvider(): array
    {
        return [
            // 既有 r5.* key。
            'r5 key: answer' => ['r5.global.invoice', ['answer' => 'Owner 改寫的答案。']],
            'r5 key: question' => ['r5.global.invoice', ['question' => 'Owner 改寫的問題？']],
            'r5 key: status' => ['r5.global.invoice', ['status' => 'draft']],
            'r5 key: sort_order' => ['r5.global.invoice', ['sort_order' => 77]],
            'r5 key: scope' => ['r5.global.invoice', ['scope' => 'platform']],
            'r5 key: owner' => ['r5.ig買粉絲.tiers', ['service_id' => null]],
            // 可重用的 R3 key(同題就地更新對象)。
            'reused r3 key: answer' => ['r3.global.price', ['answer' => 'Owner 改寫的價格說明。']],
            'reused r3 key: status' => ['r3.global.price', ['status' => 'draft']],
        ];
    }

    /**
     * @param  array<string, mixed>  $edit
     */
    #[DataProvider('ownerEditProvider')]
    public function test_apply_fails_closed_when_a_managed_row_was_edited_by_the_owner(string $key, array $edit): void
    {
        $this->apply();

        $before = $this->managedRowsFingerprint();
        $snapshotsBefore = $this->snapshotFiles();

        Faq::query()->where('managed_key', $key)->firstOrFail()->forceFill($edit)->saveQuietly();

        $afterEdit = $this->managedRowsFingerprint();

        $this->assertSame(1, Artisan::call('m2c:apply-r5-faq'));
        $output = Artisan::output();

        // 固定 conflict identifier,⛔ 不得帶文案內容。
        $this->assertStringContainsString('R5-APPLY-CONFLICT:faq.'.$key, $output);

        foreach ($edit as $value) {
            if (is_string($value)) {
                $this->assertStringNotContainsString($value, $output);
            }
        }

        // 0 writes:Owner 的修改原封不動,其他受控列也沒被動。
        $this->assertSame($afterEdit, $this->managedRowsFingerprint());
        $this->assertNotSame($before, $afterEdit);

        // ⛔ 0 新 snapshot。
        $this->assertSame($snapshotsBefore, $this->snapshotFiles());
    }

    // ------------------------------------------------------------------
    // 2c. R2:lock → guard → snapshot → apply 必須在同一 transaction
    // ------------------------------------------------------------------

    /**
     * real apply 必須在 transaction 內、於任何寫入之前鎖定受控列。
     *
     * ⛔ 不以 method 名稱或原始碼字串斷言,而是攔截實際送到資料庫的 SQL:
     * 證明(a)有 transaction 並 commit、(b)受控列的鎖定查詢存在、
     * (c)鎖定發生在第一次 FAQ 寫入之前。
     */
    public function test_real_apply_locks_managed_rows_before_any_write(): void
    {
        Artisan::call('m2c:apply-r3');

        $log = [];
        DB::listen(function ($query) use (&$log) {
            $log[] = $query->sql;
        });

        $started = 0;
        $committed = 0;
        Event::listen(TransactionBeginning::class, function () use (&$started) {
            $started++;
        });
        Event::listen(TransactionCommitted::class, function () use (&$committed) {
            $committed++;
        });

        $this->assertSame(0, Artisan::call('m2c:apply-r5-faq'));

        // (a) 真的開了 transaction 並 commit。
        $this->assertGreaterThan(0, $started);
        $this->assertGreaterThan(0, $committed);

        $lockIndex = null;
        $firstWriteIndex = null;

        foreach ($log as $i => $sql) {
            $isManagedSelect = str_contains($sql, 'from "faqs"')
                && str_contains($sql, '"managed_key" in')
                && str_contains($sql, 'order by "managed_key"');

            if ($lockIndex === null && $isManagedSelect) {
                $lockIndex = $i;
            }

            if ($firstWriteIndex === null
                && (str_starts_with($sql, 'insert into "faqs"') || str_starts_with($sql, 'update "faqs"'))) {
                $firstWriteIndex = $i;
            }
        }

        // (b) 鎖定查詢存在。
        $this->assertNotNull($lockIndex, '找不到受控列的鎖定查詢');

        // (c) 鎖定必須早於第一次 FAQ 寫入。
        $this->assertNotNull($firstWriteIndex, '本輪應有 FAQ 寫入');
        $this->assertLessThan($firstWriteIndex, $lockIndex, '鎖定必須發生在任何 FAQ 寫入之前');
    }

    /**
     * 鎖定查詢在支援 row lock 的 driver 上真的編譯成 `for update`。
     *
     * 本機測試庫是 SQLite,其 grammar 靜默省略 `for update`(SQLite 沒有
     * row lock 概念,靠整庫寫鎖)。因此「並行 Owner 寫入被擋下」⛔ 無法在
     * SQLite 上真實反證;此測試退而證明 query 帶著 lock 意圖,在 MySQL／
     * Postgres grammar 下會輸出 `for update`。
     */
    public function test_the_managed_row_lock_compiles_to_for_update_on_locking_drivers(): void
    {
        $query = Faq::withTrashed()
            ->whereIn('managed_key', ['r5.global.invoice', 'r3.global.price'])
            ->orderBy('managed_key')
            ->lockForUpdate()
            ->getQuery();

        $connection = DB::connection();

        foreach ([new MySqlGrammar($connection), new PostgresGrammar($connection)] as $grammar) {
            $this->assertStringContainsString('for update', $grammar->compileSelect($query));
        }

        // 現行測試 driver 是 SQLite:如實記錄,不假裝有 row lock。
        $this->assertSame('sqlite', $connection->getDriverName());
    }

    /**
     * snapshot 的 before-state 必須來自「同一批 locked rows」。
     *
     * 反證:3 個可重用 R3 key 在 apply 前是 R3 baseline 值,apply 後 DB 已是
     * R5 值。若 before-state 是事後重新查詢產生的,快照就會記成 R5 值。
     */
    public function test_snapshot_before_state_comes_from_the_locked_rows(): void
    {
        Artisan::call('m2c:apply-r3');

        $before = Faq::query()->whereIn('managed_key', self::REUSED_R3_KEYS)->orderBy('managed_key')
            ->get()->map->only(['managed_key', 'question', 'answer', 'status', 'sort_order'])->toArray();

        $snapshot = json_decode((string) file_get_contents($this->apply()), true);

        $recorded = collect($snapshot['managed_faqs_before'])
            ->sortBy('managed_key')
            ->map(fn (array $row) => [
                'managed_key' => $row['managed_key'],
                'question' => $row['question'],
                'answer' => $row['answer'],
                'status' => $row['status'],
                'sort_order' => (int) $row['sort_order'],
            ])
            ->values()
            ->all();

        $this->assertSame($before, $recorded);

        // apply 後 DB 已是 R5 值 → 證明快照記的是「被覆寫前」的狀態。
        $index = array_search('r3.global.price', array_column($recorded, 'managed_key'), true);
        $this->assertNotSame(
            $recorded[$index]['answer'],
            Faq::query()->where('managed_key', 'r3.global.price')->value('answer'),
        );
    }

    /**
     * 快照之後發生狀態漂移時:0 FAQ writes、0 受保護表 writes、
     * 不留下本次快照,且既有快照不被誤刪。
     */
    public function test_a_drift_after_snapshot_leaves_no_writes_and_no_new_snapshot(): void
    {
        $this->apply();

        // sentinel 代表「既有真快照」,證明失敗清理不會波及它。
        $sentinel = $this->snapshotDirectory().'/r5-faq-existing-sentinel.json';
        file_put_contents($sentinel, '{"keep":true}');

        $snapshotsBefore = $this->snapshotFiles();
        $faqsBefore = $this->managedRowsFingerprint();
        $protectedBefore = DB::table('services')->orderBy('id')->get()->toJson();

        Faq::query()->where('managed_key', 'r5.global.invoice')
            ->firstOrFail()->forceFill(['answer' => 'drift。'])->saveQuietly();

        $drifted = $this->managedRowsFingerprint();

        $this->assertSame(1, Artisan::call('m2c:apply-r5-faq'));
        $this->assertStringContainsString('R5-APPLY-CONFLICT:faq.r5.global.invoice', Artisan::output());

        // 0 writes:漂移後的狀態原封不動,受保護表也沒動。
        $this->assertSame($drifted, $this->managedRowsFingerprint());
        $this->assertNotSame($faqsBefore, $drifted);
        $this->assertSame($protectedBefore, DB::table('services')->orderBy('id')->get()->toJson());

        // 0 新快照,既有 sentinel 仍在。
        $this->assertSame($snapshotsBefore, $this->snapshotFiles());
        $this->assertFileExists($sentinel);
    }

    public function test_apply_fails_closed_when_a_managed_row_was_soft_deleted(): void
    {
        $this->apply();

        $snapshotsBefore = $this->snapshotFiles();
        Faq::query()->where('managed_key', 'r5.global.password')->firstOrFail()->delete();

        $this->assertSame(1, Artisan::call('m2c:apply-r5-faq'));
        $this->assertStringContainsString('R5-APPLY-CONFLICT:faq.r5.global.password', Artisan::output());

        // ⛔ 不得靜默復活並覆寫。
        $this->assertSoftDeleted('faqs', ['managed_key' => 'r5.global.password']);
        $this->assertSame($snapshotsBefore, $this->snapshotFiles());
    }

    public function test_apply_still_upgrades_a_clean_r3_baseline_and_stays_idempotent(): void
    {
        // 乾淨 R3 baseline(3 個可重用 key 為未修改的 R3 值)可一次升級。
        Artisan::call('m2c:apply-r3');

        $this->assertSame(0, Artisan::call('m2c:apply-r5-faq'));
        $this->assertStringContainsString('created=19', Artisan::output());

        // 第二次:0 created／0 updated。
        $this->assertSame(0, Artisan::call('m2c:apply-r5-faq'));
        $output = Artisan::output();
        $this->assertStringContainsString('created=0', $output);
        $this->assertStringContainsString('updated=0', $output);
        $this->assertStringContainsString('unchanged=22', $output);

        $this->assertSame(22, $this->managedRowCount());
    }

    public function test_conflicting_apply_leaves_protected_tables_untouched(): void
    {
        $this->apply();

        $protected = fn () => [
            'services' => DB::table('services')->orderBy('id')->get()->toJson(),
            'service_variants' => DB::table('service_variants')->orderBy('id')->get()->toJson(),
            'platforms' => DB::table('platforms')->orderBy('id')->get()->toJson(),
            'site_settings' => DB::table('site_settings')->orderBy('id')->get()->toJson(),
            'orders' => DB::table('orders')->orderBy('id')->get()->toJson(),
        ];

        $before = $protected();

        Faq::query()->where('managed_key', 'r5.global.password')
            ->firstOrFail()->forceFill(['answer' => 'Owner 改。'])->saveQuietly();

        $this->assertSame(1, Artisan::call('m2c:apply-r5-faq'));
        $this->assertSame($before, $protected());
    }

    // ------------------------------------------------------------------
    // 3. snapshot 只含本輪 FAQ,不含 orders／PII／credential
    // ------------------------------------------------------------------

    public function test_the_snapshot_is_faq_only_and_carries_no_orders_or_credentials(): void
    {
        $path = $this->apply();
        $snapshot = json_decode((string) file_get_contents($path), true);

        $this->assertSame(
            ['schema_version', 'created_at', 'fixture', 'fixture_sha256', 'expected', 'managed_faqs_before', 'created_faq_keys'],
            array_keys($snapshot),
        );

        // expected/created 只含本輪允許的 key(r5.* 或同題 R3 key)。
        $allowed = fn (string $key) => str_starts_with($key, 'r5.') || in_array($key, self::REUSED_R3_KEYS, true);

        foreach (array_keys($snapshot['expected']) as $key) {
            $this->assertTrue($allowed($key), "snapshot.expected 含未預期 key:{$key}");
        }

        foreach ($snapshot['created_faq_keys'] as $key) {
            $this->assertTrue($allowed($key), "created_faq_keys 含未預期 key:{$key}");
        }

        $this->assertCount(22, $snapshot['expected']);
        // 未先跑 R3 時 3 個同題 R3 key 尚不存在 → 全部視為新建。
        $this->assertCount(22, $snapshot['created_faq_keys']);
        $this->assertSame([], $snapshot['managed_faqs_before']);

        /*
         * ⛔ 快照不得帶其他內容表或交易資料。逐鍵檢查:每列只有 FAQ 欄位,
         * 沒有 orders／PII／credential 欄位名稱。
         */
        foreach ($snapshot['expected'] as $row) {
            $this->assertSame(
                ['scope', 'platform_slug', 'product_slug', 'question', 'answer', 'status', 'sort_order'],
                array_keys($row),
            );
        }

        $keysInFile = [];
        array_walk_recursive($snapshot, function ($value, $key) use (&$keysInFile) {
            $keysInFile[] = (string) $key;
        });

        foreach (['customer_email', 'customer_phone', 'target', 'order_number', 'amount', 'provider_service_id', 'api_key', 'unit_price', 'sku'] as $forbidden) {
            $this->assertNotContains($forbidden, $keysInFile);
        }
    }

    // ------------------------------------------------------------------
    // 4. rollback:逐欄還原 + exact 刪除 + Owner 衝突 fail closed
    // ------------------------------------------------------------------

    public function test_rollback_removes_only_r5_rows_and_leaves_everything_else_untouched(): void
    {
        Artisan::call('m2c:apply-r3');

        $ownerFaq = Faq::query()->create([
            'scope' => 'global', 'question' => 'Owner 保留題？', 'answer' => 'Owner 保留答案。',
            'status' => 'published', 'sort_order' => 98,
        ]);

        // 同題 3 列會被 R5 改寫再由 rollback 還原,另行斷言;其餘必須全程不動。
        $others = fn () => Faq::query()
            ->where(fn ($q) => $q->whereNull('managed_key')->orWhere('managed_key', 'like', 'r3.%'))
            ->whereNotIn('managed_key', self::REUSED_R3_KEYS)
            ->orderBy('id')->get()->map->only(['id', 'question', 'answer', 'status', 'sort_order', 'managed_key'])->toArray();

        $variants = ServiceVariant::query()->orderBy('id')->get()->map->only(['id', 'sku', 'unit_price', 'min_quantity', 'status'])->toArray();
        $services = Service::query()->orderBy('id')->get()->map->only(['id', 'product_slug', 'seo_title', 'h1'])->toArray();
        $platforms = Platform::query()->orderBy('id')->get()->map->only(['id', 'slug', 'seo_title', 'h1'])->toArray();

        $before = $others();
        $snapshot = $this->apply();

        $this->assertSame(0, Artisan::call('m2c:apply-r5-faq', ['--rollback' => $snapshot]));

        /*
         * 本輪新建的 r5.* 列全數刪除;同題沿用的 3 個 R3 key 屬於
         * 「快照前已存在」→ 逐欄還原成 R3 原值,不會被刪除。
         */
        $this->assertSame(0, Faq::withTrashed()->where('managed_key', 'like', 'r5.%')->count());
        $this->assertSame(3, Faq::query()->whereIn('managed_key', self::REUSED_R3_KEYS)->count());

        // R3 managed 與 Owner 自建列逐欄不變。
        $this->assertSame($before, $others());
        $this->assertDatabaseHas('faqs', ['id' => $ownerFaq->id, 'answer' => 'Owner 保留答案。']);

        // 價格／SKU／款式／頁面欄位不變。
        $this->assertSame($variants, ServiceVariant::query()->orderBy('id')->get()->map->only(['id', 'sku', 'unit_price', 'min_quantity', 'status'])->toArray());
        $this->assertSame($services, Service::query()->orderBy('id')->get()->map->only(['id', 'product_slug', 'seo_title', 'h1'])->toArray());
        $this->assertSame($platforms, Platform::query()->orderBy('id')->get()->map->only(['id', 'slug', 'seo_title', 'h1'])->toArray());
    }

    public function test_rollback_restores_prior_values_for_rows_that_already_existed(): void
    {
        // 第一次 apply 後改動 fixture 值再 apply,第二份快照的 before 就是第一版值。
        $snapshotOne = $this->apply();
        $this->assertSame(0, Artisan::call('m2c:apply-r5-faq', ['--rollback' => $snapshotOne]));

        // 重新 apply 讓列存在,然後手動改成「已套用的舊值」情境:
        $this->apply();
        $original = Faq::query()->where('managed_key', 'r5.global.payment-methods')->firstOrFail()->only(['question', 'answer', 'status', 'sort_order']);

        // 第二份快照:此時 r5.* 已存在 → before 非空、created 為空。
        $snapshotTwo = $this->apply();
        $plan = json_decode((string) file_get_contents($snapshotTwo), true);

        $this->assertCount(22, $plan['managed_faqs_before']);
        $this->assertSame([], $plan['created_faq_keys']);

        // 改一列,再 rollback → 逐欄還原,列不會被刪掉。
        $row = Faq::query()->where('managed_key', 'r5.global.payment-methods')->firstOrFail();
        $row->forceFill(['answer' => '被改掉的答案。'])->saveQuietly();

        // ⛔ current≠expected → 整批 0 writes。
        $this->assertSame(1, Artisan::call('m2c:apply-r5-faq', ['--rollback' => $snapshotTwo]));
        $this->assertSame('被改掉的答案。', Faq::query()->where('managed_key', 'r5.global.payment-methods')->value('answer'));

        // 還原成 expected 後即可 rollback;列仍在,值為 before。
        $row->forceFill($original)->saveQuietly();
        $this->assertSame(0, Artisan::call('m2c:apply-r5-faq', ['--rollback' => $snapshotTwo]));

        $restored = Faq::query()->where('managed_key', 'r5.global.payment-methods')->firstOrFail();
        $this->assertSame($original['answer'], $restored->answer);
    }

    public function test_rollback_fails_closed_on_owner_edits_and_hostile_paths(): void
    {
        $snapshot = $this->apply();

        // Owner 改了其中一列 → 整批 0 writes,conflict id 不含文案。
        Faq::query()->where('managed_key', 'r5.global.invoice')
            ->first()?->forceFill(['answer' => 'Owner 後來改的答案。'])->saveQuietly();

        $this->assertSame(1, Artisan::call('m2c:apply-r5-faq', ['--rollback' => $snapshot]));
        $output = Artisan::output();

        $this->assertStringContainsString('R5-ROLLBACK-CONFLICT:faq.r5.global.invoice', $output);
        $this->assertStringNotContainsString('Owner 後來改的答案。', $output);
        $this->assertSame(22, $this->managedRowCount());

        // 目錄外路徑一律拒絕。
        foreach ([database_path('fixtures/m2c-r5-faq.json'), storage_path('app/private/m2c-snapshots/../../../composer.json'), 'nope.json'] as $bad) {
            $this->assertSame(1, Artisan::call('m2c:apply-r5-faq', ['--rollback' => $bad]));
        }

        $this->assertSame(22, $this->managedRowCount());
    }

    public function test_rollback_rejects_tampered_snapshots(): void
    {
        $snapshot = $this->apply();
        $good = (string) file_get_contents($snapshot);

        $tampers = [
            // 未知 schema。
            fn (array $s) => tap($s, function (&$s) {
                $s['schema_version'] = 99;
            }),
            // fixture hash 不符。
            fn (array $s) => tap($s, function (&$s) {
                $s['fixture_sha256'] = str_repeat('0', 64);
            }),
            // expected 與現行 fixture 不一致。
            fn (array $s) => tap($s, function (&$s) {
                $s['expected']['r5.global.payment-methods']['answer'] = '偽造的期望值。';
            }),
            // ⛔ 想刪掉不屬於本輪新建的 key。
            fn (array $s) => tap($s, function (&$s) {
                $s['created_faq_keys'][] = 'r3.ig買粉絲.faq.data';
            }),
            // 多一個 top-level key。
            fn (array $s) => tap($s, function (&$s) {
                $s['extra'] = true;
            }),
        ];

        foreach ($tampers as $i => $tamper) {
            file_put_contents($snapshot, json_encode($tamper(json_decode($good, true)), JSON_UNESCAPED_UNICODE));

            $this->assertSame(1, Artisan::call('m2c:apply-r5-faq', ['--rollback' => $snapshot]), "tamper {$i} 應被拒絕");
            $this->assertSame(22, $this->managedRowCount(), "tamper {$i} 不得留下寫入");
        }
    }

    // ------------------------------------------------------------------
    // 5. 唯一 owner 頁分工 + draft gate
    // ------------------------------------------------------------------

    public function test_each_published_answer_appears_only_on_its_single_owner_page(): void
    {
        $this->apply();

        $fixture = $this->fixture();
        $pages = $this->publicPages();
        $featured = $fixture['page']['home_featured_keys'];

        // global:完整 9 題只在 /faq;首頁只允許核准的 3 題。
        foreach ($fixture['faqs']['global'] as $item) {
            if ($item['status'] !== 'published') {
                continue;
            }

            foreach ($pages as $path => $html) {
                $present = str_contains($html, $item['answer']);

                if ($path === '/faq') {
                    $this->assertTrue($present, "{$item['managed_key']} 應完整出現在 /faq");
                } elseif ($path === '/') {
                    $this->assertSame(in_array($item['managed_key'], $featured, true), $present,
                        "首頁只能顯示核准精選:{$item['managed_key']}");
                } else {
                    $this->assertFalse($present, "{$item['managed_key']} 不得重複於 {$path}");
                }
            }
        }

        // platform:只在對應 Hub。
        foreach ($fixture['faqs']['platform'] as $item) {
            $owner = "/services/{$item['platform_slug']}";

            foreach ($pages as $path => $html) {
                $this->assertSame($path === $owner, str_contains($html, $item['answer']),
                    "{$item['managed_key']} owner 應為 {$owner},實際在 {$path} 命中不符");
            }
        }

        // service:只在對應 canonical 商品頁。
        foreach ($fixture['faqs']['service'] as $item) {
            $owner = Service::query()->where('product_slug', $item['product_slug'])->firstOrFail()->primaryUrl();

            foreach ($pages as $path => $html) {
                $this->assertSame($path === $owner, str_contains($html, $item['answer']),
                    "{$item['managed_key']} owner 應為 {$owner},實際在 {$path} 命中不符");
            }
        }
    }

    public function test_the_faq_page_renders_exactly_the_nine_approved_global_questions(): void
    {
        /*
         * ⛔ /faq 承接「9 題 global」是規格數字,不是大約值。3 題與 R3 同題
         * 者以精確 managed key 就地更新,若改成新建平行列,這裡會看到 12 題
         * 同義問答——正是這個斷言要擋掉的退化。
         */
        Artisan::call('m2c:apply-r3');
        $this->apply();

        $rendered = Faq::query()->published()->where('scope', 'global')->orderBy('sort_order')->get();
        $this->assertCount(9, $rendered);

        $expected = collect($this->fixture()['faqs']['global'])
            ->where('status', 'published')->pluck('question')->all();

        $this->assertSame($expected, $rendered->pluck('question')->all());

        // 頁面上每題只出現一次(⛔ 同義重複題會被抓出來)。
        $html = $this->get('/faq')->assertOk()->getContent();

        foreach ($expected as $question) {
            $this->assertSame(1, substr_count($html, e($question)), "「{$question}」在 /faq 應只出現一次");
        }
    }

    public function test_the_draft_order_lookup_answer_is_invisible_to_guests_everywhere(): void
    {
        $this->apply();

        foreach ($this->publicPages() as $path => $html) {
            $this->assertStringNotContainsString(self::DRAFT_ANSWER_FRAGMENT, $html, "draft 不得出現在 {$path}");
            $this->assertStringNotContainsString('下單後如何查詢訂單進度', $html, "draft 問題不得出現在 {$path}");
        }

        // DB 內仍是 draft(⛔ 不得被 apply 成 published)。
        $this->assertDatabaseHas('faqs', ['managed_key' => 'r5.global.order-lookup', 'status' => 'draft']);
    }

    // ------------------------------------------------------------------
    // 6. 初始 HTML:heading + 收合後仍在 DOM
    // ------------------------------------------------------------------

    public function test_questions_are_headings_and_answers_exist_in_the_initial_html(): void
    {
        $this->apply();

        $html = $this->get('/faq')->assertOk()->getContent();
        $fixture = $this->fixture();

        foreach ($fixture['faqs']['global'] as $item) {
            if ($item['status'] !== 'published') {
                continue;
            }

            // 問題以 h3 可讀 heading 呈現。
            $this->assertMatchesRegularExpression(
                '/<h3[^>]*>\s*'.preg_quote(e($item['question']), '/').'\s*<\/h3>/u',
                $html,
            );

            // 答案為文字、存在於伺服器輸出的初始 HTML(accordion 收合仍在 DOM)。
            $this->assertStringContainsString(e($item['answer']), $html);
        }

        // details/summary 沒有 hidden 或 JS-only 取代文字。
        $this->assertStringContainsString('<details', $html);
        $this->assertStringNotContainsString('style="display:none"', $html);
    }

    // ------------------------------------------------------------------
    // 7. /faq metadata、canonical、導航
    // ------------------------------------------------------------------

    public function test_the_faq_page_carries_its_fixed_metadata_and_clean_self_canonical(): void
    {
        $this->apply();

        $html = $this->get('/faq')->assertOk()->getContent();

        $this->assertStringContainsString('<title>'.e(self::FAQ_TITLE).'</title>', $html);
        $this->assertStringContainsString('content="'.e(self::FAQ_DESCRIPTION).'"', $html);
        $this->assertStringContainsString(e(self::FAQ_INTRO), $html);

        // 單一 H1,且逐字為核准值。
        preg_match_all('/<h1[^>]*>(.*?)<\/h1>/su', $html, $h1s);
        $this->assertCount(1, $h1s[1]);
        $this->assertSame(self::FAQ_H1, trim(strip_tags($h1s[1][0])));

        // self-canonical:乾淨 /faq,⛔ 不含 query／fragment。
        preg_match('/<link rel="canonical" href="([^"]+)"/', $html, $canonical);
        $this->assertSame(route('faq'), $canonical[1]);
        $this->assertStringEndsWith('/faq', $canonical[1]);
        $this->assertStringNotContainsString('?', $canonical[1]);
        $this->assertStringNotContainsString('#', $canonical[1]);

        // ⛔ Title/H1 不搶商品頁主要交易詞。
        foreach (['IG 買粉絲', 'IG 買讚'] as $term) {
            $this->assertStringNotContainsString($term, self::FAQ_TITLE);
            $this->assertStringNotContainsString($term, self::FAQ_H1);
        }
    }

    public function test_the_faq_page_links_to_hubs_and_canonical_products_without_copying_their_answers(): void
    {
        $this->apply();

        $html = $this->get('/faq')->assertOk()->getContent();

        // 3 個 Hub 導覽連結。
        foreach (['instagram', 'facebook', 'threads'] as $slug) {
            $this->assertStringContainsString('href="'.route('platform', $slug).'"', $html);
        }

        // 9 個 canonical 商品連結(⛔ 一律 /product/,不得指回 /services/ 商品層)。
        foreach (Service::query()->whereNotNull('product_slug')->get() as $service) {
            $this->assertStringContainsString('href="'.e($service->primaryUrl()).'"', $html);
        }

        preg_match_all('#href="[^"]*/services/[^"/]+/[^"]+"#', $html, $productLevel);
        $this->assertSame([], $productLevel[0]);
    }

    public function test_the_faq_link_appears_in_desktop_and_mobile_navigation_on_every_page(): void
    {
        $this->apply();

        foreach ($this->publicPages() as $path => $html) {
            // desktop nav + mobile:兩個可爬 <a href="/faq">。
            $this->assertGreaterThanOrEqual(
                2,
                substr_count($html, 'href="'.route('faq').'"'),
                "{$path} 應同時有 desktop 與 mobile 的 /faq 連結",
            );

            // ⛔ 主連結不得是 JS-only／query／fragment。
            $this->assertStringNotContainsString('href="#faq-nav"', $html);
        }

        // 目前頁面在 /faq 時標示 active state。
        $faqHtml = $this->get('/faq')->assertOk()->getContent();
        $this->assertStringContainsString('aria-current="page"', $faqHtml);

        // 首頁有「查看全部常見問題」可爬連結。
        $home = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('查看全部常見問題', $home);
    }

    // ------------------------------------------------------------------
    // 8. 13 頁 SEO 不變 + 14 頁唯一性
    // ------------------------------------------------------------------

    public function test_the_existing_thirteen_pages_keep_their_seo_surface_and_all_fourteen_are_unique(): void
    {
        Artisan::call('m2c:apply-r3');

        $capture = function (): array {
            $paths = ['/', '/services/instagram', '/services/facebook', '/services/threads'];

            foreach (Service::query()->whereNotNull('product_slug')->orderBy('id')->get() as $service) {
                $paths[] = $service->primaryUrl();
            }

            $out = [];

            foreach ($paths as $path) {
                $html = $this->get($path)->assertOk()->getContent();
                preg_match('/<title>(.*?)<\/title>/su', $html, $t);
                preg_match('/name="description" content="([^"]*)"/', $html, $d);
                preg_match_all('/<h1[^>]*>(.*?)<\/h1>/su', $html, $h);
                preg_match('/<link rel="canonical" href="([^"]+)"/', $html, $c);
                preg_match_all('#href="([^"]*/product/[^"]*)"#', $html, $links);

                $out[$path] = [
                    'title' => $t[1] ?? '', 'desc' => $d[1] ?? '',
                    'h1' => trim(strip_tags($h[1][0] ?? '')), 'h1_count' => count($h[1]),
                    'canonical' => $c[1] ?? '', 'product_links' => array_values(array_unique($links[1])),
                ];
            }

            return $out;
        };

        $before = $capture();
        $this->apply();
        $after = $capture();

        // ⛔ R5 不得改動原 13 頁的 Title／Description／H1／canonical／商品內鏈目的地。
        $this->assertSame($before, $after);
        $this->assertCount(13, $after);

        // 加上 /faq 共 14 頁:各自單一 H1、Title 唯一、Description 唯一、self-canonical 正確。
        $faqHtml = $this->get('/faq')->assertOk()->getContent();
        preg_match('/<title>(.*?)<\/title>/su', $faqHtml, $t);
        preg_match('/name="description" content="([^"]*)"/', $faqHtml, $d);
        preg_match_all('/<h1[^>]*>(.*?)<\/h1>/su', $faqHtml, $h);
        preg_match('/<link rel="canonical" href="([^"]+)"/', $faqHtml, $c);

        $after['/faq'] = [
            'title' => $t[1], 'desc' => $d[1], 'h1' => trim(strip_tags($h[1][0])),
            'h1_count' => count($h[1]), 'canonical' => $c[1], 'product_links' => [],
        ];

        $this->assertCount(14, $after);
        $this->assertCount(14, array_unique(array_column($after, 'title')));
        $this->assertCount(14, array_unique(array_column($after, 'desc')));
        $this->assertCount(14, array_unique(array_column($after, 'canonical')));

        foreach ($after as $path => $row) {
            $this->assertSame(1, $row['h1_count'], "{$path} 應只有一個 H1");
            $this->assertNotSame('', $row['canonical']);
            $this->assertStringNotContainsString('?', $row['canonical']);
            $this->assertStringNotContainsString('#', $row['canonical']);
        }
    }

    // ------------------------------------------------------------------
    // 9. 禁句 / 內部詞 / 無證據宣稱 / 無 AI markup
    // ------------------------------------------------------------------

    public function test_no_banned_public_wording_internal_terms_or_unevidenced_claims(): void
    {
        /*
         * 依實際上線順序:R3 先移除 seed 期的舊 global FAQ(那 4 題帶有
         * 「後端驗證」「本機 mock」等內部用語),R5 再套用。⛔ /faq 會輸出
         * 所有 published global 列,所以這個順序不是形式問題——測試順序若
         * 與正式順序不同,就驗不到真正上線時的頁面內容。
         */
        Artisan::call('m2c:apply-r3');
        $this->apply();

        $banned = [
            // R4 公開禁句。
            '預覽版本', '本機 MOCK', '尚未開放正式下單', '測試前往付款', 'Mock 訂單結果',
            '建立訂單紀錄', '後端驗證', '付款回呼', '進入處理流程', '不會扣款', '不會建立真實訂單',
            // 內部／供應商用語。
            'SMM', '第三方供應商', 'API派單', 'service ID', '批發成本', 'provider_service_id',
            // 無證據成效／保證。
            '保證觸及', '一定會漲', '不掉粉', '排名保證', '成效保證', '安全保證', '降低觸及率',
        ];

        foreach ($this->publicPages() as $path => $html) {
            foreach ($banned as $term) {
                $this->assertStringNotContainsString($term, $html, "{$path} 不得出現「{$term}」");
            }
        }

        // ⛔ R5 不新增 QAPage／FAQPage／AI 專用 markup。
        $faqHtml = $this->get('/faq')->assertOk()->getContent();

        foreach (['QAPage', 'FAQPage', 'application/ld+json'] as $markup) {
            $this->assertStringNotContainsString($markup, $faqHtml);
        }

        // llms.txt 不存在。
        $this->assertFalse(file_exists(public_path('llms.txt')));
    }

    public function test_r5_touches_only_the_faqs_table(): void
    {
        Artisan::call('m2c:apply-r3');

        $fingerprint = fn () => [
            'services' => DB::table('services')->orderBy('id')->get()->toJson(),
            'platforms' => DB::table('platforms')->orderBy('id')->get()->toJson(),
            'site_settings' => DB::table('site_settings')->orderBy('id')->get()->toJson(),
            'service_variants' => DB::table('service_variants')->orderBy('id')->get()->toJson(),
            'service_content_sections' => DB::table('service_content_sections')->orderBy('id')->get()->toJson(),
            'orders' => DB::table('orders')->orderBy('id')->get()->toJson(),
        ];

        $before = $fingerprint();
        $this->apply();

        $this->assertSame($before, $fingerprint());
    }
}
