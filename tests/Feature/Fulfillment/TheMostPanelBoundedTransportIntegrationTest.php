<?php

namespace Tests\Feature\Fulfillment;

use App\Contracts\TheMostPanelDispatchCredentialSource;
use App\Data\Fulfillment\FulfillmentSubmission;
use App\Enums\FulfillmentAttentionReason;
use App\Services\Fulfillment\TheMostPanelBoundedResponseStream;
use App\Services\Fulfillment\TheMostPanelCurlCapability;
use App\Services\Fulfillment\TheMostPanelFulfillmentGateway;
use App\Services\Fulfillment\TheMostPanelHardenedTransport;
use App\Services\Fulfillment\TheMostPanelResponseSizeGuard;
use App\Services\Fulfillment\TheMostPanelTransferState;
use GuzzleHttp\Client;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Throwable;

/**
 * REAL cURL proof: the short-writing sink aborts an oversized transfer
 * before the body finishes downloading — on this machine's libcurl, no 8.4.
 *
 * ⛔ 這一檔刻意**不用** `Http::fake()`:fake 走 MockHandler,考驗不到
 * libcurl 的 write callback。這裡以 `php -S 127.0.0.1:<隨機port>` 啟動
 * 本檔專屬的 localhost fixture(`tests/Fixtures/oversized-stream-server.php`),
 * 用**真實的 HardenedTransport(真 cURL handler)**打它——整個測試唯一的
 * 流量是 loopback,⛔ 0 對外請求、0 provider、0 credential。
 *
 * 要證明的事(施工單 3.3):chunked/未宣告長度、總量 10 MiB 的回應,在
 * 完整下載完成之前就被中止——sink `overflowed()`=true、保存量 ≤ 2 MiB 上限、
 * 且遠快於 15 秒 timeout(逾時結束=沒有中止,只是等到死)。
 */
class TheMostPanelBoundedTransportIntegrationTest extends TestCase
{
    /** @var resource|null */
    private $server = null;

