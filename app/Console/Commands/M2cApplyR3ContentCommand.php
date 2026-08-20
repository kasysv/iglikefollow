<?php

namespace App\Console\Commands;

use App\Models\Faq;
use App\Models\Platform;
use App\Models\Service;
use App\Models\ServiceContentSection;
use App\Models\SiteSetting;
use App\Support\ProductSlugMap;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * M2-C R3:13 頁完整 SEO 內容套用(唯一來源=repo 內 R3 fixture)。
 *
 * ⛔ 只碰公開內容欄位:site settings(含固定六個 highlight 欄)、
 * 3 platforms、9 services、managed content sections 與 managed FAQ,及
 * fixture 明列之受控 FAQ 移除(exact scope+question+managed_key IS NULL)。
 * 價格/數量/SKU/status/mapping/orders/credential 不碰。
 *
 * R1 封閉後的 rollback 合約:
 * - 只接受 `storage/app/private/m2c-snapshots` 內實際解析(realpath)的
 *   檔案;目錄外、symlink 逃逸、缺檔、壞 JSON、未知 schema、未知
 *   top-level key、未知 slug/欄位、重複 id/key 一律 fail closed。
 * - 欄位只能來自程式內常數 allowlist;highlight 固定六欄;snapshot
 *   不得指示寫入其他 column。
 * - 刪除只限 snapshot 明列「本次 apply 新建」的 exact managed keys;
 *   ⛔ 禁止 LIKE/prefix-wide deletion。
 * - 還原前在同一 transaction 內逐列驗證 current state 仍等於 snapshot
 *   記錄的 expected-applied state;任何一列被 Owner/其他流程改過 →
 *   整批 0 writes,輸出固定 conflict identifier(不含文案內容)。
 * - 首次 legacy v1 快照由嚴格 v1 parser 閉合:expected 值取自現行 R3
 *   fixture 的 exact 值,新建 keys=fixture keys−v1 既存 keys;不因相容
 *   而放寬任意欄位或 prefix 刪除。
 */
class M2cApplyR3ContentCommand extends Command
{
    protected $signature = 'm2c:apply-r3 {--dry-run : 驗證並試算,不寫入} {--rollback= : 從指定快照檔還原本輪欄位}';

    protected $description = 'M2-C R3:套用 13 頁完整 SEO 內容(transactional;支援 dry-run 與快照 rollback)';

    private const SNAPSHOT_SCHEMA_VERSION = 2;

    private const SITE_FIELDS = ['seo_title', 'meta_description', 'home_h1', 'home_intro', 'primary_cta_label'];

    /** ⛔ highlight 固定六欄;snapshot 不得指示寫入其他 column。 */
    private const HIGHLIGHT_FIELDS = [
        'home_highlight_1_title', 'home_highlight_1_body',
        'home_highlight_2_title', 'home_highlight_2_body',
        'home_highlight_3_title', 'home_highlight_3_body',
    ];

    private const PLATFORM_FIELDS = ['h1', 'tagline', 'intro', 'seo_title', 'meta_description'];

    private const PLATFORM_SLUGS = ['instagram', 'facebook', 'threads'];

    private const SERVICE_FIELDS = ['seo_title', 'meta_description', 'h1', 'summary', 'intro', 'card_title', 'card_blurb', 'cta_label'];

    private const FAQ_ROW_FIELDS = ['id', 'scope', 'platform_id', 'service_id', 'question', 'answer', 'status', 'sort_order', 'managed_key'];

    private const SECTION_ROW_FIELDS = ['id', 'service_id', 'heading', 'body', 'status', 'sort_order', 'managed_key'];

    /** v1(首次快照)的精確 top-level key 集合;多一少一都拒絕。 */
    private const LEGACY_V1_KEYS = ['created_at', 'fixture', 'site', 'platforms', 'services', 'managed_faqs_before', 'managed_sections_before', 'removed_faqs'];

