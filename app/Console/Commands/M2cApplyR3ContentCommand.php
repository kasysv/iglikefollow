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
 * ⛔ 只碰公開內容欄位:site settings(含 highlights)、3 platforms
 * (h1/tagline/intro/seo)、9 services(seo/h1/summary/intro/card_title/
 * card_blurb/cta_label)、managed content sections 與 managed FAQ,及
 * fixture 明列之受控 FAQ 移除(exact scope+question+managed_key IS NULL,
 * ⛔ 無模糊 LIKE)。價格/數量/SKU/status/mapping/orders/credential 不碰。
 *
 * - `--dry-run`:完整驗證+試算後 transaction rollback,DB 0 writes,
 *   也不建立 snapshot 檔。
 * - apply:寫入前自動存公開內容快照(只含本輪將修改欄位與 FAQ/段落列,
 *   無 credential/orders/PII/價格/mapping)。
 * - `--rollback=<snapshot>`:只還原該快照記錄的欄位、restore 被移除的
 *   FAQ、forceDelete 本輪新建的 managed 列;其餘後台資料不碰。
 * - idempotent:managed_key 為穩定識別,同 fixture 重跑 0 新增。
 */
class M2cApplyR3ContentCommand extends Command
{
    protected $signature = 'm2c:apply-r3 {--dry-run : 驗證並試算,不寫入} {--rollback= : 從指定快照檔還原本輪欄位}';

    protected $description = 'M2-C R3:套用 13 頁完整 SEO 內容(transactional;支援 dry-run 與快照 rollback)';

    private const SITE_FIELDS = ['seo_title', 'meta_description', 'home_h1', 'home_intro', 'primary_cta_label'];

    private const PLATFORM_FIELDS = ['h1', 'tagline', 'intro', 'seo_title', 'meta_description'];

    private const SERVICE_FIELDS = ['seo_title', 'meta_description', 'h1', 'summary', 'intro', 'card_title', 'card_blurb', 'cta_label'];

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

        // ⛔ 未知 product slug fail closed(必須在 D-103 固定 mapping 內)。
        $known = array_values(ProductSlugMap::MAP);

        foreach (array_keys($fixture['services']) as $slug) {
            if (! in_array($slug, $known, true)) {
                throw new RuntimeException("R3 fixture 有未知 product slug:{$slug}");
            }
        }

        if (count($fixture['services']) !== 9) {
            throw new RuntimeException('R3 fixture 服務數不是 9。');
        }

        $keys = array_merge(
            array_column($fixture['content_sections'], 'managed_key'),
            array_column($fixture['faqs']['global'], 'managed_key'),
            array_column($fixture['faqs']['service'], 'managed_key'),
        );

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

    private function serviceBySlug(string $productSlug): Service
    {
        return Service::query()->where('product_slug', $productSlug)->first()
            ?? throw new RuntimeException("service 不存在(product_slug):{$productSlug}");
    }

