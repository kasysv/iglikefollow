<?php

namespace Tests\Feature;

use App\Models\Faq;
use App\Models\Service;
use App\Support\ProductSlugMap;
use Database\Seeders\CatalogSeeder;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
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
 * M2-C R6:FAQ 深度補強。
 *
 * 核心不變式:
 * - 共通付款／發票／下單時間問題只在 `/faq`;粉絲商品專屬 4 題只在各自
 *   canonical 商品 owner,⛔ 不複製到 `/faq`、首頁或 Hub。
 * - R5 的 `/faq` URL／Title／Description／H1／intro／canonical／導航與
 *   首頁三題精選完全不變。
 * - payment FAQ 不得宣稱絕對不會重複扣款,也不得引導已扣款／結果不明的
 *   顧客再次付款。
 * - R6 只碰 fixture 明列的 exact managed key;其他 managed 與 Owner 自建
 *   FAQ 0 讀寫。
 */
class M2cR6FaqDepthTest extends TestCase
{
    use IsolatesSnapshotStorage;
    use RefreshDatabase;
    use SeedsThreadsCatalog;

    /** 唯一允許就地更新的既有 R5 key。 */
    private const UPDATABLE_R5_KEYS = ['r5.global.processing-start'];

    /** 粉絲商品 owner(半年保固政策相同,四個意圖各自加在自己的 canonical 頁)。 */
    private const FOLLOWER_SLUGS = ['ig買粉絲', 'fb買粉絲', 'threads買粉絲'];

    /** R5 首頁三題精選(⛔ R6 不得改變這個清單)。 */
    private const HOME_FEATURED_KEYS = ['r3.global.membership', 'r5.global.password', 'r5.global.processing-start'];

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        // ⛔ 快照寫進拋棄式目錄,不得污染 Owner 的真實還原資產。
        $this->isolateSnapshotStorage();

        $this->seed(CatalogSeeder::class);
        $this->seedThreadsCatalog();
        Artisan::call('m2c:apply-copy');
        Artisan::call('m2c:apply-r3');
        Artisan::call('m2c:apply-r5-faq');
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedSnapshotStorage();

        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function fixture(): array
    {
        return json_decode((string) file_get_contents(database_path('fixtures/m2c-r6-faq-depth.json')), true);
    }

    private function apply(): string
    {
        $this->assertSame(0, Artisan::call('m2c:apply-r6-faq'));
        preg_match('/snapshot=(\S+\.json)/u', Artisan::output(), $m);
        $this->assertNotEmpty($m[1] ?? '');

        return $m[1];
    }

    /** @return list<string> */
    private function managedKeys(): array
    {
        $fixture = $this->fixture();

        return array_merge(
            array_column($fixture['faqs']['global'], 'managed_key'),
            array_column($fixture['faqs']['service'], 'managed_key'),
        );
    }

    private function managedRowCount(): int
    {
        return Faq::withTrashed()->whereIn('managed_key', $this->managedKeys())->count();
    }

    /** @return array<int, mixed> */
    private function managedRowsFingerprint(): array
    {
        return Faq::withTrashed()->whereIn('managed_key', $this->managedKeys())->orderBy('id')
            ->get()
            ->map
            ->only(['managed_key', 'scope', 'platform_id', 'service_id', 'question', 'answer', 'status', 'sort_order', 'deleted_at'])
            ->toArray();
    }

    /** @return array<string, string> */
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

    /** 14 個 indexable 頁的 HTML。 @return array<string, string> */
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
    // 1. fixture schema／count／unique key／published gate／owner scope
    // ------------------------------------------------------------------

