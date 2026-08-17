<?php

namespace Tests\Feature\Fulfillment;

use App\Services\Fulfillment\TheMostPanelCatalogSyncExecutionLedger;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use RuntimeException;
use Tests\TestCase;

/**
 * The ledger itself: exclusive, durable, append-only, and safe.
 *
 * ⛔ Every test uses an isolated temp directory — nothing here touches the
 * real storage tree, the database or the network.
 */
class TheMostPanelCatalogSyncLedgerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'b4a-ledger-'.uniqid();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    /** @return array<string, array{0: string}> */
    public static function invalidExecutionIdProvider(): array
    {
        return [
            'empty' => [''],
            'too short' => ['ABC-123'],
            'too long' => [str_repeat('A', 65)],
            'lowercase' => ['abcdefgh-1234'],
            'leading hyphen' => ['-ABCDEFGH'],
            'space inside' => ['ABCD EFGH'],
            'path traversal' => ['../../EVIL-ID'],
            'dot segments' => ['..-ABCDEFG'],
            'unicode' => ['執行編號12345678'],
            'underscore' => ['ABCD_EFGH'],
        ];
    }

    #[DataProvider('invalidExecutionIdProvider')]
    public function test_an_invalid_execution_id_is_refused(string $id): void
    {
        $this->assertFalse(TheMostPanelCatalogSyncExecutionLedger::isValidExecutionId($id));

        $this->expectException(RuntimeException::class);

        TheMostPanelCatalogSyncExecutionLedger::open($this->dir, $id);
    }

    public function test_a_valid_id_creates_the_file_with_a_durable_initial_record(): void
    {
        $ledger = TheMostPanelCatalogSyncExecutionLedger::open($this->dir, 'B4A-TEST-0001');

        $this->assertFileExists($ledger->path);

        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($ledger->path))));
        $this->assertCount(1, $lines);

        $initial = json_decode($lines[0], true);
        $this->assertSame('B4A-TEST-0001', $initial['execution_id']);
        // ⛔ 固定 state:request 前授權已消耗。
        $this->assertSame('consumed-before-command', $initial['state']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $initial['recorded_at_utc']);
    }

    /** ⛔ 同 ID 永不可重用:exclusive create 是全部機制,沒有覆寫、沒有重試。 */
    public function test_a_duplicate_id_fails_closed_and_never_touches_the_original(): void
    {
        $first = TheMostPanelCatalogSyncExecutionLedger::open($this->dir, 'B4A-TEST-0002');
        $original = file_get_contents($first->path);

        try {
            TheMostPanelCatalogSyncExecutionLedger::open($this->dir, 'B4A-TEST-0002');
            $this->fail('同 ID 必須拒絕');
        } catch (RuntimeException $e) {
            $this->assertSame(TheMostPanelCatalogSyncExecutionLedger::CREATE_FAILED_MESSAGE, $e->getMessage());
        }

        // ⛔ 原檔一個 byte 都沒被動:沒有 truncate、沒有 delete。
        $this->assertSame($original, file_get_contents($first->path));
    }

    public function test_the_final_record_appends_to_the_same_artifact(): void
    {
        $ledger = TheMostPanelCatalogSyncExecutionLedger::open($this->dir, 'B4A-TEST-0003');

        $this->assertTrue($ledger->recordFinal([
            'outcome' => 'catalog_rejected_by_parser',
            'catalog_applied' => false,
            'parser_reason' => 'catalog_top_level_not_list',
        ]));

        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($ledger->path))));
        $this->assertCount(2, $lines);

        // ⛔ 第一行仍是 initial marker:append,不是 rewrite。
        $this->assertSame('consumed-before-command', json_decode($lines[0], true)['state']);

        $final = json_decode($lines[1], true);
        $this->assertSame('completed', $final['state']);
        $this->assertSame('B4A-TEST-0003', $final['execution_id']);
        $this->assertSame('catalog_top_level_not_list', $final['parser_reason']);
    }

    /**
     * ⛔ 模擬 crash:initial 之後沒有 final,marker 仍在,同 ID 第二次
     * 執行必須拒絕——這正是 B3 缺的那個保證。
     */
    public function test_an_abandoned_execution_still_consumes_the_id_forever(): void
    {
        $ledger = TheMostPanelCatalogSyncExecutionLedger::open($this->dir, 'B4A-TEST-0004');
        $path = $ledger->path;

        // 模擬 process 中斷:handle 隨物件消失,final 永遠沒寫。
        unset($ledger);

        $this->assertFileExists($path);
        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($path))));
        $this->assertCount(1, $lines);

        $this->expectException(RuntimeException::class);

        TheMostPanelCatalogSyncExecutionLedger::open($this->dir, 'B4A-TEST-0004');
    }

    /** ⛔ final 寫入失敗回 false、不 throw:action 已發生,唯一正確反應是人工審查。 */
    public function test_a_final_write_failure_reports_false_without_throwing(): void
    {
        $ledger = TheMostPanelCatalogSyncExecutionLedger::open($this->dir, 'B4A-TEST-0005');

        // 模擬 handle 失效(磁碟拔除／檔案系統錯誤的最近似可控形式)。
        $property = new ReflectionProperty($ledger, 'handle');
        fclose($property->getValue($ledger));

        $this->assertFalse($ledger->recordFinal(['outcome' => 'transport_failed']));

        // initial marker 仍在。
        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($ledger->path))));
        $this->assertCount(1, $lines);
    }

    public function test_an_unwritable_directory_fails_closed(): void
    {
        // 以「檔案佔住目錄路徑」讓 mkdir 必然失敗——跨平台可靠的不可寫模擬。
        File::ensureDirectoryExists(dirname($this->dir.'-blocked'));
        file_put_contents($this->dir.'-blocked', 'occupied');

        $this->expectException(RuntimeException::class);

        try {
            TheMostPanelCatalogSyncExecutionLedger::open($this->dir.'-blocked', 'B4A-TEST-0006');
        } finally {
            @unlink($this->dir.'-blocked');
        }
    }
}