    private int $port = 0;

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }

        parent::tearDown();
    }

    /** 啟動 fixture server;失敗(埠被占/環境不允許)則明確 skip,不假裝通過。 */
    private function startFixtureServer(): void
    {
        $router = base_path('tests/Fixtures/oversized-stream-server.php');

        for ($try = 0; $try < 5; $try++) {
            $this->port = random_int(49500, 59999);

            $this->server = proc_open(
                [PHP_BINARY, '-S', "127.0.0.1:{$this->port}", $router],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );

            if (! is_resource($this->server)) {
                continue;
            }

            // 等它開始收連線(最多 5 秒)。
            for ($i = 0; $i < 50; $i++) {
                $socket = @fsockopen('127.0.0.1', $this->port, $errno, $error, 0.1);

                if (is_resource($socket)) {
                    fclose($socket);

                    return;
                }

                usleep(100_000);
            }

            proc_terminate($this->server);
            proc_close($this->server);
            $this->server = null;
        }

        // ⛔ 起不來就誠實 skip:這是環境問題,不能拿假裝的 PASS 蓋過去。
        $this->markTestSkipped('無法啟動 localhost fixture server(埠不可用?)');
    }

    private function url(string $path): string
    {
        return "http://127.0.0.1:{$this->port}{$path}";
    }

    // ==================================== 1. 正常小回應:harness 本身是通的

    public function test_a_small_response_passes_through_the_real_transport(): void
    {
        $this->startFixtureServer();

        $sink = new TheMostPanelBoundedResponseStream(TheMostPanelResponseSizeGuard::MAX_BODY_BYTES);
        $transfer = new TheMostPanelTransferState;

        $response = (new TheMostPanelHardenedTransport)
            ->postExactlyOnce($this->url('/small'), ['action' => 'ping'], $sink, $transfer);

        $this->assertSame(200, $response->status());
        $this->assertFalse($sink->overflowed());
        $this->assertStringContainsString('23501', (string) $response->body());
    }

    // ==================================== 2. 超限 chunked 回應:下載完成前中止

    /**
     * 核心證明:10 MiB、未宣告長度的串流,在完整下載前被截停。
     *
     * 三個判準缺一不可:
     *  - transport 以失敗收場(libcurl write error),不是完整 200;
     *  - `overflowed()`=true(本站主動拒收)且保存量 ≤ 上限;
     *  - 耗時遠小於 15 秒 timeout——「等到逾時」不是中止,是投降。
     */
    public function test_an_oversized_chunked_response_is_aborted_mid_transfer(): void
    {
        $this->startFixtureServer();

        $sink = new TheMostPanelBoundedResponseStream(TheMostPanelResponseSizeGuard::MAX_BODY_BYTES);
        $transfer = new TheMostPanelTransferState;

        $threw = false;
        $started = microtime(true);

        try {
            (new TheMostPanelHardenedTransport)
                ->postExactlyOnce($this->url('/stream'), ['action' => 'services'], $sink, $transfer);
        } catch (Throwable) {
            // ⛔ 不讀、不斷言任何錯誤文字。
            $threw = true;
        }

        $elapsed = microtime(true) - $started;

        $this->assertTrue($threw, '超限串流必須以失敗收場,不得回一個看似完整的 response');
        $this->assertTrue($sink->overflowed(), 'sink 必須記下「本站主動拒收」');
        $this->assertLessThanOrEqual(
            TheMostPanelResponseSizeGuard::MAX_BODY_BYTES,
            $sink->bytesWritten(),
            '保存量不得超過上限',
        );
        $this->assertGreaterThan(0, $sink->bytesWritten(), '上限之前的資料應正常保存');

        /*
         * ⛔ 快於 timeout 一大截才算「傳輸中止」。10 MiB loopback 串流在
         * 中止失敗時會跑滿或撞 15s timeout;中止成功則在 2 MiB 處就停。
         */
        $this->assertLessThan(10.0, $elapsed, "耗時 {$elapsed}s:看起來是等到 timeout,不是主動中止");
    }

    /**
     * ⛔ 隔離證明:**只靠 sink 的 short write**,真實 cURL 也會中止。
     *
     * 上面的測試走完整 hardened chain,那裡還有 progress/on_headers 等其他
     * 保險層——mutation(overflow 時回完整 byte count)可能被那些層蓋住而
     * 測不出來。這裡用一個**只有 sink**、沒有任何其他限制層的最小 Guzzle
     * client(真 cURL handler)打同一個超限串流:唯一能中止它的就是 short
     * write 本身。⛔ 這不是複製 hardened chain——它刻意什麼都不帶,存在的
     * 目的正是把機制隔離出來單獨驗證;production 永遠只用 HardenedTransport。
     *
     * mutation 反證:sink 在 overflow 時改回完整 byte count → 這裡的下載會
     * 跑完整 10 MiB 並回 200 → 本測試必須失敗。
     */
    public function test_the_short_write_alone_aborts_a_raw_curl_transfer(): void
    {
        $this->startFixtureServer();

        $sink = new TheMostPanelBoundedResponseStream(TheMostPanelResponseSizeGuard::MAX_BODY_BYTES);

        $threw = false;
        $started = microtime(true);

        try {
            (new Client)->post($this->url('/stream'), [
                'sink' => $sink,
                'timeout' => 15,
                'decode_content' => false,
            ]);
        } catch (Throwable) {
            $threw = true;
        }

        $elapsed = microtime(true) - $started;

        $this->assertTrue($threw, '⛔ 只有 short write 一層時仍必須中止;完整下載完成代表 short write 沒有作用');
        $this->assertTrue($sink->overflowed());
        $this->assertLessThanOrEqual(TheMostPanelResponseSizeGuard::MAX_BODY_BYTES, $sink->bytesWritten());
        $this->assertLessThan(10.0, $elapsed);
    }

    // ==================================== 3. add 遇 size abort → unknown,0 重送

    /**
     * `add` 已送出後回應超限:結果必須是 unknown(可能已成立),
     * ⛔ 絕不自動重送——這裡用注入式 transport 模擬 libcurl 的 short-write
     * 中止行為,證明 gateway 以 sink 的 overflow state 分類,不讀錯誤文字。
     */
    public function test_a_size_abort_after_add_is_unknown_and_never_resent(): void
    {
        Http::preventStrayRequests(); // 這一個測試不碰網路。

        $credentials = new class implements TheMostPanelDispatchCredentialSource
        {
            public function apiKey(): ?string
            {
                return 'FAKE-KEY-FOR-SIZE-ABORT-TEST';
            }
        };

        // 模擬 libcurl:把 chunk 塞進 sink,遇 short write 立即以 write error 中止。
        $transport = new class extends TheMostPanelHardenedTransport
        {
            public int $calls = 0;

            public function postExactlyOnce(
                string $endpoint,
                array $payload,
                TheMostPanelBoundedResponseStream $sink,
                TheMostPanelTransferState $transfer,
            ): Response {
                $this->calls++;
                $chunk = str_repeat('B', 1_048_576);

                for ($i = 0; $i < 10; $i++) {
                    if ($sink->write($chunk) === 0) {
                        throw new ConnectionException('cURL error 23');
                    }
                }

                $this->fail('sink 應該在 10 MiB 之前就拒收');
            }

            private function fail(string $message): never
            {
                throw new \LogicException($message);
            }
        };

        $gateway = new TheMostPanelFulfillmentGateway(
            $credentials,
            TheMostPanelCurlCapability::supported(),
            $transport,
        );

        $result = $gateway->submit(new FulfillmentSubmission('4501', 'https://example.invalid/post', 1000));

        // ⛔ unknown,不是 rejected:對方可能已收單。恰好一次嘗試,0 重送。
        $this->assertTrue($result->isUnknown());
        $this->assertSame(FulfillmentAttentionReason::UnreadableResponse, $result->reason);
        $this->assertSame(1, $transport->calls);

        // 唯讀 sync 遇同樣中止:unrecognised,同樣恰好一次。
        $sync = $gateway->sync('23501');
        $this->assertFalse($sync->isRecognised());
        $this->assertSame(2, $transport->calls);
    }
}