    public function test_the_fixture_carries_exact_schema_counts_and_owner_scope(): void
    {
        $fixture = $this->fixture();

        $this->assertSame(['_meta', 'faqs'], array_keys($fixture));
        $this->assertSame(['global', 'service'], array_keys($fixture['faqs']));

        // 1 個更新的 R5 key + 4 個新 global + 4 題 x 3 粉絲商品。
        $this->assertCount(5, $fixture['faqs']['global']);
        $this->assertCount(12, $fixture['faqs']['service']);

        $keys = [];
        $serviceSlugs = array_values(ProductSlugMap::MAP);

        foreach (['global', 'service'] as $scope) {
            foreach ($fixture['faqs'][$scope] as $item) {
                // key 只能是 r6.* 或明列可更新的 R5 key,且全域唯一。
                $this->assertTrue(
                    str_starts_with($item['managed_key'], 'r6.')
                        || in_array($item['managed_key'], self::UPDATABLE_R5_KEYS, true),
                    "未預期的 managed key:{$item['managed_key']}",
                );
                $this->assertNotContains($item['managed_key'], $keys);
                $keys[] = $item['managed_key'];

                $this->assertNotSame('', trim($item['question']));
                $this->assertNotSame('', trim($item['answer']));
                $this->assertIsInt($item['sort_order']);

                // R6 全部 published(⛔ 不引入新的 draft)。
                $this->assertSame('published', $item['status']);

                if ($scope === 'service') {
                    $this->assertContains($item['product_slug'], $serviceSlugs);
                    // 四個意圖只加在粉絲商品 owner。
                    $this->assertContains($item['product_slug'], self::FOLLOWER_SLUGS);
                } else {
                    $this->assertSame(['managed_key', 'question', 'answer', 'status', 'sort_order'], array_keys($item));
                }
            }
        }

        // 每個粉絲商品各 4 題,且四個意圖齊備。
        foreach (self::FOLLOWER_SLUGS as $slug) {
            $rows = array_filter($fixture['faqs']['service'], fn ($i) => $i['product_slug'] === $slug);
            $this->assertCount(4, $rows);

            foreach (['delivery-list', 'warranty', 'private-account', 'rename-or-delete'] as $intent) {
                $this->assertContains("r6.{$slug}.{$intent}", array_column($rows, 'managed_key'));
            }
        }
    }

    /** @return array<string, array{callable}> */
    public static function badFixtureProvider(): array
    {
        return [
            'unknown product slug' => [fn (array $f) => tap($f, function (&$f) {
                $f['faqs']['service'][0]['product_slug'] = 'ig買不存在';
            })],
            'non r6 key' => [fn (array $f) => tap($f, function (&$f) {
                $f['faqs']['global'][1]['managed_key'] = 'r5.global.invoice';
            })],
            'duplicate key' => [fn (array $f) => tap($f, function (&$f) {
                $f['faqs']['global'][1]['managed_key'] = $f['faqs']['global'][0]['managed_key'];
            })],
            'empty answer' => [fn (array $f) => tap($f, function (&$f) {
                $f['faqs']['global'][1]['answer'] = '   ';
            })],
            'extra field' => [fn (array $f) => tap($f, function (&$f) {
                $f['faqs']['global'][1]['extra'] = 'x';
            })],
            'draft status' => [fn (array $f) => tap($f, function (&$f) {
                $f['faqs']['global'][1]['status'] = 'draft';
            })],
        ];
    }

    #[DataProvider('badFixtureProvider')]
    public function test_the_importer_fails_closed_on_invalid_fixtures(callable $mutate): void
    {
        $path = database_path('fixtures/m2c-r6-faq-depth.json');
        $original = (string) file_get_contents($path);

        try {
            file_put_contents($path, json_encode($mutate(json_decode($original, true)), JSON_UNESCAPED_UNICODE));

            $this->assertSame(1, Artisan::call('m2c:apply-r6-faq'));
            $this->assertSame(0, Faq::query()->where('managed_key', 'like', 'r6.%')->count());
        } finally {
            file_put_contents($path, $original);
        }
    }

    // ------------------------------------------------------------------
    // 2. dry-run／apply／idempotency／conflict guard
    // ------------------------------------------------------------------

    public function test_dry_run_writes_nothing_and_leaves_no_snapshot(): void
    {
        $before = $this->managedRowsFingerprint();
        $snapshotsBefore = $this->snapshotFiles();

        $this->assertSame(0, Artisan::call('m2c:apply-r6-faq', ['--dry-run' => true]));
        $this->assertStringContainsString('0 writes', Artisan::output());

        $this->assertSame($before, $this->managedRowsFingerprint());
        $this->assertSame(0, Faq::query()->where('managed_key', 'like', 'r6.%')->count());
        $this->assertSame($snapshotsBefore, $this->snapshotFiles());
    }

