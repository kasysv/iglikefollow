<?php

namespace App\Console\Commands;

use App\Models\Faq;
use App\Models\Service;
use App\Support\ProductSlugMap;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * M2-C R6:FAQ 深度補強(唯一來源=repo 內 R6 fixture)。
 *
 * ⛔ 只碰 `faqs` 表,且只碰 fixture 明列的 exact managed key:
 * 1 個核准更新的 R5 key(`r5.global.processing-start`)與新建的 `r6.*` key。
 * Owner 自建 FAQ(managed_key IS NULL)、其他 R3／R5 managed rows 0 讀寫;
 * 價格/SKU/款式/mapping/orders/payments/invoices/credential 一律不碰,
 * 其他內容表也不碰(那些屬於 R3/R4/R5 的 snapshot 責任範圍)。
 *
 * 安全順序沿用 R5-R2 封閉版:同一 transaction 內
 * lock → guard → snapshot → apply。
 * - 依 managed key 穩定排序對受控列(含 soft-deleted)取得 lockForUpdate;
 * - 在鎖內逐欄驗證:既有列只能是 R5 accepted baseline 或已等於 R6 expected;
 * - snapshot before-state 由同一批 locked rows 組出,⛔ 不重新查詢;
 * - apply 只使用同一批 locked rows,⛔ 快照後不存在未鎖定的 save 窗口。
 */
class M2cApplyR6FaqDepthCommand extends Command
{
    protected $signature = 'm2c:apply-r6-faq {--dry-run : 驗證並試算,不寫入} {--rollback= : 從指定快照檔還原本輪 FAQ}';

    protected $description = 'M2-C R6:套用 FAQ 深度補強(transactional;支援 dry-run 與 FAQ-only 快照 rollback)';

    private const SNAPSHOT_SCHEMA_VERSION = 1;

    private const FIXTURE = 'database/fixtures/m2c-r6-faq-depth.json';

    private const FAQ_ROW_FIELDS = ['id', 'scope', 'platform_id', 'service_id', 'question', 'answer', 'status', 'sort_order', 'managed_key'];

    private const GLOBAL_ITEM_FIELDS = ['managed_key', 'question', 'answer', 'status', 'sort_order'];

    private const SERVICE_ITEM_FIELDS = ['managed_key', 'product_slug', 'question', 'answer', 'status', 'sort_order'];

    /**
     * 唯一允許就地更新的既有 R5 key(任務書精確列出)。
     *
     * ⛔ 這是能碰非 `r6.*` key 的唯一通道,且為寫死的精確清單:其他 R5／R3
     * key 與 Owner 自建列都不在範圍內。
     */
    private const UPDATABLE_R5_KEYS = ['r5.global.processing-start'];

    private const SNAPSHOT_TOP_KEYS = ['schema_version', 'created_at', 'fixture', 'fixture_sha256', 'expected', 'managed_faqs_before', 'created_faq_keys'];

    public function handle(): int
    {
        try {
            if (($snapshot = (string) $this->option('rollback')) !== '') {
                return $this->rollbackFromSnapshot($snapshot);
            }

            $fixture = $this->loadFixture();
            $dry = (bool) $this->option('dry-run');

            $stats = ['created' => 0, 'updated' => 0, 'unchanged' => 0];
            $snapshotPath = null;

            /*
             * Preflight:未鎖定的快速拒絕。⛔ 這不是安全邊界——真正的保證
             * 來自下方 transaction 內的 lock → guard → snapshot → apply。
             */
            $this->assertNoApplyConflicts($fixture);

            try {
                DB::transaction(function () use ($fixture, $dry, &$stats, &$snapshotPath): void {
                    $locked = $this->lockManagedRows($fixture);

                    $this->assertNoApplyConflicts($fixture, $locked);

                    $snapshot = $this->buildSnapshot($fixture, $locked);

                    $this->applyFaqs($fixture, $locked, $stats);

                    if ($dry) {
                        // ⛔ dry-run 一律不產生快照檔。
                        throw new RuntimeException('__R6_DRY_RUN_ROLLBACK__');
                    }

                    // 寫檔為 commit 前最後一步:apply 失敗就不會留下孤兒快照。
                    $snapshotPath = $this->writeSnapshotFile($snapshot);
                });
            } catch (RuntimeException $e) {
                if ($e->getMessage() !== '__R6_DRY_RUN_ROLLBACK__') {
                    $this->discardSnapshot($snapshotPath);
                    $snapshotPath = null;

                    throw $e;
                }
            }

            $this->info(sprintf(
                '%s faq created=%d updated=%d unchanged=%d%s',
                $dry ? '[dry-run,已 rollback,0 writes]' : '[applied]',
                $stats['created'], $stats['updated'], $stats['unchanged'],
                $snapshotPath !== null ? ';snapshot='.$snapshotPath : '',
            ));

            return self::SUCCESS;
        } catch (RuntimeException $e) {
            $this->error('R6 失敗,未留下部分寫入:'.$e->getMessage());

            return self::FAILURE;
        }
    }