    public function handle(): int
    {
        try {
            if (($snapshot = (string) $this->option('rollback')) !== '') {
                return $this->rollbackFromSnapshot($snapshot);
            }

            $fixture = $this->loadFixture();
            $dry = (bool) $this->option('dry-run');

            $stats = [
                'site' => 0, 'platform' => 0, 'service' => 0,
                'sections_created' => 0, 'sections_updated' => 0, 'sections_unchanged' => 0,
                'faq_created' => 0, 'faq_updated' => 0, 'faq_unchanged' => 0, 'faq_removed' => 0,
                'unchanged_fields' => 0,
            ];

            $snapshotPath = null;

            if (! $dry) {
                $snapshotPath = $this->writeSnapshot($fixture);
            }

            try {
                DB::transaction(function () use ($fixture, $dry, &$stats): void {
                    $this->applyRemovals($fixture, $stats);
                    $this->applySite($fixture, $stats);
                    $this->applyPlatforms($fixture, $stats);
                    $this->applyServices($fixture, $stats);
                    $this->applySections($fixture, $stats);
                    $this->applyFaqs($fixture, $stats);

                    if ($dry) {
                        throw new RuntimeException('__R3_DRY_RUN_ROLLBACK__');
                    }
                });
            } catch (RuntimeException $e) {
                if ($e->getMessage() !== '__R3_DRY_RUN_ROLLBACK__') {
                    throw $e;
                }
            }

            $this->info(sprintf(
                '%s site=%d platform=%d service=%d;sections created=%d updated=%d unchanged=%d;faq created=%d updated=%d unchanged=%d removed=%d;fields already equal=%d%s',
                $dry ? '[dry-run,已 rollback,0 writes]' : '[applied]',
                $stats['site'], $stats['platform'], $stats['service'],
                $stats['sections_created'], $stats['sections_updated'], $stats['sections_unchanged'],
                $stats['faq_created'], $stats['faq_updated'], $stats['faq_unchanged'], $stats['faq_removed'],
                $stats['unchanged_fields'],
                $snapshotPath !== null ? ';snapshot='.$snapshotPath : '',
            ));

            return self::SUCCESS;
        } catch (RuntimeException $e) {
            $this->error('R3 失敗,未留下部分寫入:'.$e->getMessage());

            return self::FAILURE;
        }
    }

    /** @return array<string, mixed> */
    private function loadFixture(): array
    {
        $path = database_path('fixtures/m2c-r3-content.json');
        $fixture = json_decode((string) file_get_contents($path), true);

        if (! is_array($fixture)) {
            throw new RuntimeException('R3 fixture 缺失或無法解析。');
        }

        foreach (['site', 'platforms', 'services', 'content_sections', 'faqs', 'faq_removals'] as $key) {
            if (! isset($fixture[$key])) {
                throw new RuntimeException("R3 fixture 缺少段落:{$key}");
            }
        }

        $known = array_values(ProductSlugMap::MAP);

        foreach (array_keys($fixture['services']) as $slug) {
            if (! in_array($slug, $known, true)) {
                throw new RuntimeException("R3 fixture 有未知 product slug:{$slug}");
            }
        }

        if (count($fixture['services']) !== 9) {
            throw new RuntimeException('R3 fixture 服務數不是 9。');
        }

        if (array_keys($fixture['platforms']) !== self::PLATFORM_SLUGS) {
            throw new RuntimeException('R3 fixture 平台集合不正確。');
        }

        if (count($fixture['site']['highlights'] ?? []) !== 3) {
            throw new RuntimeException('R3 fixture highlights 必須恰為 3 組。');
        }

        $keys = $this->fixtureManagedKeys($fixture);

        if (count($keys) !== count(array_unique($keys))) {
            throw new RuntimeException('R3 managed_key 重複。');
        }

        $assertFilled = function (mixed $value, string $where): void {
            if (! is_string($value) || trim($value) === '') {
                throw new RuntimeException("R3 fixture 空值:{$where}");
            }
        };

        foreach ($fixture['content_sections'] as $section) {
            $assertFilled($section['managed_key'] ?? null, 'section.managed_key');
            $assertFilled($section['heading'] ?? null, ($section['managed_key'] ?? '?').'.heading');
            $assertFilled($section['body'] ?? null, ($section['managed_key'] ?? '?').'.body');

            if (! in_array($section['product_slug'] ?? null, $known, true)) {
                throw new RuntimeException('section 指向未知 product slug:'.($section['managed_key'] ?? '?'));
            }
        }

        foreach (array_merge($fixture['faqs']['global'], $fixture['faqs']['service']) as $faq) {
            $assertFilled($faq['managed_key'] ?? null, 'faq.managed_key');
            $assertFilled($faq['question'] ?? null, ($faq['managed_key'] ?? '?').'.question');
            $assertFilled($faq['answer'] ?? null, ($faq['managed_key'] ?? '?').'.answer');
        }

        return $fixture;
    }

