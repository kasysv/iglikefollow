<?php

namespace App\Console\Commands;

use App\Models\Faq;
use App\Models\Platform;
use App\Models\Service;
use App\Models\SiteSetting;
use App\Support\ProductSlugMap;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * M2-C:把已 ACCEPT 的 81 筆公開文案填入本機後台資料,並依 D-103 指派
 * 9 個 `/product/` product slug。
 *
 * ⛔ 來源固定為 repo 內 versioned fixture(只含公開欄位;由
 * `90-Data/SEO/2026-08-19-publishable-copy-draft.json` 逐字產生)。
 * 只碰 site setting、3 platforms、9 services 的指定欄位、IG followers
 * 等級敘述與 3 組 FAQ;價格、數量、SKU、status、mapping、履約設定
 * 一律不動。任何 mapping 缺漏/重複/狀態異常 → 整批 rollback。
 *
 * `--dry-run`:完整執行驗證與寫入計算後 rollback,0 writes;
 * 重跑 idempotent:第二次 apply 不新增 FAQ、不改動任何欄位。
 */
class M2cApplyCopyCommand extends Command
{
    protected $signature = 'm2c:apply-copy {--dry-run : 驗證並試算,不寫入}';

    protected $description = 'M2-C:套用 81 筆公開文案與 D-103 product slugs(transactional,支援 --dry-run)';

    private const TIER_TOKENS = [
        'tier.real' => '真人',
        'tier.top' => '頂級',
        'tier.advanced' => '高級',
        'tier.standard' => '普通',
    ];

    private const SITE_FIELDS = [
        'home.seo_title' => 'seo_title',
        'home.meta_description' => 'meta_description',
        'home.home_h1' => 'home_h1',
        'home.home_intro' => 'home_intro',
        'home.primary_cta_label' => 'primary_cta_label',
    ];

    private const PLATFORM_FIELDS = ['seo_title', 'meta_description', 'tagline', 'intro'];

    private const SERVICE_FIELDS = ['seo_title', 'meta_description', 'h1', 'summary', 'intro', 'cta_label'];

    public function handle(): int
    {
        $records = $this->loadFixture();
        $dry = (bool) $this->option('dry-run');

        $stats = [
            'slug_set' => 0, 'slug_unchanged' => 0,
            'site' => 0, 'platform' => 0, 'service' => 0,
            'tier_variants' => 0, 'tier_skipped' => [],
            'faq_created' => 0, 'faq_updated' => 0, 'faq_unchanged' => 0,
            'unchanged_fields' => 0,
        ];

        try {
            DB::transaction(function () use ($records, $dry, &$stats): void {
                $this->applyProductSlugs($stats);
                $this->applySiteSettings($records, $stats);
                $this->applyPlatforms($records, $stats);
                $this->applyServices($records, $stats);
                $this->applyTierDescriptions($records, $stats);
                $this->applyFaqs($records, $stats);

                if ($dry) {
                    // ⛔ dry-run:一切驗證照跑,最後整批回滾=0 writes。
                    throw new RuntimeException('__M2C_DRY_RUN_ROLLBACK__');
                }
            });
        } catch (RuntimeException $e) {
            if ($e->getMessage() !== '__M2C_DRY_RUN_ROLLBACK__') {
                $this->error('M2-C apply 失敗,整批 rollback:'.$e->getMessage());

                return self::FAILURE;
            }
        }

        $mode = $dry ? '[dry-run,已 rollback,0 writes]' : '[applied]';
        $this->info(sprintf(
            '%s slug set=%d unchanged=%d;site=%d platform=%d service=%d;tier variants=%d skipped=%s;faq created=%d updated=%d unchanged=%d;fields already equal=%d',
            $mode,
            $stats['slug_set'], $stats['slug_unchanged'],
            $stats['site'], $stats['platform'], $stats['service'],
            $stats['tier_variants'], json_encode($stats['tier_skipped'], JSON_UNESCAPED_UNICODE),
            $stats['faq_created'], $stats['faq_updated'], $stats['faq_unchanged'],
            $stats['unchanged_fields'],
        ));

        return self::SUCCESS;
    }