    // ------------------------------------------------------------------
    // Fixture(fail closed)
    // ------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function loadFixture(): array
    {
        $path = database_path('fixtures/m2c-r6-faq-depth.json');
        $fixture = json_decode((string) file_get_contents($path), true);

        if (! is_array($fixture)) {
            throw new RuntimeException('R6 fixture 缺失或無法解析。');
        }

        $this->exactKeys(array_keys($fixture), ['_meta', 'faqs'], 'fixture top-level');
        $this->exactKeys(array_keys((array) $fixture['faqs']), ['global', 'service'], 'fixture.faqs');

        $serviceSlugs = array_values(ProductSlugMap::MAP);
        $keys = [];

        foreach ($fixture['faqs']['global'] as $i => $item) {
            $this->validateItem($item, self::GLOBAL_ITEM_FIELDS, "faqs.global[{$i}]", $keys);
        }

        foreach ($fixture['faqs']['service'] as $i => $item) {
            $this->validateItem($item, self::SERVICE_ITEM_FIELDS, "faqs.service[{$i}]", $keys);

            if (! in_array($item['product_slug'], $serviceSlugs, true)) {
                throw new RuntimeException("faqs.service[{$i}] 指向未知 product slug。");
            }
        }

        return $fixture;
    }

    /**
     * @param  list<string>  $fields
     * @param  list<string>  $keys
     */
    private function validateItem(mixed $item, array $fields, string $where, array &$keys): void
    {
        if (! is_array($item)) {
            throw new RuntimeException("{$where} 不是物件。");
        }

        $this->exactKeys(array_keys($item), $fields, $where);

        foreach (['managed_key', 'question', 'answer'] as $field) {
            $this->assertFilledString($item[$field] ?? null, "{$where}.{$field}");
        }

        /*
         * ⛔ 只准 `r6.*` 新 key,或明列的可更新 R5 key。其他 R5／R3 key、
         * Owner 自建列一律拒絕。
         */
        if (! str_starts_with($item['managed_key'], 'r6.')
            && ! in_array($item['managed_key'], self::UPDATABLE_R5_KEYS, true)) {
            throw new RuntimeException("{$where}.managed_key 不在允許範圍(r6.* 或明列的可更新 R5 key)。");
        }

        if (in_array($item['managed_key'], $keys, true)) {
            throw new RuntimeException("{$where}.managed_key 重複。");
        }

        $keys[] = $item['managed_key'];

        // R6 全部為 published;draft gate 由 R5 的訂單查詢題各自維持。
        if ($item['status'] !== 'published') {
            throw new RuntimeException("{$where}.status 只允許 published。");
        }

        if (! is_int($item['sort_order'])) {
            throw new RuntimeException("{$where}.sort_order 非整數。");
        }
    }