    public function test_apply_creates_sixteen_rows_updates_one_and_is_idempotent(): void
    {
        // ⛔ 這裡不走 apply() helper:它已消耗 Artisan::output(),第二次讀為空。
        $this->assertSame(0, Artisan::call('m2c:apply-r6-faq'));
        $first = Artisan::output();

        $this->assertStringContainsString('created=16', $first);
        $this->assertStringContainsString('updated=1', $first);
        $this->assertSame(17, $this->managedRowCount());

        $rows = $this->managedRowsFingerprint();

        $this->assertSame(0, Artisan::call('m2c:apply-r6-faq'));
        $second = Artisan::output();

        $this->assertStringContainsString('created=0', $second);
        $this->assertStringContainsString('updated=0', $second);
        $this->assertStringContainsString('unchanged=17', $second);
        $this->assertSame($rows, $this->managedRowsFingerprint());
    }

    public function test_apply_never_touches_other_managed_or_owner_authored_faqs(): void
    {
        $ownerFaq = Faq::query()->create([
            'scope' => 'global', 'question' => 'Owner 自己加的？', 'answer' => 'Owner 自己寫的。',
            'status' => 'published', 'sort_order' => 99,
        ]);

        /*
         * ⛔ 除了 fixture 明列的 key,其餘 R3／R5 managed 與 Owner 自建列
         * 都必須逐欄不動。
         */
        // deleted_at 轉字串比對:Carbon 物件實例每次查詢都不同,只有值才有意義。
        $keys = $this->managedKeys();
        $others = fn () => Faq::withTrashed()
            ->where(fn ($q) => $q->whereNull('managed_key')->orWhereNotIn('managed_key', $keys))
            ->orderBy('id')
            ->get()
            ->map(fn (Faq $faq) => [
                'id' => $faq->id,
                'question' => $faq->question,
                'answer' => $faq->answer,
                'status' => $faq->status,
                'sort_order' => $faq->sort_order,
                'managed_key' => $faq->managed_key,
                'deleted_at' => (string) $faq->deleted_at,
            ])->toArray();

        $before = $others();

        $this->apply();

        $this->assertSame($before, $others());
        $this->assertDatabaseHas('faqs', ['id' => $ownerFaq->id, 'answer' => 'Owner 自己寫的。']);
    }

    /** @return array<string, array{string, array<string, mixed>}> */
    public static function ownerEditProvider(): array
    {
        return [
            'updatable r5 key: answer' => ['r5.global.processing-start', ['answer' => 'Owner 改寫的答案。']],
            'updatable r5 key: status' => ['r5.global.processing-start', ['status' => 'draft']],
            'updatable r5 key: sort_order' => ['r5.global.processing-start', ['sort_order' => 77]],
        ];
    }

    /** @param array<string, mixed> $edit */
    #[DataProvider('ownerEditProvider')]
    public function test_apply_fails_closed_when_the_updatable_row_was_edited_by_the_owner(string $key, array $edit): void
    {
        $snapshotsBefore = $this->snapshotFiles();

        Faq::query()->where('managed_key', $key)->firstOrFail()->forceFill($edit)->saveQuietly();
        $drifted = $this->managedRowsFingerprint();

        $this->assertSame(1, Artisan::call('m2c:apply-r6-faq'));
        $output = Artisan::output();

        $this->assertStringContainsString('R6-APPLY-CONFLICT:faq.'.$key, $output);

        foreach ($edit as $value) {
            if (is_string($value)) {
                $this->assertStringNotContainsString($value, $output);
            }
        }

        // 0 writes、0 新快照。
        $this->assertSame($drifted, $this->managedRowsFingerprint());
        $this->assertSame(0, Faq::query()->where('managed_key', 'like', 'r6.%')->count());
        $this->assertSame($snapshotsBefore, $this->snapshotFiles());
    }

    public function test_apply_fails_closed_when_a_created_row_was_soft_deleted(): void
    {
        $this->apply();

        $snapshotsBefore = $this->snapshotFiles();
        Faq::query()->where('managed_key', 'r6.global.always-open')->firstOrFail()->delete();

        $this->assertSame(1, Artisan::call('m2c:apply-r6-faq'));
        $this->assertStringContainsString('R6-APPLY-CONFLICT:faq.r6.global.always-open', Artisan::output());

        // ⛔ 不得靜默復活覆寫。
        $this->assertSoftDeleted('faqs', ['managed_key' => 'r6.global.always-open']);
        $this->assertSame($snapshotsBefore, $this->snapshotFiles());
    }

