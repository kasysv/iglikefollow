<?php

namespace App\Console\Commands;

use App\Models\Faq;
use App\Models\Platform;
use App\Models\Service;
use App\Support\ProductSlugMap;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * M2-C R5:GEO／AEO FAQ 套用(唯一來源=repo 內 R5 fixture)。
 *
 * ⛔ 只碰 `faqs` 表、且只碰 fixture 明列的 exact managed key。
 * 價格/SKU/款式/mapping/orders/payments/invoices/credential 一律不碰,
 * 其他內容表(site_settings、platforms、services、content sections)也不碰
 * ——那些屬於 R3/R4 的 snapshot 責任範圍,R5 覆寫會使其 expected 失效。
 *
 * Owner 自建 FAQ(managed_key IS NULL)不可讀寫:R5 沒有任何 removal
 * 路徑,也不做 question 模糊匹配、LIKE 或 prefix-wide 刪除。
 *
 * Rollback 合約(沿用 R3 R2 封閉版的同等強度):
 * - 只接受 `storage/app/private/m2c-snapshots` 內 realpath 解析後的檔案。
 * - snapshot 為 FAQ-only:不含 orders、PII、credential 或其他內容表。
 * - 還原前在同一 transaction 內驗證 current==expected-applied,任一列被
 *   Owner 改過 → 整批 0 writes,輸出固定 conflict identifier(不含文案)。
 * - 刪除只限 snapshot 明列「本次 apply 新建」的 exact managed keys。
 */
class M2cApplyR5FaqCommand extends Command
{
    protected $signature = 'm2c:apply-r5-faq {--dry-run : 驗證並試算,不寫入} {--rollback= : 從指定快照檔還原本輪 FAQ}';

    protected $description = 'M2-C R5:套用 GEO／AEO FAQ(transactional;支援 dry-run 與 FAQ-only 快照 rollback)';

    private const SNAPSHOT_SCHEMA_VERSION = 1;

    private const FIXTURE = 'database/fixtures/m2c-r5-faq.json';

    private const PLATFORM_SLUGS = ['instagram', 'facebook', 'threads'];

    private const FAQ_ROW_FIELDS = ['id', 'scope', 'platform_id', 'service_id', 'question', 'answer', 'status', 'sort_order', 'managed_key'];

    /** fixture 每列的 exact 欄位集合;多一少一都拒絕。 */
    private const GLOBAL_ITEM_FIELDS = ['managed_key', 'question', 'answer', 'status', 'sort_order'];

    private const PLATFORM_ITEM_FIELDS = ['managed_key', 'platform_slug', 'question', 'answer', 'status', 'sort_order'];

    private const SERVICE_ITEM_FIELDS = ['managed_key', 'product_slug', 'question', 'answer', 'status', 'sort_order'];

    private const PAGE_FIELDS = ['seo_title', 'meta_description', 'h1', 'intro', 'home_featured_keys'];

    /**
     * 允許就地更新的既有 R3 managed key(同題改寫)。
     *
     * R5 有 3 題與 R3 global 是同一題(會員、下單準備、查看價格),依任務
     * 要求「同題既有 R3 FAQ 以精確 managed key 更新」就地改寫,⛔ 不建立
     * 平行重複列讓 /faq 出現兩題同義問答。這是唯一能碰非 `r5.*` key 的
     * 通道,且為寫死的精確清單:其他 R3 key 與 Owner 自建列
     * (managed_key IS NULL)都不在範圍內。
     */
    private const REUSABLE_R3_KEYS = ['r3.global.membership', 'r3.global.prepare', 'r3.global.price'];

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

            if (! $dry) {
                $snapshotPath = $this->writeSnapshot($fixture);
            }