    /**
     * @param  array<string, mixed>  $fixture
     * @return list<string>
     */
    private function fixtureManagedKeys(array $fixture): array
    {
        return array_merge(
            array_column($fixture['content_sections'], 'managed_key'),
            array_column($fixture['faqs']['global'], 'managed_key'),
            array_column($fixture['faqs']['service'], 'managed_key'),
        );
    }

    private function serviceBySlug(string $productSlug): Service
    {
        return Service::query()->where('product_slug', $productSlug)->first()
            ?? throw new RuntimeException("service 不存在(product_slug):{$productSlug}");
    }

    /**
     * v2 快照:before 值+expected-applied 值+精確 managed key 分類。
     *
     * @param  array<string, mixed>  $fixture
     */
    private function writeSnapshot(array $fixture): string
    {
        $managedKeys = $this->fixtureManagedKeys($fixture);

        $beforeFaqRows = Faq::query()->whereIn('managed_key', $managedKeys)->get()
            ->map(fn (Faq $f) => $f->only(self::FAQ_ROW_FIELDS))->all();
        $beforeSectionRows = ServiceContentSection::query()->whereIn('managed_key', $managedKeys)->get()
            ->map(fn (ServiceContentSection $s) => $s->only(self::SECTION_ROW_FIELDS))->all();

        $beforeFaqKeys = array_column($beforeFaqRows, 'managed_key');
        $beforeSectionKeys = array_column($beforeSectionRows, 'managed_key');

        $fixtureFaqKeys = array_merge(
            array_column($fixture['faqs']['global'], 'managed_key'),
            array_column($fixture['faqs']['service'], 'managed_key'),
        );
        $fixtureSectionKeys = array_column($fixture['content_sections'], 'managed_key');

        $removalRows = [];

        foreach ($fixture['faq_removals'] as $removal) {
            $query = Faq::query()->whereNull('managed_key')
                ->where('scope', $removal['scope'])
                ->where('question', $removal['question']);

            if (($removal['product_slug'] ?? null) !== null) {
                $query->where('service_id', $this->serviceBySlug($removal['product_slug'])->id);
            }

            foreach ($query->get() as $row) {
                $removalRows[] = $row->only(self::FAQ_ROW_FIELDS);
            }
        }

        $snapshot = [
            'schema_version' => self::SNAPSHOT_SCHEMA_VERSION,
            'created_at' => now()->toIso8601String(),
            'fixture' => 'database/fixtures/m2c-r3-content.json',
            'fixture_sha256' => hash_file('sha256', database_path('fixtures/m2c-r3-content.json')),
            'site' => SiteSetting::query()->first()?->only(array_merge(self::SITE_FIELDS, self::HIGHLIGHT_FIELDS)),
            'platforms' => Platform::query()->whereIn('slug', self::PLATFORM_SLUGS)->get()
                ->mapWithKeys(fn (Platform $p) => [$p->slug => $p->only(self::PLATFORM_FIELDS)])->all(),
            'services' => Service::query()->whereIn('product_slug', array_keys($fixture['services']))->get()
                ->mapWithKeys(fn (Service $s) => [$s->product_slug => $s->only(self::SERVICE_FIELDS)])->all(),
            'expected' => $this->expectedFromFixture($fixture),
            'managed_faqs_before' => $beforeFaqRows,
            'managed_sections_before' => $beforeSectionRows,
            'created_faq_keys' => array_values(array_diff($fixtureFaqKeys, $beforeFaqKeys)),
            'created_section_keys' => array_values(array_diff($fixtureSectionKeys, $beforeSectionKeys)),
            'removed_faqs' => $removalRows,
        ];

        $dir = storage_path('app/private/m2c-snapshots');

        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        // uniqid:同一秒內多次 apply(如測試)不得互相覆寫快照。
        $path = $dir.'/r3-content-'.now()->format('Ymd-His').'-'.uniqid().'.json';
        file_put_contents($path, json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return $path;
    }

    /**
     * expected-applied 狀態:apply 完成後各欄位/各 managed 列應有的值。
     *
     * @param  array<string, mixed>  $fixture
     * @return array<string, mixed>
     */
    private function expectedFromFixture(array $fixture): array
    {
        $site = [];

        foreach (self::SITE_FIELDS as $field) {
            $site[$field] = $fixture['site'][$field];
        }

        foreach ($fixture['site']['highlights'] as $i => $highlight) {
            $n = $i + 1;
            $site["home_highlight_{$n}_title"] = $highlight['title'];
            $site["home_highlight_{$n}_body"] = $highlight['body'];
        }

        $platforms = [];

        foreach ($fixture['platforms'] as $slug => $values) {
            foreach (self::PLATFORM_FIELDS as $field) {
                $platforms[$slug][$field] = $values[$field];
            }
        }

        $services = [];

        foreach ($fixture['services'] as $slug => $values) {
            foreach (self::SERVICE_FIELDS as $field) {
                $services[$slug][$field] = $values[$field];
            }
        }

        $managed = [];

        foreach ($fixture['content_sections'] as $section) {
            $managed[$section['managed_key']] = [
                'type' => 'section',
                'product_slug' => $section['product_slug'],
                'heading' => $section['heading'],
                'body' => $section['body'],
                'status' => 'published',
                'sort_order' => (int) $section['sort_order'],
            ];
        }

        foreach ($fixture['faqs']['global'] as $faq) {
            $managed[$faq['managed_key']] = [
                'type' => 'faq', 'scope' => 'global', 'product_slug' => null,
                'question' => $faq['question'], 'answer' => $faq['answer'],
                'status' => 'published', 'sort_order' => (int) $faq['sort_order'],
            ];
        }

        foreach ($fixture['faqs']['service'] as $faq) {
            $managed[$faq['managed_key']] = [
                'type' => 'faq', 'scope' => 'service', 'product_slug' => $faq['product_slug'],
                'question' => $faq['question'], 'answer' => $faq['answer'],
                'status' => 'published', 'sort_order' => (int) $faq['sort_order'],
            ];
        }

        return ['site' => $site, 'platforms' => $platforms, 'services' => $services, 'managed' => $managed];
    }

    /** @param array<string, mixed> $fixture @param array<string, mixed> $stats */
    private function applyRemovals(array $fixture, array &$stats): void
    {
        foreach ($fixture['faq_removals'] as $removal) {
            $query = Faq::query()->whereNull('managed_key')
                ->where('scope', $removal['scope'])
                ->where('question', $removal['question']);

            if (($removal['product_slug'] ?? null) !== null) {
                $query->where('service_id', $this->serviceBySlug($removal['product_slug'])->id);
            }

            foreach ($query->get() as $row) {
                $row->delete();
                $stats['faq_removed']++;
            }
        }
    }

    /** @param array<string, mixed> $fixture @param array<string, mixed> $stats */
    private function applySite(array $fixture, array &$stats): void
    {
        $setting = SiteSetting::query()->first()
            ?? throw new RuntimeException('site_settings 不存在。');

        foreach ($this->expectedFromFixture($fixture)['site'] as $field => $value) {
            $this->setField($setting, $field, $value, $stats, 'site');
        }

        if ($setting->isDirty()) {
            $setting->save();
        }
    }

    /** @param array<string, mixed> $fixture @param array<string, mixed> $stats */
    private function applyPlatforms(array $fixture, array &$stats): void
    {
        foreach ($fixture['platforms'] as $slug => $values) {
            $platform = Platform::query()->where('slug', $slug)->first()
                ?? throw new RuntimeException("platform 不存在:{$slug}");

            foreach (self::PLATFORM_FIELDS as $field) {
                $this->setField($platform, $field, $values[$field] ?? null, $stats, 'platform');
            }

            if ($platform->isDirty()) {
                $platform->save();
            }
        }
    }

    /** @param array<string, mixed> $fixture @param array<string, mixed> $stats */
    private function applyServices(array $fixture, array &$stats): void
    {
        foreach ($fixture['services'] as $productSlug => $values) {
            $service = $this->serviceBySlug($productSlug);

            foreach (self::SERVICE_FIELDS as $field) {
                $this->setField($service, $field, $values[$field] ?? null, $stats, 'service');
            }

            if ($service->isDirty()) {
                $service->save();
            }
        }
    }

    /** @param array<string, mixed> $fixture @param array<string, mixed> $stats */
    private function applySections(array $fixture, array &$stats): void
    {
        foreach ($fixture['content_sections'] as $section) {
            $service = $this->serviceBySlug($section['product_slug']);

            $existing = ServiceContentSection::query()->where('managed_key', $section['managed_key'])->first();

            $attributes = [
                'service_id' => $service->id,
                'heading' => $section['heading'],
                'body' => $section['body'],
                'status' => 'published',
                'sort_order' => (int) $section['sort_order'],
            ];

            if ($existing === null) {
                ServiceContentSection::query()->create($attributes + ['managed_key' => $section['managed_key']]);
                $stats['sections_created']++;

                continue;
            }

            $existing->fill($attributes);

            if ($existing->isDirty()) {
                $existing->save();
                $stats['sections_updated']++;
            } else {
                $stats['sections_unchanged']++;
            }
        }
    }

    /** @param array<string, mixed> $fixture @param array<string, mixed> $stats */
    private function applyFaqs(array $fixture, array &$stats): void
    {
        $upsert = function (array $faq, string $scope, ?int $serviceId) use (&$stats): void {
            $existing = Faq::query()->where('managed_key', $faq['managed_key'])->first();

            $attributes = [
                'scope' => $scope,
                'platform_id' => null,
                'service_id' => $serviceId,
                'question' => $faq['question'],
                'answer' => $faq['answer'],
                'status' => 'published',
                'sort_order' => (int) $faq['sort_order'],
            ];

            if ($existing === null) {
                Faq::query()->create($attributes + ['managed_key' => $faq['managed_key']]);
                $stats['faq_created']++;

                return;
            }

            $existing->fill($attributes);

            if ($existing->isDirty()) {
                $existing->save();
                $stats['faq_updated']++;
            } else {
                $stats['faq_unchanged']++;
            }
        };

        foreach ($fixture['faqs']['global'] as $faq) {
            $upsert($faq, 'global', null);
        }

        foreach ($fixture['faqs']['service'] as $faq) {
            $upsert($faq, 'service', $this->serviceBySlug($faq['product_slug'])->id);
        }
    }

    private function setField(object $model, string $field, mixed $value, array &$stats, string $bucket): void
    {
        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException("R3 fixture 空值:{$bucket}.{$field}");
        }

        if ((string) $model->{$field} === $value) {
            $stats['unchanged_fields']++;

            return;
        }

        $model->{$field} = $value;
        $stats[$bucket]++;
    }