    public function test_real_apply_locks_managed_rows_before_any_write(): void
    {
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

        $this->apply();

        $this->assertGreaterThan(0, $started);
        $this->assertGreaterThan(0, $committed);

        $lockIndex = null;
        $firstWriteIndex = null;

        foreach ($log as $i => $sql) {
            if ($lockIndex === null
                && str_contains($sql, 'from "faqs"')
                && str_contains($sql, '"managed_key" in')
                && str_contains($sql, 'order by "managed_key"')) {
                $lockIndex = $i;
            }

            if ($firstWriteIndex === null
                && (str_starts_with($sql, 'insert into "faqs"') || str_starts_with($sql, 'update "faqs"'))) {
                $firstWriteIndex = $i;
            }
        }

        $this->assertNotNull($lockIndex, '找不到受控列的鎖定查詢');
        $this->assertNotNull($firstWriteIndex, '本輪應有 FAQ 寫入');
        $this->assertLessThan($firstWriteIndex, $lockIndex, '鎖定必須發生在任何 FAQ 寫入之前');
    }

    // ------------------------------------------------------------------
    // 3. snapshot 與 rollback
    // ------------------------------------------------------------------

    public function test_the_snapshot_is_faq_only_and_uses_its_own_naming(): void
    {
        $path = $this->apply();

        // ⛔ 獨立命名,不與 R3／R4／R5 快照混淆。
        $this->assertStringStartsWith('r6-faq-depth-', basename($path));

        $snapshot = json_decode((string) file_get_contents($path), true);

        $this->assertSame(
            ['schema_version', 'created_at', 'fixture', 'fixture_sha256', 'expected', 'managed_faqs_before', 'created_faq_keys'],
            array_keys($snapshot),
        );
        $this->assertSame('database/fixtures/m2c-r6-faq-depth.json', $snapshot['fixture']);

        // before 只有 1 列(可更新的 R5 key),created 16 個。
        $this->assertCount(1, $snapshot['managed_faqs_before']);
        $this->assertSame('r5.global.processing-start', $snapshot['managed_faqs_before'][0]['managed_key']);
        $this->assertCount(16, $snapshot['created_faq_keys']);

        // 不含 orders／PII／credential 欄位。
        $keysInFile = [];
        array_walk_recursive($snapshot, function ($value, $key) use (&$keysInFile) {
            $keysInFile[] = (string) $key;
        });

        foreach (['customer_email', 'customer_phone', 'order_number', 'amount', 'provider_service_id', 'api_key', 'unit_price', 'sku'] as $forbidden) {
            $this->assertNotContains($forbidden, $keysInFile);
        }
    }

    public function test_snapshot_before_state_records_the_pre_overwrite_r5_value(): void
    {
        $before = Faq::query()->where('managed_key', 'r5.global.processing-start')
            ->firstOrFail()->only(['question', 'answer', 'status', 'sort_order']);

        $snapshot = json_decode((string) file_get_contents($this->apply()), true);
        $recorded = $snapshot['managed_faqs_before'][0];

        // 快照記錄的是「被覆寫前」的 R5 值。
        $this->assertSame($before['question'], $recorded['question']);
        $this->assertSame($before['answer'], $recorded['answer']);

        // apply 後 DB 已是 R6 值。
        $this->assertNotSame($before['answer'], Faq::query()->where('managed_key', 'r5.global.processing-start')->value('answer'));
    }