    /** @return array<int, array{record_type: string, record_key: string, field_name: string, draft_value: string}> */
    private function loadFixture(): array
    {
        $path = database_path('fixtures/m2c-publishable-copy.json');
        $records = json_decode((string) file_get_contents($path), true);

        if (! is_array($records) || count($records) !== 81) {
            throw new RuntimeException('fixture 缺失或筆數不是 81。');
        }

        $keys = array_column($records, 'record_key');

        if (count($keys) !== count(array_unique($keys))) {
            throw new RuntimeException('fixture record_key 重複。');
        }

        foreach ($records as $r) {
            if (! is_string($r['draft_value'] ?? null) || trim($r['draft_value']) === '') {
                throw new RuntimeException('fixture 有空白 draft_value:'.($r['record_key'] ?? '?'));
            }
        }

        return $records;
    }

    /** @param array<string, mixed> $stats */
    private function applyProductSlugs(array &$stats): void
    {
        $slugs = array_values(ProductSlugMap::MAP);

        if (count($slugs) !== count(array_unique($slugs))) {
            throw new RuntimeException('product slug mapping 自身重複。');
        }

        foreach (ProductSlugMap::MAP as $key => $slug) {
            [$platformSlug, $serviceSlug] = explode('/', $key, 2);

            if (! Service::isValidProductSlug($slug)) {
                throw new RuntimeException("product slug 不合 allowlist:{$key}");
            }

            $service = $this->service($platformSlug, $serviceSlug);

            if ($service->status !== 'published') {
                throw new RuntimeException("service 狀態非 published:{$key}");
            }

            // ⛔ 碰撞:同 slug 已被其他 service 佔用 → 整批停止。
            $holder = Service::query()->where('product_slug', $slug)
                ->where('id', '!=', $service->id)->first();

            if ($holder !== null) {
                throw new RuntimeException("product slug 已被占用:{$slug}");
            }

            if ($service->product_slug === $slug) {
                $stats['slug_unchanged']++;

                continue;
            }

            if (filled($service->product_slug)) {
                throw new RuntimeException("service 已有不同 product slug,不覆寫:{$key}");
            }

            $service->product_slug = $slug;
            $service->save();
            $stats['slug_set']++;
        }
    }

    /** @param array<int, array<string, string>> $records @param array<string, mixed> $stats */
    private function applySiteSettings(array $records, array &$stats): void
    {
        $setting = SiteSetting::query()->first();

        if ($setting === null) {
            throw new RuntimeException('site_settings 不存在,無法填入首頁欄位。');
        }

        foreach (self::SITE_FIELDS as $key => $column) {
            $value = $this->value($records, $key);

            if ((string) $setting->{$column} === $value) {
                $stats['unchanged_fields']++;

                continue;
            }

            $setting->{$column} = $value;
            $stats['site']++;
        }

        if ($setting->isDirty()) {
            $setting->save();
        }
    }

    /** @param array<int, array<string, string>> $records @param array<string, mixed> $stats */
    private function applyPlatforms(array $records, array &$stats): void
    {
        foreach (['instagram', 'facebook', 'threads'] as $slug) {
            $platform = Platform::query()->where('slug', $slug)->first()
                ?? throw new RuntimeException("platform 不存在:{$slug}");

            foreach (self::PLATFORM_FIELDS as $field) {
                $value = $this->value($records, "{$slug}.{$field}");

                if ((string) $platform->{$field} === $value) {
                    $stats['unchanged_fields']++;

                    continue;
                }

                $platform->{$field} = $value;
                $stats['platform']++;
            }

            if ($platform->isDirty()) {
                $platform->save();
            }
        }
    }

