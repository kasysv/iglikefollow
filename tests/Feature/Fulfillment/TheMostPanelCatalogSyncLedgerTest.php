<?php

namespace Tests\Feature\Fulfillment;

use App\Data\Fulfillment\ProviderServiceCatalogSyncResult;
use App\Exceptions\TheMostPanelCatalogParseException;
use App\Services\Fulfillment\TheMostPanelCatalogSyncExecutionLedger;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;
use RuntimeException;
use Tests\TestCase;
use TypeError;

/**
 * The ledger itself: exclusive, durable, append-only, and safe.
 *
 * ⛔ Isolation is the storage root, not a directory parameter: the class has
 * no path entrance at all (R1), so every test swaps the whole storage root to
 * a temp directory via `useStoragePath()`. Nothing here touches the real
 * storage tree, the database or the network.
 */
class TheMostPanelCatalogSyncLedgerTest extends TestCase
{
    private string $storageRoot;

    /** 固定 ledger 目錄在隔離 storage root 下的解析結果。 */
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $this->storageRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'b4a-r1-storage-'.uniqid();
        File::ensureDirectoryExists($this->storageRoot);
        $this->app->useStoragePath($this->storageRoot);

        $this->dir = storage_path('app/private/themostpanel/catalog-sync-attempts');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storageRoot);

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

        TheMostPanelCatalogSyncExecutionLedger::open($id);
    }

    public function test_a_valid_id_creates_the_file_with_a_durable_initial_record(): void
    {
        $ledger = TheMostPanelCatalogSyncExecutionLedger::open('B4A-TEST-0001');

        $this->assertFileExists($ledger->path);

        // ⛔ 位置由 class 決定:唯一固定目錄＋驗證後 ID,caller 無從指定。
        $this->assertSame(
            $this->dir.DIRECTORY_SEPARATOR.'B4A-TEST-0001.ndjson',
            $ledger->path,
        );

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
        $first = TheMostPanelCatalogSyncExecutionLedger::open('B4A-TEST-0002');
        $original = file_get_contents($first->path);

        try {
            TheMostPanelCatalogSyncExecutionLedger::open('B4A-TEST-0002');
            $this->fail('同 ID 必須拒絕');
        } catch (RuntimeException $e) {
            $this->assertSame(TheMostPanelCatalogSyncExecutionLedger::CREATE_FAILED_MESSAGE, $e->getMessage());
        }

        // ⛔ 原檔一個 byte 都沒被動:沒有 truncate、沒有 delete。
        $this->assertSame($original, file_get_contents($first->path));
    }

    public function test_the_final_record_appends_to_the_same_artifact(): void
    {
        $ledger = TheMostPanelCatalogSyncExecutionLedger::open('B4A-TEST-0003');

        $result = ProviderServiceCatalogSyncResult::rejectedByParser(
            TheMostPanelCatalogParseException::because(TheMostPanelCatalogParseException::TOP_LEVEL_NOT_LIST),
            200,
            123,
        );

        $this->assertTrue($ledger->recordFinal($result));

        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($ledger->path))));
        $this->assertCount(2, $lines);

        // ⛔ 第一行仍是 initial marker:append,不是 rewrite。
        $this->assertSame('consumed-before-command', json_decode($lines[0], true)['state']);

        $final = json_decode($lines[1], true);
        $this->assertSame('completed', $final['state']);
        $this->assertSame('B4A-TEST-0003', $final['execution_id']);
        $this->assertSame('catalog_rejected_by_parser', $final['outcome']);
        $this->assertSame('catalog_top_level_not_list', $final['parser_reason']);
    }

    // ==================================== R1:typed-only final 入口

    /**
     * ⛔ R1 反例封閉:GPT 曾以任意 array 把 fake raw-body 與 credential
     * marker 寫進 ledger。現在唯一入口是 typed result,array 在呼叫層就是
     * TypeError,檔案一個 byte 都不會多。
     */
    public function test_an_arbitrary_array_cannot_enter_the_final_record(): void
    {
        $ledger = TheMostPanelCatalogSyncExecutionLedger::open('B4A-TEST-0007');
        $before = file_get_contents($ledger->path);

        try {
            /* @phpstan-ignore-next-line 反例本身就是傳錯型別。 */
            $ledger->recordFinal([
                'raw_body' => '{"FAKE-RAW-BODY-MARKER-424242":true}',
                'credential' => 'FAKE-API-KEY-MARKER-424242',
            ]);
            $this->fail('array 必須無法進入 recordFinal');
        } catch (TypeError) {
            // 期望路徑:typed parameter 是執行期安全邊界。
        }

        // ⛔ 不落地:marker 沒有任何 byte 進到 ledger。
        $raw = (string) file_get_contents($ledger->path);
        $this->assertSame($before, $raw);
        $this->assertStringNotContainsString('FAKE-RAW-BODY-MARKER-424242', $raw);
        $this->assertStringNotContainsString('FAKE-API-KEY-MARKER-424242', $raw);
    }

    /**
     * ⛔ R1 反例封閉:public API 不存在任何 directory／path／stream 入口。
     * `open()` 只收 execution ID,`recordFinal()` 只收 typed result,
     * constructor 是 private——這是 reflection 可證的封閉介面。
     */
    public function test_no_public_entrance_accepts_a_directory_or_arbitrary_fields(): void
    {
        $class = new ReflectionClass(TheMostPanelCatalogSyncExecutionLedger::class);

        $this->assertTrue($class->getConstructor()->isPrivate());

        $publicMethods = array_map(
            fn (ReflectionMethod $method) => $method->getName(),
            array_filter(
                $class->getMethods(ReflectionMethod::IS_PUBLIC),
                fn (ReflectionMethod $method) => ! $method->isConstructor(),
            ),
        );
        sort($publicMethods);

        $this->assertSame(['isValidExecutionId', 'open', 'recordFinal'], $publicMethods);

        $open = $class->getMethod('open');
        $this->assertSame(1, $open->getNumberOfParameters());
        $this->assertSame('executionId', $open->getParameters()[0]->getName());
        $type = $open->getParameters()[0]->getType();
        $this->assertInstanceOf(ReflectionNamedType::class, $type);
        $this->assertSame('string', $type->getName());

        $final = $class->getMethod('recordFinal');
        $this->assertSame(1, $final->getNumberOfParameters());
        $finalType = $final->getParameters()[0]->getType();
        $this->assertInstanceOf(ReflectionNamedType::class, $finalType);
        $this->assertSame(ProviderServiceCatalogSyncResult::class, $finalType->getName());
    }

    /**
     * ⛔ 模擬 crash:initial 之後沒有 final,marker 仍在,同 ID 第二次
     * 執行必須拒絕——這正是 B3 缺的那個保證。
     */
    public function test_an_abandoned_execution_still_consumes_the_id_forever(): void
    {
        $ledger = TheMostPanelCatalogSyncExecutionLedger::open('B4A-TEST-0004');
        $path = $ledger->path;

        // 模擬 process 中斷:handle 隨物件消失,final 永遠沒寫。
        unset($ledger);

        $this->assertFileExists($path);
        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($path))));
        $this->assertCount(1, $lines);

        $this->expectException(RuntimeException::class);

        TheMostPanelCatalogSyncExecutionLedger::open('B4A-TEST-0004');
    }

    /** ⛔ final 寫入失敗回 false、不 throw:action 已發生,唯一正確反應是人工審查。 */
    public function test_a_final_write_failure_reports_false_without_throwing(): void
    {
        $ledger = TheMostPanelCatalogSyncExecutionLedger::open('B4A-TEST-0005');

        // 模擬 handle 失效(磁碟拔除／檔案系統錯誤的最近似可控形式)。
        $property = new ReflectionProperty($ledger, 'handle');
        fclose($property->getValue($ledger));

        $this->assertFalse($ledger->recordFinal(ProviderServiceCatalogSyncResult::refused('transport_failed')));

        // initial marker 仍在。
        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($ledger->path))));
        $this->assertCount(1, $lines);
    }

    public function test_an_unwritable_directory_fails_closed(): void
    {
        // 以「檔案佔住固定目錄路徑」讓 mkdir 必然失敗——跨平台可靠的不可寫模擬。
        File::ensureDirectoryExists(dirname($this->dir));
        file_put_contents($this->dir, 'occupied');

        $this->expectException(RuntimeException::class);

        TheMostPanelCatalogSyncExecutionLedger::open('B4A-TEST-0006');
    }
}