    public function test_rollback_restores_the_r5_row_and_deletes_only_this_rounds_keys(): void
    {
        $ownerFaq = Faq::query()->create([
            'scope' => 'global', 'question' => 'Owner 保留題？', 'answer' => 'Owner 保留答案。',
            'status' => 'published', 'sort_order' => 98,
        ]);

        $r5Before = Faq::query()->where('managed_key', 'r5.global.processing-start')
            ->firstOrFail()->only(['question', 'answer', 'status', 'sort_order']);

        $keys = $this->managedKeys();
        $others = fn () => Faq::withTrashed()
            ->where(fn ($q) => $q->whereNull('managed_key')->orWhereNotIn('managed_key', $keys))
            ->orderBy('id')->get()->map->only(['id', 'question', 'answer', 'status', 'sort_order', 'managed_key'])->toArray();

        $protectedBefore = [
            'services' => DB::table('services')->orderBy('id')->get()->toJson(),
            'service_variants' => DB::table('service_variants')->orderBy('id')->get()->toJson(),
            'orders' => DB::table('orders')->orderBy('id')->get()->toJson(),
        ];
        $othersBefore = $others();

        $snapshot = $this->apply();

        $this->assertSame(0, Artisan::call('m2c:apply-r6-faq', ['--rollback' => $snapshot]));

        // 本輪新建的 16 列全數刪除。
        $this->assertSame(0, Faq::withTrashed()->where('managed_key', 'like', 'r6.%')->count());

        // 被更新的 R5 列逐欄還原。
        $this->assertSame($r5Before, Faq::query()->where('managed_key', 'r5.global.processing-start')
            ->firstOrFail()->only(['question', 'answer', 'status', 'sort_order']));

        // R5 accepted FAQ、Owner 自建 FAQ 與受保護表全部不變。
        $this->assertSame($othersBefore, $others());
        $this->assertDatabaseHas('faqs', ['id' => $ownerFaq->id, 'answer' => 'Owner 保留答案。']);

        foreach ($protectedBefore as $table => $json) {
            $this->assertSame($json, DB::table($table)->orderBy('id')->get()->toJson(), $table);
        }
    }

    public function test_rollback_fails_closed_on_owner_edits_and_hostile_paths(): void
    {
        $snapshot = $this->apply();

        Faq::query()->where('managed_key', 'r6.global.invoice-conversion')
            ->firstOrFail()->forceFill(['answer' => 'Owner 後來改的。'])->saveQuietly();

        $this->assertSame(1, Artisan::call('m2c:apply-r6-faq', ['--rollback' => $snapshot]));
        $output = Artisan::output();

        $this->assertStringContainsString('R6-ROLLBACK-CONFLICT:faq.r6.global.invoice-conversion', $output);
        $this->assertStringNotContainsString('Owner 後來改的。', $output);
        $this->assertSame(17, $this->managedRowCount());

        foreach ([database_path('fixtures/m2c-r6-faq-depth.json'), $this->snapshotDirectory().'/../../../composer.json', 'nope.json'] as $bad) {
            $this->assertSame(1, Artisan::call('m2c:apply-r6-faq', ['--rollback' => $bad]));
        }
    }

    public function test_rollback_rejects_tampered_snapshots(): void
    {
        $snapshot = $this->apply();
        $good = (string) file_get_contents($snapshot);

        $tampers = [
            fn (array $s) => tap($s, function (&$s) {
                $s['schema_version'] = 99;
            }),
            fn (array $s) => tap($s, function (&$s) {
                $s['fixture_sha256'] = str_repeat('0', 64);
            }),
            fn (array $s) => tap($s, function (&$s) {
                $s['expected']['r6.global.always-open']['answer'] = '偽造的期望值。';
            }),
            // ⛔ 想刪掉不屬於本輪新建的 key。
            fn (array $s) => tap($s, function (&$s) {
                $s['created_faq_keys'][] = 'r5.global.invoice';
            }),
            fn (array $s) => tap($s, function (&$s) {
                $s['extra'] = true;
            }),
        ];

        foreach ($tampers as $i => $tamper) {
            file_put_contents($snapshot, json_encode($tamper(json_decode($good, true)), JSON_UNESCAPED_UNICODE));

            $this->assertSame(1, Artisan::call('m2c:apply-r6-faq', ['--rollback' => $snapshot]), "tamper {$i} 應被拒絕");
            $this->assertSame(17, $this->managedRowCount(), "tamper {$i} 不得留下寫入");
        }
    }

    // ------------------------------------------------------------------
    // 4. owner 分工與 R5 表面不變
    // ------------------------------------------------------------------