    private function assertFilledString(mixed $value, string $where): void
    {
        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException("{$where} 非有效字串。");
        }
    }

    // ------------------------------------------------------------------
    // Lock → guard → snapshot → apply
    // ------------------------------------------------------------------

    /**
     * transaction 內對已存在的受控列取得 row lock。
     *
     * @param  array<string, mixed>  $fixture
     * @return array<string, Faq>
     */
    private function lockManagedRows(array $fixture): array
    {
        $keys = array_keys($this->expectedFromFixture($fixture));
        sort($keys);

        return Faq::withTrashed()
            ->whereIn('managed_key', $keys)
            ->orderBy('managed_key')
            ->lockForUpdate()
            ->get()
            ->keyBy('managed_key')
            ->all();
    }

    /**
     * Owner conflict guard。
     *
     * 合法的既有狀態只有三種:
     * 1. row 不存在(本輪新建);
     * 2. row 逐欄已等於 R6 expected(冪等重跑);
     * 3. `UPDATABLE_R5_KEYS` 逐欄等於「未被修改的 R5 accepted baseline」。
     *
     * ⛔ 其他任何狀態(欄位被後台改過、列被 soft delete)都以固定
     * `R6-APPLY-CONFLICT:faq.<key>` 拒絕整批;無 `--force` 可繞過,
     * 錯誤訊息只帶 key,不帶文案。
     *
     * @param  array<string, mixed>  $fixture
     * @param  array<string, Faq>|null  $locked
     */
    private function assertNoApplyConflicts(array $fixture, ?array $locked = null): void
    {
        $expectedAll = $this->expectedFromFixture($fixture);
        $r5Baseline = $this->updatableR5Baseline();
        $conflicts = [];

        foreach ($expectedAll as $key => $expected) {
            $row = $locked !== null
                ? ($locked[$key] ?? null)
                : Faq::withTrashed()->where('managed_key', $key)->first();

            if ($row === null) {
                continue;
            }

            if ($row->deleted_at !== null) {
                $conflicts[] = $key;

                continue;
            }

            if ($this->rowMatches($row, $expected)) {
                continue;
            }

            if (isset($r5Baseline[$key]) && $this->rowMatches($row, $r5Baseline[$key])) {
                continue;
            }

            $conflicts[] = $key;
        }

        if ($conflicts !== []) {
            sort($conflicts);

            throw new RuntimeException('R6-APPLY-CONFLICT:'.implode(',', array_map(
                static fn (string $key) => 'faq.'.$key,
                $conflicts,
            )));
        }
    }

    /**
     * 可更新 R5 key 的「未被修改」基準值,取自現行 R5 fixture。
     *
     * @return array<string, array<string, mixed>>
     */
    private function updatableR5Baseline(): array
    {
        $path = database_path('fixtures/m2c-r5-faq.json');

        if (! is_file($path)) {
            return [];
        }

        $r5 = json_decode((string) file_get_contents($path), true);

        if (! is_array($r5) || ! is_array($r5['faqs']['global'] ?? null)) {
            return [];
        }

        $baseline = [];

        foreach ($r5['faqs']['global'] as $item) {
            $key = $item['managed_key'] ?? null;

            if (! is_string($key) || ! in_array($key, self::UPDATABLE_R5_KEYS, true)) {
                continue;
            }

            $baseline[$key] = [
                'scope' => 'global', 'product_slug' => null,
                'question' => (string) $item['question'],
                'answer' => (string) $item['answer'],
                'status' => (string) $item['status'],
                'sort_order' => (int) $item['sort_order'],
            ];
        }

        return $baseline;
    }

    /** @param array<string, mixed> $expected */
    private function rowMatches(Faq $row, array $expected): bool
    {
        $expectedServiceId = $expected['product_slug'] !== null
            ? Service::query()->where('product_slug', $expected['product_slug'])->value('id')
            : null;

        return (string) $row->scope === (string) $expected['scope']
            && $row->platform_id === null
            && ($row->service_id === null ? null : (int) $row->service_id) === ($expectedServiceId === null ? null : (int) $expectedServiceId)
            && (string) $row->question === (string) $expected['question']
            && (string) $row->answer === (string) $expected['answer']
            && (string) $row->status === (string) $expected['status']
            && (int) $row->sort_order === (int) $expected['sort_order'];
    }

    /**
     * @param  array<string, mixed>  $fixture
     * @param  array<string, Faq>  $locked
     * @param  array<string, mixed>  $stats
     */
    private function applyFaqs(array $fixture, array $locked, array &$stats): void
    {
        foreach ($this->expectedFromFixture($fixture) as $key => $expected) {
            $attributes = [
                'scope' => $expected['scope'],
                'platform_id' => null,
                'service_id' => $expected['product_slug'] !== null
                    ? $this->serviceBySlug($expected['product_slug'])->id
                    : null,
                'question' => $expected['question'],
                'answer' => $expected['answer'],
                'status' => $expected['status'],
                'sort_order' => $expected['sort_order'],
            ];

            // ⛔ 只用已鎖定的那批列;不得重新查詢。
            $existing = $locked[$key] ?? null;

            if ($existing === null) {
                Faq::query()->create($attributes + ['managed_key' => $key]);
                $stats['created']++;

                continue;
            }

            $existing->fill($attributes);

            if ($existing->isDirty()) {
                $existing->save();
                $stats['updated']++;
            } else {
                $stats['unchanged']++;
            }
        }
    }

    /**
     * apply 完成後每個 managed key 應有的值。
     *
     * @param  array<string, mixed>  $fixture
     * @return array<string, array<string, mixed>>
     */
    private function expectedFromFixture(array $fixture): array
    {
        $expected = [];

        foreach ($fixture['faqs']['global'] as $item) {
            $expected[$item['managed_key']] = [
                'scope' => 'global', 'product_slug' => null,
                'question' => $item['question'], 'answer' => $item['answer'],
                'status' => $item['status'], 'sort_order' => (int) $item['sort_order'],
            ];
        }

        foreach ($fixture['faqs']['service'] as $item) {
            $expected[$item['managed_key']] = [
                'scope' => 'service', 'product_slug' => $item['product_slug'],
                'question' => $item['question'], 'answer' => $item['answer'],
                'status' => $item['status'], 'sort_order' => (int) $item['sort_order'],
            ];
        }

        return $expected;
    }

    private function serviceBySlug(string $productSlug): Service
    {
        return Service::query()->where('product_slug', $productSlug)->first()
            ?? throw new RuntimeException("service 不存在:{$productSlug}");
    }

    // ------------------------------------------------------------------
    // Snapshot(FAQ-only)
    // ------------------------------------------------------------------

    /**
     * 由同一批 locked rows 組出 snapshot before-state。
     *
     * @param  array<string, mixed>  $fixture
     * @param  array<string, Faq>  $locked
     * @return array<string, mixed>
     */
    private function buildSnapshot(array $fixture, array $locked): array
    {
        $expected = $this->expectedFromFixture($fixture);
        $fixtureKeys = array_keys($expected);

        $beforeKeys = array_values(array_intersect($fixtureKeys, array_keys($locked)));
        sort($beforeKeys);

        $beforeRows = [];

        foreach ($beforeKeys as $key) {
            $beforeRows[] = $this->rowFrom($locked[$key]);
        }

        return [
            'schema_version' => self::SNAPSHOT_SCHEMA_VERSION,
            'created_at' => now()->toIso8601String(),
            'fixture' => self::FIXTURE,
            'fixture_sha256' => hash_file('sha256', database_path('fixtures/m2c-r6-faq-depth.json')),
            'expected' => $expected,
            'managed_faqs_before' => $beforeRows,
            'created_faq_keys' => array_values(array_diff($fixtureKeys, $beforeKeys)),
        ];
    }

    /** @param array<string, mixed> $snapshot */
    private function writeSnapshotFile(array $snapshot): string
    {
        $dir = storage_path('app/private/m2c-snapshots');

        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        // ⛔ 獨立命名:不得與 R3／R4／R5 快照混淆。
        $path = $dir.'/r6-faq-depth-'.now()->format('Ymd-His').'-'.uniqid().'.json';
        file_put_contents($path, json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return $path;
    }

    /** 只刪除「本次剛建立且位於固定快照目錄內」的檔案。 */
    private function discardSnapshot(?string $path): void
    {
        if ($path === null) {
            return;
        }

        $dir = realpath(storage_path('app/private/m2c-snapshots'));
        $real = realpath($path);

        if ($dir === false || $real === false || ! is_file($real)
            || ! str_starts_with($real, $dir.DIRECTORY_SEPARATOR)
            || ! str_starts_with(basename($real), 'r6-faq-depth-')
            || ! str_ends_with($real, '.json')) {
            return;
        }

        @unlink($real);
    }

    /** @return array<string, mixed> */
    private function rowFrom(Faq $faq): array
    {
        return [
            'id' => (int) $faq->id,
            'scope' => (string) $faq->scope,
            'platform_id' => $faq->platform_id === null ? null : (int) $faq->platform_id,
            'service_id' => $faq->service_id === null ? null : (int) $faq->service_id,
            'question' => (string) $faq->question,
            'answer' => (string) $faq->answer,
            'status' => (string) $faq->status,
            'sort_order' => (int) $faq->sort_order,
            'managed_key' => $faq->managed_key === null ? null : (string) $faq->managed_key,
        ];
    }

    // ------------------------------------------------------------------
    // Rollback
    // ------------------------------------------------------------------

    private function rollbackFromSnapshot(string $requestedPath): int
    {
        $plan = $this->loadAndValidateSnapshot($requestedPath);

        $restored = ['deleted' => 0, 'restored' => 0];

        DB::transaction(function () use ($plan, &$restored): void {
            $keys = array_keys($plan['expected']);
            sort($keys);

            // 鎖定後再驗證:與 apply 同等強度。
            $locked = Faq::withTrashed()
                ->whereIn('managed_key', $keys)
                ->orderBy('managed_key')
                ->lockForUpdate()
                ->get()
                ->keyBy('managed_key')
                ->all();

            $this->assertNoRollbackConflicts($plan, $locked);

            // ⛔ 刪除只限 snapshot 明列的本次新建 exact keys。
            foreach ($plan['created_faq_keys'] as $key) {
                if (isset($locked[$key])) {
                    $locked[$key]->forceDelete();
                    $restored['deleted']++;
                }
            }

            // 快照前已存在的列 → 逐欄還原原值。
            foreach ($plan['managed_faqs_before'] as $row) {
                $faq = $locked[$row['managed_key']] ?? null;

                if ($faq !== null && (int) $faq->id === (int) $row['id']) {
                    $faq->restore();
                    $faq->forceFill(collect($row)->except('id')->all())->save();
                    $restored['restored']++;
                }
            }
        });

        $this->info(sprintf(
            '[rollback] faq deleted=%d;faq restored=%d(來源:%s)',
            $restored['deleted'], $restored['restored'], $plan['path'],
        ));

        return self::SUCCESS;
    }

    /**
     * ⛔ current state 必須等於 expected-applied state,否則整批停止。
     *
     * @param  array<string, mixed>  $plan
     * @param  array<string, Faq>  $locked
     */
    private function assertNoRollbackConflicts(array $plan, array $locked): void
    {
        $conflicts = [];

        foreach ($plan['expected'] as $key => $expected) {
            $row = $locked[$key] ?? null;

            if ($row === null || $row->deleted_at !== null || ! $this->rowMatches($row, $expected)) {
                $conflicts[] = 'faq.'.$key;
            }
        }

        if ($conflicts !== []) {
            sort($conflicts);

            throw new RuntimeException('R6-ROLLBACK-CONFLICT:'.implode(',', $conflicts));
        }
    }

    /**
     * 路徑防護+schema 驗證+normalize 成單一 rollback 計畫。
     *
     * @return array<string, mixed>
     */
    private function loadAndValidateSnapshot(string $requestedPath): array
    {
        $dir = realpath(storage_path('app/private/m2c-snapshots'));
        $real = realpath($requestedPath);

        // ⛔ 只接受允許目錄內、實際解析後的檔案(擋目錄外/symlink 逃逸/缺檔)。
        if ($dir === false || $real === false || ! is_file($real)
            || ! str_starts_with($real, $dir.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('快照路徑不在允許目錄內或不存在。');
        }

        $snapshot = json_decode((string) file_get_contents($real), true);

        if (! is_array($snapshot)) {
            throw new RuntimeException('快照檔無法解析。');
        }

        if (($snapshot['schema_version'] ?? null) !== self::SNAPSHOT_SCHEMA_VERSION) {
            throw new RuntimeException('未知 snapshot schema。');
        }

        $this->exactKeys(array_keys($snapshot), self::SNAPSHOT_TOP_KEYS, 'snapshot top-level');

        // ⛔ fixture 識別與 hash 必須對得上現行 versioned fixture。
        if (($snapshot['fixture'] ?? null) !== self::FIXTURE) {
            throw new RuntimeException('快照 fixture 識別不符。');
        }

        $currentHash = hash_file('sha256', database_path('fixtures/m2c-r6-faq-depth.json'));

        if (! is_string($snapshot['fixture_sha256'] ?? null) || ! hash_equals($currentHash, $snapshot['fixture_sha256'])) {
            throw new RuntimeException('快照 fixture hash 與現行 fixture 不符。');
        }

        $fixture = $this->loadFixture();
        $fixtureExpected = $this->expectedFromFixture($fixture);

        $expected = (array) $snapshot['expected'];
        $this->exactKeys(array_keys($expected), array_keys($fixtureExpected), 'snapshot.expected');

        if ($this->normalizeExpected($expected) != $this->normalizeExpected($fixtureExpected)) {
            throw new RuntimeException('快照 expected 與 versioned fixture 不一致。');
        }

        $beforeRows = $this->exactFaqRows((array) $snapshot['managed_faqs_before'], 'managed_faqs_before');
        $beforeKeys = array_column($beforeRows, 'managed_key');

        foreach ($beforeKeys as $key) {
            if (! array_key_exists($key, $fixtureExpected)) {
                throw new RuntimeException('managed_faqs_before 含非 fixture key。');
            }
        }

        // ⛔ created keys 必須恰等於 fixture keys − before keys。
        $createdKeys = array_values((array) $snapshot['created_faq_keys']);
        $expectedCreated = array_values(array_diff(array_keys($fixtureExpected), $beforeKeys));

        if ($this->sortedList($createdKeys) !== $this->sortedList($expectedCreated)) {
            throw new RuntimeException('快照 created keys 與 fixture−before 集合關係不符。');
        }

        return [
            'path' => $real,
            'expected' => $fixtureExpected,
            'managed_faqs_before' => $beforeRows,
            'created_faq_keys' => $createdKeys,
        ];
    }

    /**
     * @param  array<string, mixed>  $expected
     * @return array<string, mixed>
     */
    private function normalizeExpected(array $expected): array
    {
        $normalized = [];

        foreach ($expected as $key => $row) {
            if (! is_array($row)) {
                throw new RuntimeException('快照 expected 列不是物件。');
            }

            $this->exactKeys(
                array_keys($row),
                ['scope', 'product_slug', 'question', 'answer', 'status', 'sort_order'],
                "expected.{$key}",
            );

            $normalized[$key] = [
                'scope' => (string) $row['scope'],
                'product_slug' => $row['product_slug'] === null ? null : (string) $row['product_slug'],
                'question' => (string) $row['question'],
                'answer' => (string) $row['answer'],
                'status' => (string) $row['status'],
                'sort_order' => (int) $row['sort_order'],
            ];
        }

        ksort($normalized);

        return $normalized;
    }

    /**
     * @param  array<int, mixed>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function exactFaqRows(array $rows, string $where): array
    {
        $ids = [];
        $keys = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw new RuntimeException("快照 {$where} 列不是物件。");
            }

            $this->exactKeys(array_keys($row), self::FAQ_ROW_FIELDS, $where);

            if (! is_int($row['id']) || $row['id'] <= 0 || in_array($row['id'], $ids, true)) {
                throw new RuntimeException("快照 {$where} id 非 positive unique int。");
            }
            $ids[] = $row['id'];

            if (! in_array($row['scope'], Faq::SCOPES, true)) {
                throw new RuntimeException("快照 {$where} scope 不合法。");
            }

            foreach (['question', 'answer', 'status'] as $field) {
                $this->assertFilledString($row[$field] ?? null, "快照 {$where}.{$field}");
            }

            if (! is_int($row['sort_order'])) {
                throw new RuntimeException("快照 {$where}.sort_order 非整數。");
            }

            foreach (['platform_id', 'service_id'] as $field) {
                if ($row[$field] !== null && (! is_int($row[$field]) || $row[$field] <= 0)) {
                    throw new RuntimeException("快照 {$where}.{$field} 非 null/positive int。");
                }
            }

            if (! is_string($row['managed_key'])
                || (! str_starts_with($row['managed_key'], 'r6.') && ! in_array($row['managed_key'], self::UPDATABLE_R5_KEYS, true))
                || in_array($row['managed_key'], $keys, true)) {
                throw new RuntimeException("快照 {$where}.managed_key 不在允許範圍或重複。");
            }
            $keys[] = $row['managed_key'];
        }

        return $rows;
    }

    /**
     * ⛔ key 集合必須「恰好等於」allowlist:多一欄、少一欄都拒絕。
     *
     * @param  array<int, mixed>  $keys
     * @param  list<string>  $required
     */
    private function exactKeys(array $keys, array $required, string $where): void
    {
        if ($this->sortedList($keys) !== $this->sortedList($required)) {
            throw new RuntimeException("{$where} key 集合不完整或含未知 key。");
        }
    }

    /**
     * @param  array<int, mixed>  $list
     * @return list<string>
     */
    private function sortedList(array $list): array
    {
        $list = array_values(array_map('strval', $list));
        sort($list);

        return $list;
    }
}