    /**
     * 公開內容快照:只含本輪將修改的欄位與列。
     *
     * @param  array<string, mixed>  $fixture
     */
    private function writeSnapshot(array $fixture): string
    {
        $managedKeys = array_merge(
            array_column($fixture['content_sections'], 'managed_key'),
            array_column($fixture['faqs']['global'], 'managed_key'),
            array_column($fixture['faqs']['service'], 'managed_key'),
        );

        $faqRowFields = ['id', 'scope', 'platform_id', 'service_id', 'question', 'answer', 'status', 'sort_order', 'managed_key'];
        $sectionRowFields = ['id', 'service_id', 'heading', 'body', 'status', 'sort_order', 'managed_key'];

        $removalRows = [];

        foreach ($fixture['faq_removals'] as $removal) {
            $query = Faq::query()->whereNull('managed_key')
                ->where('scope', $removal['scope'])
                ->where('question', $removal['question']);

            if (($removal['product_slug'] ?? null) !== null) {
                $query->where('service_id', $this->serviceBySlug($removal['product_slug'])->id);
            }

            foreach ($query->get() as $row) {
                $removalRows[] = $row->only($faqRowFields);
            }
        }

        $snapshot = [
            'created_at' => now()->toIso8601String(),
            'fixture' => 'database/fixtures/m2c-r3-content.json',
            'site' => SiteSetting::query()->first()?->only(array_merge(
                self::SITE_FIELDS,
                ['home_highlight_1_title', 'home_highlight_1_body', 'home_highlight_2_title', 'home_highlight_2_body', 'home_highlight_3_title', 'home_highlight_3_body'],
            )),
            'platforms' => Platform::query()->whereIn('slug', array_keys($fixture['platforms']))->get()
                ->mapWithKeys(fn (Platform $p) => [$p->slug => $p->only(self::PLATFORM_FIELDS)])->all(),
            'services' => Service::query()->whereIn('product_slug', array_keys($fixture['services']))->get()
                ->mapWithKeys(fn (Service $s) => [$s->product_slug => $s->only(self::SERVICE_FIELDS)])->all(),
            'managed_faqs_before' => Faq::query()->whereIn('managed_key', $managedKeys)->get()
                ->map(fn (Faq $f) => $f->only($faqRowFields))->all(),
            'managed_sections_before' => ServiceContentSection::query()->whereIn('managed_key', $managedKeys)->get()
                ->map(fn (ServiceContentSection $s) => $s->only($sectionRowFields))->all(),
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

    /** @param array<string, mixed> $fixture @param array<string, mixed> $stats */
    private function applyRemovals(array $fixture, array &$stats): void
    {
        foreach ($fixture['faq_removals'] as $removal) {
            // ⛔ exact scope+question,且只刪未受管(managed_key IS NULL)列。
            $query = Faq::query()->whereNull('managed_key')
                ->where('scope', $removal['scope'])
                ->where('question', $removal['question']);

            if (($removal['product_slug'] ?? null) !== null) {
                $query->where('service_id', $this->serviceBySlug($removal['product_slug'])->id);
            }

            foreach ($query->get() as $row) {
                $row->delete(); // SoftDeletes:rollback 可 restore。
                $stats['faq_removed']++;
            }
        }
    }

    /** @param array<string, mixed> $fixture @param array<string, mixed> $stats */
    private function applySite(array $fixture, array &$stats): void
    {
        $setting = SiteSetting::query()->first()
            ?? throw new RuntimeException('site_settings 不存在。');

        $values = $fixture['site'];

        foreach (self::SITE_FIELDS as $field) {
            $this->setField($setting, $field, $values[$field] ?? null, $stats, 'site');
        }

        foreach ($values['highlights'] as $i => $highlight) {
            $n = $i + 1;
            $this->setField($setting, "home_highlight_{$n}_title", $highlight['title'] ?? null, $stats, 'site');
            $this->setField($setting, "home_highlight_{$n}_body", $highlight['body'] ?? null, $stats, 'site');
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

    private function rollbackFromSnapshot(string $path): int
    {
        $snapshot = json_decode((string) file_get_contents($path), true);

        if (! is_array($snapshot) || ! isset($snapshot['fixture'])) {
            throw new RuntimeException('快照檔缺失或無法解析。');
        }

        $restored = ['fields' => 0, 'faq_restored' => 0, 'managed_deleted' => 0, 'managed_restored' => 0];

        DB::transaction(function () use ($snapshot, &$restored): void {
            // 1) 欄位還原(site/platform/service 只還原快照記錄的欄位)。
            $setting = SiteSetting::query()->first();

            foreach (($snapshot['site'] ?? []) as $field => $value) {
                if ($setting !== null && (string) $setting->{$field} !== (string) $value) {
                    $setting->{$field} = $value;
                    $restored['fields']++;
                }
            }
            $setting?->save();

            foreach (($snapshot['platforms'] ?? []) as $slug => $fields) {
                $platform = Platform::query()->where('slug', $slug)->first();

                foreach ($fields as $field => $value) {
                    if ($platform !== null && (string) $platform->{$field} !== (string) $value) {
                        $platform->{$field} = $value;
                        $restored['fields']++;
                    }
                }
                $platform?->save();
            }

            foreach (($snapshot['services'] ?? []) as $productSlug => $fields) {
                $service = Service::query()->where('product_slug', $productSlug)->first();

                foreach ($fields as $field => $value) {
                    if ($service !== null && (string) $service->{$field} !== (string) $value) {
                        $service->{$field} = $value;
                        $restored['fields']++;
                    }
                }
                $service?->save();
            }

            // 2) 被移除的 FAQ:restore+還原原值。
            foreach (($snapshot['removed_faqs'] ?? []) as $row) {
                $faq = Faq::withTrashed()->find($row['id']);

                if ($faq !== null) {
                    $faq->restore();
                    $faq->forceFill(collect($row)->except('id')->all())->save();
                    $restored['faq_restored']++;
                }
            }

            // 3) managed 列:快照前不存在 → forceDelete;存在 → 還原原值。
            $beforeFaqKeys = array_column($snapshot['managed_faqs_before'] ?? [], 'managed_key');
            $beforeSectionKeys = array_column($snapshot['managed_sections_before'] ?? [], 'managed_key');

            foreach (Faq::query()->whereNotNull('managed_key')->where('managed_key', 'like', 'r3.%')->get() as $faq) {
                if (! in_array($faq->managed_key, $beforeFaqKeys, true)) {
                    $faq->forceDelete();
                    $restored['managed_deleted']++;
                }
            }

            foreach (($snapshot['managed_faqs_before'] ?? []) as $row) {
                $faq = Faq::withTrashed()->find($row['id']);

                if ($faq !== null) {
                    $faq->restore();
                    $faq->forceFill(collect($row)->except('id')->all())->save();
                    $restored['managed_restored']++;
                }
            }

            foreach (ServiceContentSection::query()->whereNotNull('managed_key')->where('managed_key', 'like', 'r3.%')->get() as $section) {
                if (! in_array($section->managed_key, $beforeSectionKeys, true)) {
                    $section->forceDelete();
                    $restored['managed_deleted']++;
                }
            }

            foreach (($snapshot['managed_sections_before'] ?? []) as $row) {
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
            $restored['fields'], $restored['faq_restored'], $restored['managed_deleted'], $restored['managed_restored'], $path,
        ));

        return self::SUCCESS;
    }
}