    public function test_each_new_question_appears_only_on_its_designated_owner(): void
    {
        $this->apply();

        $fixture = $this->fixture();
        $pages = $this->publicPages();

        // global:只在 /faq;唯一例外是本來就在首頁精選的 processing-start。
        foreach ($fixture['faqs']['global'] as $item) {
            $isFeatured = in_array($item['managed_key'], self::HOME_FEATURED_KEYS, true);

            foreach ($pages as $path => $html) {
                $present = str_contains($html, $item['question']);
                $should = $path === '/faq' || ($isFeatured && $path === '/');

                $this->assertSame($should, $present, "{$item['managed_key']} 在 {$path} 的出現狀態不符");
            }
        }

        /*
         * service:每題 question 只在自己的 canonical owner。
         * ⛔ 用 question 而非 answer 判斷——三個粉絲商品共用 Owner 核准的
         * 同一段答案文字(見結果文件的重複內容說明),只有問句帶平台名。
         */
        foreach ($fixture['faqs']['service'] as $item) {
            $owner = Service::query()->where('product_slug', $item['product_slug'])->firstOrFail()->primaryUrl();

            foreach ($pages as $path => $html) {
                $this->assertSame($path === $owner, str_contains($html, $item['question']),
                    "{$item['managed_key']} owner 應為 {$owner}");
            }
        }

        // ⛔ /faq 不得完整複製粉絲商品問答。
        foreach ($fixture['faqs']['service'] as $item) {
            $this->assertStringNotContainsString($item['question'], $pages['/faq']);
        }
    }

    public function test_the_home_featured_three_are_unchanged(): void
    {
        $beforeCount = substr_count($this->get('/')->assertOk()->getContent(), '<details');

        $this->apply();

        $home = $this->get('/')->assertOk()->getContent();

        // 首頁仍然只有三題精選。
        $this->assertSame(3, $beforeCount);
        $this->assertSame(3, substr_count($home, '<details'));

        foreach (self::HOME_FEATURED_KEYS as $key) {
            $question = Faq::query()->where('managed_key', $key)->value('question');
            $this->assertStringContainsString($question, $home);
        }

        // R6 新題一律不得出現在首頁。
        foreach ($this->fixture()['faqs']['global'] as $item) {
            if (in_array($item['managed_key'], self::HOME_FEATURED_KEYS, true)) {
                continue;
            }

            $this->assertStringNotContainsString($item['question'], $home);
        }

        $this->assertStringContainsString('查看全部常見問題', $home);
    }

    public function test_the_r5_page_surface_does_not_regress(): void
    {
        $capture = function (): array {
            $out = [];

            foreach ($this->publicPages() as $path => $html) {
                preg_match('/<title>(.*?)<\/title>/su', $html, $t);
                preg_match('/name="description" content="([^"]*)"/', $html, $d);
                preg_match_all('/<h1[^>]*>(.*?)<\/h1>/su', $html, $h);
                preg_match('/<link rel="canonical" href="([^"]+)"/', $html, $c);

                $out[$path] = [
                    'title' => $t[1] ?? '', 'desc' => $d[1] ?? '',
                    'h1' => trim(strip_tags($h[1][0] ?? '')), 'h1_count' => count($h[1]),
                    'canonical' => $c[1] ?? '',
                    'faq_nav' => substr_count($html, 'href="'.route('faq').'"'),
                ];
            }

            return $out;
        };

        $before = $capture();
        $this->apply();
        $after = $capture();

        // ⛔ R6 不得改動 Title／Description／H1／canonical／導航。
        $this->assertSame($before, $after);
        $this->assertCount(14, $after);

        foreach ($after as $path => $row) {
            $this->assertSame(1, $row['h1_count'], $path);
            $this->assertGreaterThanOrEqual(2, $row['faq_nav'], "{$path} 導航應保留 desktop+mobile 的 /faq 連結");
        }

        $this->assertCount(14, array_unique(array_column($after, 'title')));
        $this->assertCount(14, array_unique(array_column($after, 'desc')));
        $this->assertCount(14, array_unique(array_column($after, 'canonical')));
    }

    public function test_answers_are_present_as_text_in_the_initial_html(): void
    {
        $this->apply();

        $faqPage = $this->get('/faq')->assertOk()->getContent();

        foreach ($this->fixture()['faqs']['global'] as $item) {
            $this->assertMatchesRegularExpression(
                '/<h3[^>]*>\s*'.preg_quote(e($item['question']), '/').'\s*<\/h3>/u',
                $faqPage,
            );
            $this->assertStringContainsString(e($item['answer']), $faqPage);
        }

        foreach ($this->fixture()['faqs']['service'] as $item) {
            $owner = Service::query()->where('product_slug', $item['product_slug'])->firstOrFail()->primaryUrl();
            $html = $this->get($owner)->assertOk()->getContent();

            $this->assertStringContainsString(e($item['question']), $html);
            $this->assertStringContainsString(e($item['answer']), $html);
        }
    }