    /** @param array<int, array<string, string>> $records @param array<string, mixed> $stats */
    private function applyServices(array $records, array &$stats): void
    {
        foreach (array_keys(ProductSlugMap::MAP) as $key) {
            [$platformSlug, $serviceSlug] = explode('/', $key, 2);
            $service = $this->service($platformSlug, $serviceSlug);
            $recordPrefix = "{$platformSlug}-{$serviceSlug}";

            foreach (self::SERVICE_FIELDS as $field) {
                $value = $this->value($records, "{$recordPrefix}.{$field}");

                if ((string) $service->{$field} === $value) {
                    $stats['unchanged_fields']++;

                    continue;
                }

                $service->{$field} = $value;
                $stats['service']++;
            }

            if ($service->isDirty()) {
                $service->save();
            }
        }
    }

    /**
     * ⛔ 只更新 instagram/followers 下、label 明確含對應 token 的既有
     * variant;「一般/台灣/華人」不得自行視為普通。0 或多重不明確匹配
     * → skipped 並回報,不猜。
     *
     * @param  array<int, array<string, string>>  $records  @param array<string, mixed> $stats
     */
    private function applyTierDescriptions(array $records, array &$stats): void
    {
        $service = $this->service('instagram', 'followers');
        $variants = $service->variants()->get();

        foreach (self::TIER_TOKENS as $key => $token) {
            $text = $this->value($records, $key);

            $matches = $variants->filter(function ($variant) use ($token): bool {
                $label = (string) $variant->label;
                $hits = 0;

                foreach (self::TIER_TOKENS as $t) {
                    if (str_contains($label, $t)) {
                        $hits++;
                    }
                }

                // 明確=只含這一個 tier token;含多個 token 視為不明確。
                return $hits === 1 && str_contains($label, $token);
            });

            if ($matches->isEmpty()) {
                $stats['tier_skipped'][] = $token.'(0 個明確匹配)';

                continue;
            }

            foreach ($matches as $variant) {
                if ((string) $variant->description === $text) {
                    $stats['unchanged_fields']++;

                    continue;
                }

                $variant->description = $text;
                $variant->save();
                $stats['tier_variants']++;
            }
        }
    }

    /** @param array<int, array<string, string>> $records @param array<string, mixed> $stats */
    private function applyFaqs(array $records, array &$stats): void
    {
        $service = $this->service('instagram', 'followers');
        $sort = (int) Faq::query()->where('scope', 'service')
            ->where('service_id', $service->id)->max('sort_order');

        foreach (['account-lock', 'tiers', 'password'] as $group) {
            $question = $this->value($records, "faq.{$group}.question");
            $answer = $this->value($records, "faq.{$group}.answer");

            // idempotent key=完整 question(同 scope+service)。
            $existing = Faq::query()
                ->where('scope', 'service')
                ->where('service_id', $service->id)
                ->where('question', $question)
                ->first();

            if ($existing !== null) {
                if ((string) $existing->answer === $answer) {
                    $stats['faq_unchanged']++;

                    continue;
                }

                $existing->answer = $answer;
                $existing->save();
                $stats['faq_updated']++;

                continue;
            }

            Faq::query()->create([
                'scope' => 'service',
                'platform_id' => null,
                'service_id' => $service->id,
                'question' => $question,
                'answer' => $answer,
                'status' => 'published',
                'sort_order' => ++$sort,
            ]);
            $stats['faq_created']++;
        }
    }

    private function service(string $platformSlug, string $serviceSlug): Service
    {
        $platform = Platform::query()->where('slug', $platformSlug)->first()
            ?? throw new RuntimeException("platform 不存在:{$platformSlug}");

        return Service::query()
            ->where('platform_id', $platform->id)
            ->where('slug', $serviceSlug)
            ->first()
            ?? throw new RuntimeException("service 不存在:{$platformSlug}/{$serviceSlug}");
    }

    /** @param array<int, array<string, string>> $records */
    private function value(array $records, string $key): string
    {
        foreach ($records as $r) {
            if ($r['record_key'] === $key) {
                return $r['draft_value'];
            }
        }

        throw new RuntimeException("fixture 缺少 record:{$key}");
    }
}
