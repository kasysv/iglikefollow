<?php

namespace Tests\Feature;

use App\Models\Faq;
use App\Models\Service;
use App\Models\ServiceContentSection;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\IsolatesSnapshotStorage;
use Tests\Concerns\SeedsThreadsCatalog;
use Tests\TestCase;

/**
 * M2-C R3-R2:v2 snapshot 完全閉合的反證。
 *
 * ⛔ 缺 key 與未知 key 同罪;expected 必須與 versioned fixture 逐欄一致
 * (hash 實證);created keys 必須可由「fixture−before」集合關係證明;
 * 衝突檢查涵蓋所有將被覆寫/刪除/forceFill 的欄位。
 */
class M2cR3RollbackClosureTest extends TestCase
{
    use IsolatesSnapshotStorage;
    use RefreshDatabase;
    use SeedsThreadsCatalog;

    protected function setUp(): void
    {
        parent::setUp();

        // ⛔ 快照寫進拋棄式目錄;不得污染 Owner 的真實還原資產。
        $this->isolateSnapshotStorage();

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
    private function publicContentState(): array
    {
        return [
            'faqs' => Faq::withTrashed()->orderBy('id')->get(['id', 'question', 'answer', 'sort_order', 'deleted_at', 'managed_key'])->toArray(),
            'sections' => ServiceContentSection::query()->orderBy('id')->get(['id', 'heading', 'body', 'sort_order', 'managed_key'])->toArray(),
            'site' => collect((array) DB::table('site_settings')->first())->except(['created_at', 'updated_at'])->all(),
        ];
    }

    /** @param callable(array): array $mutate */
    private function assertCraftedSnapshotFails(string $snapshot, callable $mutate, string $name): void
    {
        $data = json_decode((string) file_get_contents($snapshot), true);
        $payload = $mutate($data);

        $path = storage_path('app/private/m2c-snapshots/crafted-'.$name.'.json');
        file_put_contents($path, json_encode($payload, JSON_UNESCAPED_UNICODE));

        $before = $this->publicContentState();
        $this->assertSame(1, Artisan::call('m2c:apply-r3', ['--rollback' => $path]), $name);
        $this->assertEquals($before, $this->publicContentState(), $name);

        unlink($path);
    }

    public function test_missing_keys_are_rejected_like_unknown_keys(): void
    {
        $snapshot = $this->applyAndGetSnapshot();

        // (a) expected.site 缺欄(before 仍在 → 不能借 before 指示寫入)。
        $this->assertCraftedSnapshotFails($snapshot, function (array $d): array {
            unset($d['expected']['site']['seo_title']);

            return $d;
        }, 'missing-expected-site-field');

        // (b) before.site 缺欄。
        $this->assertCraftedSnapshotFails($snapshot, function (array $d): array {
            unset($d['site']['home_h1']);

            return $d;
        }, 'missing-before-site-field');

        // (c) expected 未知 nested key。
        $this->assertCraftedSnapshotFails($snapshot, function (array $d): array {
            $d['expected']['evil'] = [];

            return $d;
        }, 'unknown-expected-nested-key');

        // (d) removed row 缺必要欄位。
        $this->assertCraftedSnapshotFails($snapshot, function (array $d): array {
            if (! empty($d['removed_faqs'])) {
                unset($d['removed_faqs'][0]['answer']);
            } else {
                $this->fail('測試前提:removed_faqs 不得為空');
            }

            return $d;
        }, 'missing-removed-row-field');

        // (e) platforms 缺一個 slug。
        $this->assertCraftedSnapshotFails($snapshot, function (array $d): array {
            unset($d['platforms']['threads'], $d['expected']['platforms']['threads']);

            return $d;
        }, 'missing-platform-slug');
    }

    public function test_wrong_fixture_hash_is_rejected(): void
    {
        $snapshot = $this->applyAndGetSnapshot();

        $this->assertCraftedSnapshotFails($snapshot, function (array $d): array {
            $d['fixture_sha256'] = str_repeat('0', 64);

            return $d;
        }, 'wrong-fixture-hash');

        $this->assertCraftedSnapshotFails($snapshot, function (array $d): array {
            $d['fixture'] = 'database/fixtures/other.json';

            return $d;
        }, 'wrong-fixture-id');
    }

    public function test_expected_values_must_match_the_versioned_fixture(): void
    {
        $snapshot = $this->applyAndGetSnapshot();

        // expected 內容被竄改(仍是合法 shape)→ 與 fixture 不一致 → 拒絕。
        $this->assertCraftedSnapshotFails($snapshot, function (array $d): array {
            $d['expected']['site']['home_h1'] = '竄改過的 H1';

            return $d;
        }, 'tampered-expected-value');

        $this->assertCraftedSnapshotFails($snapshot, function (array $d): array {
            $d['expected']['managed']['r3.global.membership']['answer'] = '竄改過的答案';

            return $d;
        }, 'tampered-expected-managed');
    }

    public function test_arbitrary_created_keys_are_rejected_and_rows_untouched(): void
    {
        $snapshot = $this->applyAndGetSnapshot();

        // snapshot 外新建的 r3.future.* 列。
        $service = Service::query()->where('product_slug', 'ig買粉絲')->firstOrFail();
        Faq::query()->create([
            'scope' => 'service', 'service_id' => $service->id,
            'question' => '未來輪次問題?', 'answer' => '未來輪次答案。',
            'status' => 'published', 'sort_order' => 99, 'managed_key' => 'r3.future.custom',
        ]);

        // crafted:把該 key 塞進 created_faq_keys → 集合關係不符 → 整批 fail。
        $this->assertCraftedSnapshotFails($snapshot, function (array $d): array {
            $d['created_faq_keys'][] = 'r3.future.custom';

            return $d;
        }, 'arbitrary-created-key');

        // 該列原封不動。
        $this->assertDatabaseHas('faqs', ['managed_key' => 'r3.future.custom', 'deleted_at' => null]);

        // 同理:把 before row 假造成含未來 key(非 fixture key)→ 拒絕。
        $this->assertCraftedSnapshotFails($snapshot, function (array $d): array {
            $d['managed_faqs_before'][] = [
                'id' => 999999, 'scope' => 'service', 'platform_id' => null,
                'service_id' => 1, 'question' => 'x?', 'answer' => 'y。',
                'status' => 'published', 'sort_order' => 1, 'managed_key' => 'r3.future.custom',
            ];

            return $d;
        }, 'foreign-before-key');

        $this->assertDatabaseHas('faqs', ['managed_key' => 'r3.future.custom', 'deleted_at' => null]);
    }

    public function test_sort_order_and_relation_edits_also_conflict(): void
    {
        $snapshot = $this->applyAndGetSnapshot();

        // (1) Owner 只改 managed FAQ 的 sort_order。
        $faq = Faq::query()->where('managed_key', 'r3.global.membership')->firstOrFail();
        $faq->forceFill(['sort_order' => 77])->save();

        $before = $this->publicContentState();
        $this->assertSame(1, Artisan::call('m2c:apply-r3', ['--rollback' => $snapshot]));
        $this->assertStringContainsString('R3-ROLLBACK-CONFLICT:', Artisan::output());
        $this->assertEquals($before, $this->publicContentState());
        $faq->forceFill(['sort_order' => 1])->save();

        // (2) Owner 只改 managed section 的 sort_order。
        $section = ServiceContentSection::query()->where('managed_key', 'r3.ig買粉絲.prepare')->firstOrFail();
        $originalSort = (int) $section->sort_order;
        $section->forceFill(['sort_order' => 88])->save();

        $before = $this->publicContentState();
        $this->assertSame(1, Artisan::call('m2c:apply-r3', ['--rollback' => $snapshot]));
        $this->assertStringContainsString('section.r3.ig買粉絲.prepare', Artisan::output());
        $this->assertEquals($before, $this->publicContentState());
        $section->forceFill(['sort_order' => $originalSort])->save();

        // (3) Owner 改 trashed(被移除)FAQ 的內容。
        $trashed = Faq::withTrashed()->whereNull('managed_key')
            ->where('question', '現在可以真的付款嗎？')->firstOrFail();
        $trashed->forceFill(['answer' => 'Owner 在 trashed 列上改的答案。'])->save();

        $before = $this->publicContentState();
        $this->assertSame(1, Artisan::call('m2c:apply-r3', ['--rollback' => $snapshot]));
        $output = Artisan::output();
        $this->assertStringContainsString('removed-faq.'.$trashed->id, $output);
        // ⛔ 不洩出 Owner 內容。
        $this->assertStringNotContainsString('Owner 在 trashed 列上改的答案。', $output);
        $this->assertEquals($before, $this->publicContentState());
    }

    public function test_junction_or_symlink_escape_is_rejected(): void
    {
        $dir = storage_path('app/private/m2c-snapshots');
        $junction = $dir.DIRECTORY_SEPARATOR.'escape-junction';
        $target = database_path('fixtures');

        // Windows junction 不需管理員權限;建不起來就如實標 NOT VERIFIED。
        @exec('cmd /c mklink /J '.escapeshellarg($junction).' '.escapeshellarg($target).' 2>&1', $out, $code);

        if ($code !== 0 || ! is_dir($junction)) {
            $this->markTestSkipped('NOT VERIFIED:本機無法建立 junction/symlink,逃逸拒絕僅由 realpath guard 邏輯保證。');
        }

        try {
            $this->applyAndGetSnapshot();
            $before = $this->publicContentState();

            $escaped = $junction.DIRECTORY_SEPARATOR.'m2c-r3-content.json';
            $this->assertFileExists($escaped);
            $this->assertSame(1, Artisan::call('m2c:apply-r3', ['--rollback' => $escaped]));
            $this->assertEquals($before, $this->publicContentState());
        } finally {
            @exec('cmd /c rmdir '.escapeshellarg($junction));
        }
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedSnapshotStorage();

        parent::tearDown();
    }
}