    // ------------------------------------------------------------------
    // 5. 禁詞與 payment 語意
    // ------------------------------------------------------------------

    public function test_no_banned_or_internal_wording_on_public_pages(): void
    {
        $this->apply();

        $banned = [
            'SMM', '第三方供應商', '供應商', 'API派單', 'service ID', '批發成本', 'provider_service_id',
            'MOCK', '本機 MOCK', '預覽版本', 'preview=1', 'checkout_token', 'reconciliation',
            '狀態機', '付款回呼', '後端驗證',
            '保證觸及', '不掉粉', '成效保證', '安全保證', '降低觸及率',
        ];

        foreach ($this->publicPages() as $path => $html) {
            foreach ($banned as $term) {
                $this->assertStringNotContainsString($term, $html, "{$path} 不得出現「{$term}」");
            }
        }

        // ⛔ 仍不新增 FAQPage／QAPage／llms.txt。
        $faqPage = $this->get('/faq')->assertOk()->getContent();

        foreach (['QAPage', 'FAQPage', 'application/ld+json'] as $markup) {
            $this->assertStringNotContainsString($markup, $faqPage);
        }

        $this->assertFalse(file_exists(public_path('llms.txt')));
    }

    public function test_payment_answers_never_promise_no_double_charge_or_invite_repayment(): void
    {
        $this->apply();

        $fixture = $this->fixture();
        $byKey = collect($fixture['faqs']['global'])->keyBy('managed_key');

        $duplicate = $byKey['r6.global.duplicate-payment']['answer'];
        $failed = $byKey['r6.global.payment-failed']['answer'];

        // ⛔ 不得宣稱絕對不會重複扣款。
        foreach (['絕對不會', '不可能重複', '保證不會', '絕不會', '一定不會'] as $phrase) {
            $this->assertStringNotContainsString($phrase, $duplicate);
        }

        // 必須誠實承認另建新訂單仍可能形成兩筆交易。
        $this->assertStringContainsString('仍可能形成兩筆交易', $duplicate);

        // ⛔ 不得引導已扣款／結果不明的顧客再次付款。
        $this->assertStringContainsString('請停止再次付款', $duplicate);
        $this->assertStringContainsString('請先不要再次付款', $failed);
        $this->assertStringContainsString('付款頁明確顯示失敗或取消時，可以重新付款', $failed);

        // ⛔ 不得洩漏付款實作細節。
        foreach (['checkout_token', 'pending', 'reconciliation', '狀態機', '欄位'] as $term) {
            $this->assertStringNotContainsString($term, $duplicate);
            $this->assertStringNotContainsString($term, $failed);
        }

        // 24 小時只描述「接受下單」,客服為依序回覆(⛔ 不得寫成即時在線)。
        $alwaysOpen = $byKey['r6.global.always-open']['answer'];
        $this->assertStringContainsString('依收到順序回覆', $alwaysOpen);

        foreach (['即時在線', '隨時回覆', '客服24小時', '24小時客服'] as $phrase) {
            $this->assertStringNotContainsString($phrase, $alwaysOpen);
        }
    }

    public function test_warranty_answers_state_the_documented_limits(): void
    {
        $this->apply();

        foreach (self::FOLLOWER_SLUGS as $slug) {
            $html = $this->get(Service::query()->where('product_slug', $slug)->firstOrFail()->primaryUrl())
                ->assertOk()->getContent();

            // 半年保固與其排除條件必須同時出現(⛔ 不得只寫好處)。
            $this->assertStringContainsString('半年掉粉補量保固', $html, $slug);
            $this->assertStringContainsString('不在保固範圍', $html, $slug);
            $this->assertStringContainsString('私密帳戶不提供保固', $html, $slug);

            // 「有名單」不等於保固。
            $this->assertStringContainsString('保固仍依方案的半年保固條件處理', $html, $slug);
        }
    }

    public function test_r6_touches_only_the_faqs_table(): void
    {
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
