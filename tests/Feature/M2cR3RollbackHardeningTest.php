<?php

namespace Tests\Feature;

use App\Models\Faq;
use App\Models\Platform;
use App\Models\Service;
use App\Models\ServiceContentSection;
use App\Models\SiteSetting;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\SeedsThreadsCatalog;
use Tests\TestCase;

/**
 * M2-C R3-R1:snapshot/rollback 封閉與未授權宣稱反證。
 *
 * ⛔ rollback 只能:走允許目錄內的實檔、只認 allowlist 欄位、只刪
 * snapshot 明列的本次新建 exact keys,且 current≠expected 時整批 0 writes
 * 並輸出固定 conflict identifier(不含文案)。
 */
class M2cR3RollbackHardeningTest extends TestCase
{
    use RefreshDatabase;
    use SeedsThreadsCatalog;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $this->seed(CatalogSeeder::class);
        $this->seedThreadsCatalog();
        Artisan::call('m2c:apply-copy');
    }

    private function applyAndGetSnapshot(): string
    {
        $this->assertSame(0, Artisan::call('m2c:apply-r3'));
        preg_match('/snapshot=(\S+\.json)/u', Artisan::output(), $m);
        $this->assertNotEmpty($m[1] ?? '');

        return $m[1];
    }

    /** @return array<string, mixed> */
    private function snapshotDirGlobState(): array
    {
        return [
            'faqs' => Faq::withTrashed()->orderBy('id')->get(['id', 'question', 'answer', 'deleted_at', 'managed_key'])->toArray(),
            'sections' => ServiceContentSection::query()->orderBy('id')->get(['id', 'heading', 'managed_key'])->toArray(),
            'site' => collect((array) DB::table('site_settings')->first())->except(['created_at', 'updated_at'])->all(),
        ];
    }

    public function test_fixture_and_pages_carry_no_unauthorised_claims_and_safe_highlights(): void
    {
        $fixtureRaw = (string) file_get_contents(database_path('fixtures/m2c-r3-content.json'));

        // ⛔ 未授權商業宣稱在 fixture 0 命中。
        $this->assertStringNotContainsString('十年', $fixtureRaw);
        $this->assertStringNotContainsString('獨家', $fixtureRaw);

        // 三項 safe highlights(R4 Owner 核准版)逐字存在於 fixture。
        foreach (['多種台灣方案', '普通、頂級、真人，部分方案可指定男女', '不需帳號密碼', '只需公開帳號、貼文或影片網址', '自動處理與電子發票', '付款成功後自動處理，可填個人或公司電子發票'] as $text) {
            $this->assertStringContainsString($text, $fixtureRaw);
        }

        Artisan::call('m2c:apply-r3');

        // 13 頁 HTML 0 命中未授權宣稱;首頁三項 safe highlights(R4 版)逐字可見。
        $home = $this->get('/')->assertOk()->getContent();

        foreach (['多種台灣方案', '普通、頂級、真人，部分方案可指定男女', '不需帳號密碼', '只需公開帳號、貼文或影片網址', '自動處理與電子發票', '付款成功後自動處理，可填個人或公司電子發票'] as $text) {
            $this->assertStringContainsString($text, $home);
        }

        $urls = array_merge(
            ['/', '/services/instagram', '/services/facebook', '/services/threads'],
            Service::query()->whereNotNull('product_slug')->pluck('product_slug')
                ->map(fn (string $slug) => '/product/'.$slug.'/')->all(),
        );

        foreach ($urls as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            $this->assertStringNotContainsString('十年', $html, $url);
            $this->assertStringNotContainsString('獨家', $html, $url);
        }
    }

    public function test_rollback_never_touches_managed_rows_outside_the_snapshot(): void
    {
        $snapshot = $this->applyAndGetSnapshot();

        // snapshot 之後另外新增的 r3.future.* 列(模擬後續輪次)。
        $service = Service::query()->where('product_slug', 'ig買粉絲')->firstOrFail();

        Faq::query()->create([
            'scope' => 'service', 'service_id' => $service->id,
            'question' => '未來輪次問題?', 'answer' => '未來輪次答案。',
            'status' => 'published', 'sort_order' => 99, 'managed_key' => 'r3.future.faq',
        ]);
        ServiceContentSection::query()->create([
            'service_id' => $service->id, 'heading' => '未來輪次段落', 'body' => '未來輪次內容。',
            'status' => 'published', 'sort_order' => 99, 'managed_key' => 'r3.future.section',
        ]);

        $this->assertSame(0, Artisan::call('m2c:apply-r3', ['--rollback' => $snapshot]));

        // ⛔ 不在該 snapshot 內的 managed 列必須原封不動(舊版 LIKE r3.% 會誤刪)。
        $this->assertDatabaseHas('faqs', ['managed_key' => 'r3.future.faq', 'deleted_at' => null]);
        $this->assertDatabaseHas('service_content_sections', ['managed_key' => 'r3.future.section', 'deleted_at' => null]);

        // 本輪列已被精確移除。
        $this->assertSame(0, Faq::query()->where('managed_key', 'like', 'r3.global.%')->count());
        $this->assertSame(0, ServiceContentSection::query()->where('managed_key', 'r3.ig買粉絲.how-to-choose')->count());
    }

    public function test_crafted_snapshot_with_unknown_fields_or_slugs_fails_closed(): void
    {
        $snapshot = $this->applyAndGetSnapshot();
        $data = json_decode((string) file_get_contents($snapshot), true);
        $dir = storage_path('app/private/m2c-snapshots');

        $cases = [];

        // (a) 未知 site 欄位。
        $bad = $data;
        $bad['site']['evil_column'] = 'x';
        $cases['unknown-site-field'] = $bad;

        // (b) 未知 service slug。
        $bad = $data;
        $bad['services']['不存在slug'] = ['seo_title' => 'x'];
        $cases['unknown-service-slug'] = $bad;

        // (c) 未知 top-level key。
        $bad = $data;
        $bad['evil_top'] = [];
        $cases['unknown-top-key'] = $bad;

        // (d) service 欄位越界(指示寫入價格欄)。
        $bad = $data;
        $bad['services']['ig買粉絲']['unit_price'] = '999';
        $cases['field-outside-allowlist'] = $bad;

        foreach ($cases as $name => $payload) {
            $path = $dir.'/crafted-'.$name.'.json';
            file_put_contents($path, json_encode($payload, JSON_UNESCAPED_UNICODE));

            $before = $this->snapshotDirGlobState();
            $this->assertSame(1, Artisan::call('m2c:apply-r3', ['--rollback' => $path]), $name);
            $this->assertEquals($before, $this->snapshotDirGlobState(), $name);

            unlink($path);
        }
    }

    public function test_snapshot_path_escapes_and_bad_files_fail_closed(): void
    {
        $this->applyAndGetSnapshot();
        $before = $this->snapshotDirGlobState();

        $outside = database_path('fixtures/m2c-r3-content.json'); // 允許目錄外的真實檔
        $traversal = storage_path('app/private/m2c-snapshots/../../../composer.json');
        $missing = storage_path('app/private/m2c-snapshots/no-such-file.json');
        $badJson = storage_path('app/private/m2c-snapshots/bad.json');
        file_put_contents($badJson, '{not json');

        foreach ([$outside, $traversal, $missing, $badJson] as $path) {
            $this->assertSame(1, Artisan::call('m2c:apply-r3', ['--rollback' => $path]), $path);
        }

        unlink($badJson);

        $this->assertEquals($before, $this->snapshotDirGlobState());
    }

    public function test_rollback_refuses_to_overwrite_owner_edits_with_fixed_conflict_identifier(): void
    {
        // (1) Owner 改了 site 欄位。
        $snapshot = $this->applyAndGetSnapshot();
        SiteSetting::query()->first()->forceFill(['home_h1' => 'Owner 手動改過的 H1'])->save();

        $before = $this->snapshotDirGlobState();
        $this->assertSame(1, Artisan::call('m2c:apply-r3', ['--rollback' => $snapshot]));
        $output = Artisan::output();
        $this->assertStringContainsString('R3-ROLLBACK-CONFLICT:', $output);
        $this->assertStringContainsString('site.home_h1', $output);
        // ⛔ conflict 訊息不得洩出文案內容。
        $this->assertStringNotContainsString('Owner 手動改過的 H1', $output);
        $this->assertEquals($before, $this->snapshotDirGlobState());
        $this->assertSame('Owner 手動改過的 H1', SiteSetting::query()->first()->home_h1);

        // (2) Owner 改了 managed FAQ 答案。
        SiteSetting::query()->first()->forceFill(['home_h1' => json_decode((string) file_get_contents(database_path('fixtures/m2c-r3-content.json')), true)['site']['home_h1']])->save();
        $faq = Faq::query()->where('managed_key', 'r3.global.membership')->firstOrFail();
        $faq->forceFill(['answer' => 'Owner 改寫的答案。'])->save();

        $before = $this->snapshotDirGlobState();
        $this->assertSame(1, Artisan::call('m2c:apply-r3', ['--rollback' => $snapshot]));
        $this->assertStringContainsString('faq.r3.global.membership', Artisan::output());
        $this->assertEquals($before, $this->snapshotDirGlobState());

        // (3) Owner 改了 managed section 內文。
        $faq->forceFill(['answer' => json_decode((string) file_get_contents(database_path('fixtures/m2c-r3-content.json')), true)['faqs']['global'][0]['answer']])->save();
        $section = ServiceContentSection::query()->where('managed_key', 'r3.ig買粉絲.prepare')->firstOrFail();
        $section->forceFill(['body' => 'Owner 改寫的段落內容。'])->save();

        $before = $this->snapshotDirGlobState();
        $this->assertSame(1, Artisan::call('m2c:apply-r3', ['--rollback' => $snapshot]));
        $this->assertStringContainsString('section.r3.ig買粉絲.prepare', Artisan::output());
        $this->assertEquals($before, $this->snapshotDirGlobState());
    }

    public function test_legacy_v1_snapshot_still_rolls_back_completely(): void
    {
        // 依真實首次快照的 v1 形狀,在 apply 前生成 legacy 快照。
        $siteFields = ['seo_title', 'meta_description', 'home_h1', 'home_intro', 'primary_cta_label',
            'home_highlight_1_title', 'home_highlight_1_body', 'home_highlight_2_title', 'home_highlight_2_body',
            'home_highlight_3_title', 'home_highlight_3_body'];
        $platformFields = ['h1', 'tagline', 'intro', 'seo_title', 'meta_description'];
        $serviceFields = ['seo_title', 'meta_description', 'h1', 'summary', 'intro', 'card_title', 'card_blurb', 'cta_label'];

        $fixture = json_decode((string) file_get_contents(database_path('fixtures/m2c-r3-content.json')), true);

        $removalRows = [];

        foreach ($fixture['faq_removals'] as $removal) {
            $query = Faq::query()->whereNull('managed_key')
                ->where('scope', $removal['scope'])->where('question', $removal['question']);

            if (($removal['product_slug'] ?? null) !== null) {
                $query->where('service_id', Service::query()->where('product_slug', $removal['product_slug'])->firstOrFail()->id);
            }

            foreach ($query->get() as $row) {
                $removalRows[] = $row->only(['id', 'scope', 'platform_id', 'service_id', 'question', 'answer', 'status', 'sort_order', 'managed_key']);
            }
        }

        $legacy = [
            'created_at' => '2026-08-20T14:44:51+08:00',
            'fixture' => 'database/fixtures/m2c-r3-content.json',
            'site' => SiteSetting::query()->first()->only($siteFields),
            'platforms' => Platform::query()->whereIn('slug', ['instagram', 'facebook', 'threads'])->get()
                ->mapWithKeys(fn ($p) => [$p->slug => $p->only($platformFields)])->all(),
            'services' => Service::query()->whereNotNull('product_slug')->get()
                ->mapWithKeys(fn ($s) => [$s->product_slug => $s->only($serviceFields)])->all(),
            'managed_faqs_before' => [],
            'managed_sections_before' => [],
            'removed_faqs' => $removalRows,
        ];

        $path = storage_path('app/private/m2c-snapshots/legacy-v1-drill.json');

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        file_put_contents($path, json_encode($legacy, JSON_UNESCAPED_UNICODE));

        $beforeState = $this->snapshotDirGlobState();
        $beforePrices = DB::table('service_variants')->orderBy('id')->pluck('unit_price', 'id')->all();

        $this->assertSame(0, Artisan::call('m2c:apply-r3'));
        $this->assertSame(0, Artisan::call('m2c:apply-r3', ['--rollback' => $path]), Artisan::output());

        // 完整還原 R3 前內容;價格與非 R3 資料不動。
        $this->assertEquals($beforeState, $this->snapshotDirGlobState());
        $this->assertSame($beforePrices, DB::table('service_variants')->orderBy('id')->pluck('unit_price', 'id')->all());

        unlink($path);
    }

    public function test_corrected_fixture_apply_is_still_idempotent_with_page_regression(): void
    {
        Artisan::call('m2c:apply-r3');
        Artisan::call('m2c:apply-r3');
        $second = Artisan::output();

        $this->assertStringContainsString('sections created=0', $second);
        $this->assertStringContainsString('faq created=0', $second);
        $this->assertStringContainsString('removed=0', $second);

        // 快速回歸:13 頁 200+唯一 Title+單一 H1+canonical/noindex+內鏈仍成立。
        $titles = [];
        $urls = array_merge(
            ['/', '/services/instagram', '/services/facebook', '/services/threads'],
            Service::query()->whereNotNull('product_slug')->pluck('product_slug')
                ->map(fn (string $slug) => '/product/'.$slug.'/')->all(),
        );

        foreach ($urls as $url) {
            $response = $this->get($url)->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
            $html = $response->getContent();
            $this->assertSame(1, substr_count($html, '<h1'), $url);
            preg_match('/<title>([^<]*)<\/title>/u', $html, $m);
            $titles[$url] = $m[1] ?? '';
            $this->assertDoesNotMatchRegularExpression('#href="[^"]*/services/[a-z]+/[a-z][^"]*"#', $html, $url);
        }

        $this->assertSame(13, count(array_unique($titles)));

        $followers = Service::query()->where('product_slug', 'ig買粉絲')->firstOrFail();
        $html = $this->get($followers->primaryUrl())->assertOk()->getContent();
        $this->assertStringContainsString('<link rel="canonical" href="'.$followers->primaryUrl().'">', $html);
    }
}