    // ------------------------------------------------------------------
    // Rollback(R1 封閉版)
    // ------------------------------------------------------------------

    private function rollbackFromSnapshot(string $requestedPath): int
    {
        $plan = $this->loadAndValidateSnapshot($requestedPath);

        $restored = ['fields' => 0, 'faq_restored' => 0, 'managed_deleted' => 0, 'managed_restored' => 0];

        DB::transaction(function () use ($plan, &$restored): void {
            // ⛔ 先驗 current==expected-applied;任何衝突 → 整批 0 writes。
            $this->assertNoConflicts($plan);

            $setting = SiteSetting::query()->first();

            foreach ($plan['before']['site'] as $field => $value) {
                if ($setting !== null && (string) $setting->{$field} !== (string) $value) {
                    $setting->{$field} = $value;
                    $restored['fields']++;
                }
            }
            $setting?->save();

            foreach ($plan['before']['platforms'] as $slug => $fields) {
                $platform = Platform::query()->where('slug', $slug)->first();

                foreach ($fields as $field => $value) {
                    if ($platform !== null && (string) $platform->{$field} !== (string) $value) {
                        $platform->{$field} = $value;
                        $restored['fields']++;
                    }
                }
                $platform?->save();
            }

            foreach ($plan['before']['services'] as $productSlug => $fields) {
                $service = Service::query()->where('product_slug', $productSlug)->first();

                foreach ($fields as $field => $value) {
                    if ($service !== null && (string) $service->{$field} !== (string) $value) {
                        $service->{$field} = $value;
                        $restored['fields']++;
                    }
                }
                $service?->save();
            }

            // 被移除 FAQ:restore+還原原值。
            foreach ($plan['removed_faqs'] as $row) {
                $faq = Faq::withTrashed()->find($row['id']);

                if ($faq !== null) {
                    $faq->restore();
                    $faq->forceFill(collect($row)->except('id')->all())->save();
                    $restored['faq_restored']++;
                }
            }

            // ⛔ 刪除只限 snapshot 明列的本次新建 exact keys。
            foreach ($plan['created_faq_keys'] as $key) {
                $faq = Faq::query()->where('managed_key', $key)->first();

                if ($faq !== null) {
                    $faq->forceDelete();
                    $restored['managed_deleted']++;
                }
            }

            foreach ($plan['created_section_keys'] as $key) {
                $section = ServiceContentSection::query()->where('managed_key', $key)->first();

                if ($section !== null) {
                    $section->forceDelete();
                    $restored['managed_deleted']++;
                }
            }

            // 快照前已存在的 managed 列 → 還原原值。
            foreach ($plan['managed_faqs_before'] as $row) {
                $faq = Faq::withTrashed()->find($row['id']);

                if ($faq !== null) {
                    $faq->restore();
                    $faq->forceFill(collect($row)->except('id')->all())->save();
                    $restored['managed_restored']++;
                }
            }

            foreach ($plan['managed_sections_before'] as $row) {
                $section = ServiceContentSection::withTrashed()->find($row['id']);

                if ($section !== null) {
                    $section->restore();
                    $section->forceFill(collect($row)->except('id')->all())->save();
                    $restored['managed_restored']++;
                }
            }
        });

        $this->info(sprintf(
            '[rollback] fields restored=%d;faq restored=%d;managed deleted=%d;managed restored=%d(來源:%s)',
            $restored['fields'], $restored['faq_restored'], $restored['managed_deleted'], $restored['managed_restored'], $plan['path'],
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

        $version = $snapshot['schema_version'] ?? null;

        if ($version === self::SNAPSHOT_SCHEMA_VERSION) {
            $plan = $this->validateV2Snapshot($snapshot);
        } elseif ($version === null && array_keys($snapshot) === self::LEGACY_V1_KEYS) {
            // 嚴格 legacy-v1 parser:expected 取自現行 R3 fixture exact 值。
            $plan = $this->validateLegacyV1Snapshot($snapshot);
        } else {
            throw new RuntimeException('未知 snapshot schema。');
        }

        $plan['path'] = $real;

        return $plan;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function validateV2Snapshot(array $snapshot): array
    {
        $allowedTop = ['schema_version', 'created_at', 'fixture', 'fixture_sha256', 'site', 'platforms', 'services', 'expected', 'managed_faqs_before', 'managed_sections_before', 'created_faq_keys', 'created_section_keys', 'removed_faqs'];

        foreach (array_keys($snapshot) as $key) {
            if (! in_array($key, $allowedTop, true)) {
                throw new RuntimeException("快照含未知 top-level key:{$key}");
            }
        }

        foreach ($allowedTop as $key) {
            if (! array_key_exists($key, $snapshot)) {
                throw new RuntimeException("快照缺少 key:{$key}");
            }
        }

        $before = [
            'site' => $this->allowlistFields((array) $snapshot['site'], array_merge(self::SITE_FIELDS, self::HIGHLIGHT_FIELDS), 'site'),
            'platforms' => $this->allowlistSlugged((array) $snapshot['platforms'], self::PLATFORM_SLUGS, self::PLATFORM_FIELDS, 'platform'),
            'services' => $this->allowlistSlugged((array) $snapshot['services'], array_values(ProductSlugMap::MAP), self::SERVICE_FIELDS, 'service'),
        ];

        $expected = [
            'site' => $this->allowlistFields((array) ($snapshot['expected']['site'] ?? []), array_merge(self::SITE_FIELDS, self::HIGHLIGHT_FIELDS), 'expected.site'),
            'platforms' => $this->allowlistSlugged((array) ($snapshot['expected']['platforms'] ?? []), self::PLATFORM_SLUGS, self::PLATFORM_FIELDS, 'expected.platform'),
            'services' => $this->allowlistSlugged((array) ($snapshot['expected']['services'] ?? []), array_values(ProductSlugMap::MAP), self::SERVICE_FIELDS, 'expected.service'),
            'managed' => (array) ($snapshot['expected']['managed'] ?? []),
        ];

        $createdFaqKeys = array_values((array) $snapshot['created_faq_keys']);
        $createdSectionKeys = array_values((array) $snapshot['created_section_keys']);

        $allKeys = array_merge(
            $createdFaqKeys,
            $createdSectionKeys,
            array_column((array) $snapshot['managed_faqs_before'], 'managed_key'),
            array_column((array) $snapshot['managed_sections_before'], 'managed_key'),
        );

        if (count($allKeys) !== count(array_unique($allKeys))) {
            throw new RuntimeException('快照 managed key 重複。');
        }

        $ids = array_merge(
            array_column((array) $snapshot['managed_faqs_before'], 'id'),
            array_column((array) $snapshot['removed_faqs'], 'id'),
        );

        if (count($ids) !== count(array_unique($ids))) {
            throw new RuntimeException('快照 FAQ id 重複。');
        }

        return [
            'before' => $before,
            'expected' => $expected,
            'created_faq_keys' => $createdFaqKeys,
            'created_section_keys' => $createdSectionKeys,
            'managed_faqs_before' => $this->rows((array) $snapshot['managed_faqs_before'], self::FAQ_ROW_FIELDS, 'managed_faqs_before'),
            'managed_sections_before' => $this->rows((array) $snapshot['managed_sections_before'], self::SECTION_ROW_FIELDS, 'managed_sections_before'),
            'removed_faqs' => $this->rows((array) $snapshot['removed_faqs'], self::FAQ_ROW_FIELDS, 'removed_faqs'),
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function validateLegacyV1Snapshot(array $snapshot): array
    {
        // v1 沒有 expected/created keys:以現行 R3 fixture exact 值閉合。
        $fixture = $this->loadFixture();
        $expected = $this->expectedFromFixture($fixture);

        $before = [
            'site' => $this->allowlistFields((array) $snapshot['site'], array_merge(self::SITE_FIELDS, self::HIGHLIGHT_FIELDS), 'site'),
            'platforms' => $this->allowlistSlugged((array) $snapshot['platforms'], self::PLATFORM_SLUGS, self::PLATFORM_FIELDS, 'platform'),
            'services' => $this->allowlistSlugged((array) $snapshot['services'], array_values(ProductSlugMap::MAP), self::SERVICE_FIELDS, 'service'),
        ];

        $beforeFaqKeys = array_column((array) $snapshot['managed_faqs_before'], 'managed_key');
        $beforeSectionKeys = array_column((array) $snapshot['managed_sections_before'], 'managed_key');

        $fixtureFaqKeys = array_merge(
            array_column($fixture['faqs']['global'], 'managed_key'),
            array_column($fixture['faqs']['service'], 'managed_key'),
        );
        $fixtureSectionKeys = array_column($fixture['content_sections'], 'managed_key');

        return [
            'before' => $before,
            'expected' => $expected,
            'created_faq_keys' => array_values(array_diff($fixtureFaqKeys, $beforeFaqKeys)),
            'created_section_keys' => array_values(array_diff($fixtureSectionKeys, $beforeSectionKeys)),
            'managed_faqs_before' => $this->rows((array) $snapshot['managed_faqs_before'], self::FAQ_ROW_FIELDS, 'managed_faqs_before'),
            'managed_sections_before' => $this->rows((array) $snapshot['managed_sections_before'], self::SECTION_ROW_FIELDS, 'managed_sections_before'),
            'removed_faqs' => $this->rows((array) $snapshot['removed_faqs'], self::FAQ_ROW_FIELDS, 'removed_faqs'),
        ];
    }

    /**
     * ⛔ current state 必須等於 expected-applied state,否則整批停止。
     * conflict identifier 只含欄位/key 識別,不含任何文案內容。
     *
     * @param  array<string, mixed>  $plan
     */
    private function assertNoConflicts(array $plan): void
    {
        $conflicts = [];

        $setting = SiteSetting::query()->first();

        foreach ($plan['expected']['site'] as $field => $value) {
            if ($setting === null || (string) $setting->{$field} !== (string) $value) {
                $conflicts[] = 'site.'.$field;
            }
        }

        foreach ($plan['expected']['platforms'] as $slug => $fields) {
            $platform = Platform::query()->where('slug', $slug)->first();

            foreach ($fields as $field => $value) {
                if ($platform === null || (string) $platform->{$field} !== (string) $value) {
                    $conflicts[] = 'platform.'.$slug.'.'.$field;
                }
            }
        }

        foreach ($plan['expected']['services'] as $slug => $fields) {
            $service = Service::query()->where('product_slug', $slug)->first();

            foreach ($fields as $field => $value) {
                if ($service === null || (string) $service->{$field} !== (string) $value) {
                    $conflicts[] = 'service.'.$slug.'.'.$field;
                }
            }
        }

        foreach ($plan['expected']['managed'] as $key => $expected) {
            if (($expected['type'] ?? null) === 'section') {
                $row = ServiceContentSection::query()->where('managed_key', $key)->first();

                if ($row === null
                    || (string) $row->heading !== (string) $expected['heading']
                    || (string) $row->body !== (string) $expected['body']
                    || (string) $row->status !== (string) $expected['status']) {
                    $conflicts[] = 'section.'.$key;
                }

                continue;
            }

            $row = Faq::query()->where('managed_key', $key)->first();

            if ($row === null
                || (string) $row->question !== (string) $expected['question']
                || (string) $row->answer !== (string) $expected['answer']
                || (string) $row->status !== (string) $expected['status']) {
                $conflicts[] = 'faq.'.$key;
            }
        }

        // 被移除列必須仍處於移除狀態(被手動復原=狀態已被人為改動)。
        foreach ($plan['removed_faqs'] as $row) {
            $faq = Faq::withTrashed()->find($row['id']);

            if ($faq === null || $faq->deleted_at === null) {
                $conflicts[] = 'removed-faq.'.$row['id'];
            }
        }

        if ($conflicts !== []) {
            throw new RuntimeException('R3-ROLLBACK-CONFLICT:'.implode(',', $conflicts));
        }
    }

    /**
     * @param  array<string, mixed>  $fields
     * @param  list<string>  $allowed
     * @return array<string, mixed>
     */
    private function allowlistFields(array $fields, array $allowed, string $where): array
    {
        foreach (array_keys($fields) as $field) {
            if (! in_array($field, $allowed, true)) {
                throw new RuntimeException("快照含未知欄位:{$where}.{$field}");
            }
        }

        return $fields;
    }

    /**
     * @param  array<string, mixed>  $slugged
     * @param  list<string>  $allowedSlugs
     * @param  list<string>  $allowedFields
     * @return array<string, array<string, mixed>>
     */
    private function allowlistSlugged(array $slugged, array $allowedSlugs, array $allowedFields, string $where): array
    {
        foreach ($slugged as $slug => $fields) {
            if (! in_array($slug, $allowedSlugs, true)) {
                throw new RuntimeException("快照含未知 slug:{$where}.{$slug}");
            }

            $this->allowlistFields((array) $fields, $allowedFields, $where.'.'.$slug);
        }

        return $slugged;
    }

    /**
     * @param  array<int, mixed>  $rows
     * @param  list<string>  $allowedFields
     * @return array<int, array<string, mixed>>
     */
    private function rows(array $rows, array $allowedFields, string $where): array
    {
        foreach ($rows as $row) {
            $this->allowlistFields((array) $row, $allowedFields, $where);
        }

        return $rows;
    }
}