            try {
                DB::transaction(function () use ($fixture, $dry, &$stats): void {
                    $this->applyFaqs($fixture, $stats);

                    if ($dry) {
                        throw new RuntimeException('__R5_DRY_RUN_ROLLBACK__');
                    }
                });
            } catch (RuntimeException $e) {
                if ($e->getMessage() !== '__R5_DRY_RUN_ROLLBACK__') {
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
            $this->error('R5 失敗,未留下部分寫入:'.$e->getMessage());

            return self::FAILURE;
        }
    }

    // ------------------------------------------------------------------
    // Fixture(fail closed)
    // ------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function loadFixture(): array
    {
        $path = database_path('fixtures/m2c-r5-faq.json');
        $fixture = json_decode((string) file_get_contents($path), true);

        if (! is_array($fixture)) {
            throw new RuntimeException('R5 fixture 缺失或無法解析。');
        }

        $this->exactKeys(array_keys($fixture), ['_meta', 'page', 'faqs'], 'fixture top-level');
        $this->exactKeys(array_keys((array) $fixture['page']), self::PAGE_FIELDS, 'fixture.page');
        $this->exactKeys(array_keys((array) $fixture['faqs']), ['global', 'platform', 'service'], 'fixture.faqs');

        foreach (['seo_title', 'meta_description', 'h1', 'intro'] as $field) {
            $this->assertFilledString($fixture['page'][$field] ?? null, "fixture.page.{$field}");
        }

        $serviceSlugs = array_values(ProductSlugMap::MAP);
        $keys = [];

        foreach ($fixture['faqs']['global'] as $i => $item) {
            $this->validateItem($item, self::GLOBAL_ITEM_FIELDS, "faqs.global[{$i}]", $keys);
        }

        foreach ($fixture['faqs']['platform'] as $i => $item) {
            $this->validateItem($item, self::PLATFORM_ITEM_FIELDS, "faqs.platform[{$i}]", $keys);

            if (! in_array($item['platform_slug'], self::PLATFORM_SLUGS, true)) {
                throw new RuntimeException("faqs.platform[{$i}] 指向未知 platform。");
            }
        }

        foreach ($fixture['faqs']['service'] as $i => $item) {
            $this->validateItem($item, self::SERVICE_ITEM_FIELDS, "faqs.service[{$i}]", $keys);

            if (! in_array($item['product_slug'], $serviceSlugs, true)) {
                throw new RuntimeException("faqs.service[{$i}] 指向未知 product slug。");
            }
        }

        /*
         * ⛔ 首頁精選必須是實際存在且 published 的 global key:
         * 指到 draft 或不存在的 key 會讓首頁靜默少一題。
         */
        $publishedGlobal = [];

        foreach ($fixture['faqs']['global'] as $item) {
            if ($item['status'] === 'published') {
                $publishedGlobal[] = $item['managed_key'];
            }
        }

        $featured = $fixture['page']['home_featured_keys'];

        if (! is_array($featured) || $featured === []) {
            throw new RuntimeException('fixture.page.home_featured_keys 不得為空。');
        }

        foreach ($featured as $key) {
            if (! in_array($key, $publishedGlobal, true)) {
                throw new RuntimeException('fixture.page.home_featured_keys 含非 published global key。');
            }
        }

        if (count($featured) !== count(array_unique($featured))) {
            throw new RuntimeException('fixture.page.home_featured_keys 重複。');
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
         * ⛔ 只准 `r5.*` 新 key,或明列的同題 R3 key(就地更新)。
         * 其他 R3 key、Owner 自建列一律拒絕。
         */
        if (! str_starts_with($item['managed_key'], 'r5.')
            && ! in_array($item['managed_key'], self::REUSABLE_R3_KEYS, true)) {
            throw new RuntimeException("{$where}.managed_key 不在允許範圍(r5.* 或明列的同題 R3 key)。");
        }

        if (in_array($item['managed_key'], $keys, true)) {
            throw new RuntimeException("{$where}.managed_key 重複。");
        }

        $keys[] = $item['managed_key'];

        if (! in_array($item['status'], ['published', 'draft'], true)) {
            throw new RuntimeException("{$where}.status 不合法。");
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
    // Apply
    // ------------------------------------------------------------------

    /** @param array<string, mixed> $fixture @param array<string, mixed> $stats */
    private function applyFaqs(array $fixture, array &$stats): void
    {
        foreach ($this->expectedFromFixture($fixture) as $key => $expected) {
            $attributes = [
                'scope' => $expected['scope'],
                'platform_id' => $expected['platform_slug'] !== null
                    ? $this->platformBySlug($expected['platform_slug'])->id
                    : null,
                'service_id' => $expected['product_slug'] !== null
                    ? $this->serviceBySlug($expected['product_slug'])->id
                    : null,
                'question' => $expected['question'],
                'answer' => $expected['answer'],
                'status' => $expected['status'],
                'sort_order' => $expected['sort_order'],
            ];

            // ⛔ 只以 exact managed key 定位;不做 question 模糊匹配。
            $existing = Faq::query()->where('managed_key', $key)->first();

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
                'scope' => 'global', 'platform_slug' => null, 'product_slug' => null,
                'question' => $item['question'], 'answer' => $item['answer'],
                'status' => $item['status'], 'sort_order' => (int) $item['sort_order'],
            ];
        }

        foreach ($fixture['faqs']['platform'] as $item) {
            $expected[$item['managed_key']] = [
                'scope' => 'platform', 'platform_slug' => $item['platform_slug'], 'product_slug' => null,
                'question' => $item['question'], 'answer' => $item['answer'],
                'status' => $item['status'], 'sort_order' => (int) $item['sort_order'],
            ];
        }

        foreach ($fixture['faqs']['service'] as $item) {
            $expected[$item['managed_key']] = [
                'scope' => 'service', 'platform_slug' => null, 'product_slug' => $item['product_slug'],
                'question' => $item['question'], 'answer' => $item['answer'],
                'status' => $item['status'], 'sort_order' => (int) $item['sort_order'],
            ];
        }

        return $expected;
    }

    private function platformBySlug(string $slug): Platform
    {
        return Platform::query()->where('slug', $slug)->first()
            ?? throw new RuntimeException("platform 不存在:{$slug}");
    }

    private function serviceBySlug(string $productSlug): Service
    {
        return Service::query()->where('product_slug', $productSlug)->first()
            ?? throw new RuntimeException("service 不存在:{$productSlug}");
    }

    // ------------------------------------------------------------------
    // Snapshot(FAQ-only)
    // ------------------------------------------------------------------

    /** @param array<string, mixed> $fixture */
    private function writeSnapshot(array $fixture): string
    {
        $expected = $this->expectedFromFixture($fixture);
        $fixtureKeys = array_keys($expected);

        /*
         * ⛔ 只擷取本輪會更新／新增的 exact managed key 列;不含 orders、
         * PII、credential,也不含其他內容表或 Owner 自建 FAQ。
         */
        $beforeRows = Faq::withTrashed()
            ->whereIn('managed_key', $fixtureKeys)
            ->get()
            ->map(fn (Faq $faq) => $this->rowFrom($faq))
            ->values()
            ->all();

        $beforeKeys = array_column($beforeRows, 'managed_key');

        $snapshot = [
            'schema_version' => self::SNAPSHOT_SCHEMA_VERSION,
            'created_at' => now()->toIso8601String(),
            'fixture' => self::FIXTURE,
            'fixture_sha256' => hash_file('sha256', database_path('fixtures/m2c-r5-faq.json')),
            'expected' => $expected,
            'managed_faqs_before' => $beforeRows,
            'created_faq_keys' => array_values(array_diff($fixtureKeys, $beforeKeys)),
        ];

        $dir = storage_path('app/private/m2c-snapshots');

        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        // uniqid:同一秒內多次 apply(如測試)不得互相覆寫快照。
        $path = $dir.'/r5-faq-'.now()->format('Ymd-His').'-'.uniqid().'.json';
        file_put_contents($path, json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return $path;
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
            // ⛔ 先驗 current==expected-applied;任何衝突 → 整批 0 writes。
            $this->assertNoConflicts($plan);

            // ⛔ 刪除只限 snapshot 明列的本次新建 exact keys。
            foreach ($plan['created_faq_keys'] as $key) {
                $faq = Faq::withTrashed()->where('managed_key', $key)->first();

                if ($faq !== null) {
                    $faq->forceDelete();
                    $restored['deleted']++;
                }
            }

            // 快照前已存在的列 → 逐欄還原原值。
            foreach ($plan['managed_faqs_before'] as $row) {
                $faq = Faq::withTrashed()->find($row['id']);

                if ($faq !== null) {
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

        // ⛔ fixture 識別與 hash 必須實際對得上現行 versioned fixture。
        if (($snapshot['fixture'] ?? null) !== self::FIXTURE) {
            throw new RuntimeException('快照 fixture 識別不符。');
        }

        $currentHash = hash_file('sha256', database_path('fixtures/m2c-r5-faq.json'));

        if (! is_string($snapshot['fixture_sha256'] ?? null) || ! hash_equals($currentHash, $snapshot['fixture_sha256'])) {
            throw new RuntimeException('快照 fixture hash 與現行 fixture 不符。');
        }

        $fixture = $this->loadFixture();
        $fixtureExpected = $this->expectedFromFixture($fixture);

        // expected 必須與現行 fixture 逐欄一致,不能只把快照內容當真。
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

        // ⛔ created keys 必須恰等於 fixture keys − before keys(集合相等)。
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
                ['scope', 'platform_slug', 'product_slug', 'question', 'answer', 'status', 'sort_order'],
                "expected.{$key}",
            );

            $normalized[$key] = [
                'scope' => (string) $row['scope'],
                'platform_slug' => $row['platform_slug'] === null ? null : (string) $row['platform_slug'],
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
     * ⛔ current state 必須等於 expected-applied state,否則整批停止。
     * conflict identifier 只含 key 識別,不含任何文案內容。
     *
     * @param  array<string, mixed>  $plan
     */
    private function assertNoConflicts(array $plan): void
    {
        $conflicts = [];

        foreach ($plan['expected'] as $key => $expected) {
            $row = Faq::query()->where('managed_key', $key)->first();

            $expectedPlatformId = $expected['platform_slug'] !== null
                ? Platform::query()->where('slug', $expected['platform_slug'])->value('id')
                : null;
            $expectedServiceId = $expected['product_slug'] !== null
                ? Service::query()->where('product_slug', $expected['product_slug'])->value('id')
                : null;

            if ($row === null
                || (string) $row->scope !== (string) $expected['scope']
                || ($row->platform_id === null ? null : (int) $row->platform_id) !== ($expectedPlatformId === null ? null : (int) $expectedPlatformId)
                || ($row->service_id === null ? null : (int) $row->service_id) !== ($expectedServiceId === null ? null : (int) $expectedServiceId)
                || (string) $row->question !== (string) $expected['question']
                || (string) $row->answer !== (string) $expected['answer']
                || (string) $row->status !== (string) $expected['status']
                || (int) $row->sort_order !== (int) $expected['sort_order']) {
                $conflicts[] = 'faq.'.$key;
            }
        }

        if ($conflicts !== []) {
            throw new RuntimeException('R5-ROLLBACK-CONFLICT:'.implode(',', $conflicts));
        }
    }

    // ------------------------------------------------------------------
    // 閉合驗證 helpers
    // ------------------------------------------------------------------

    /**
     * FAQ row 的 exact schema+型別:positive unique id、合法 scope、
     * 非空字串內容、非空 unique managed_key。
     *
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
                || (! str_starts_with($row['managed_key'], 'r5.') && ! in_array($row['managed_key'], self::REUSABLE_R3_KEYS, true))
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
