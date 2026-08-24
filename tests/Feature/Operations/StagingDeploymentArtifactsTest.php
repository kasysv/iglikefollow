<?php

namespace Tests\Feature\Operations;

use App\Jobs\SubmitFulfillmentOrder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Static verification of the RunCloud staging deployment package.
 *
 * ⛔ Documents drift; code does not. These tests pin the deployment
 * artifacts to the facts they claim: flags default off, worker settings
 * compatible with the queue config, callback paths identical to the real
 * route table, scripts free of secrets and of anything that could write,
 * POST or reach a provider.
 */
class StagingDeploymentArtifactsTest extends TestCase
{
    private const DIR = 'deploy/runcloud/';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    private function artifact(string $file): string
    {
        $path = base_path(self::DIR.$file);
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    // ==================================== env 範本

    public function test_every_capability_flag_defaults_to_false_in_the_env_template(): void
    {
        $env = $this->artifact('staging.env.example');

        foreach ([
            'THEMOSTPANEL_READ_ONLY_ENABLED=false',
            'ALLOW_INDEXING=false',
            'APP_ENV=staging',
            'APP_DEBUG=false',
            'QUEUE_CONNECTION=database',
        ] as $line) {
            $this->assertStringContainsString($line, $env, $line);
        }
    }

    /**
     * ⛔ M4C＋R1:付款／發票／派單的舊 env 旗標不得再出現在範本裡。
     *
     * runtime 已完全不讀它們;範本若還列著 `FULFILLMENT_DISPATCH_ENABLED=false`,
     * 部署的人會以為那一行就是「派單關閉」的證據——一個看起來通過、實際上
     * 什麼都沒驗到的檢查。真正的開關在 Owner 的後台,證據由
     * `app:staging-readiness` 逐項回報。
     */
    public function test_the_deprecated_flags_are_not_offered_by_the_template(): void
    {
        $env = $this->artifact('staging.env.example');

        foreach ([
            'PAYMENTS_SANDBOX_ENABLED=',
            'INVOICE_SANDBOX_ENABLED=',
            'INVOICE_GATEWAY=',
            'FULFILLMENT_DISPATCH_ENABLED=',
            'FULFILLMENT_STAGING_THEMOSTPANEL_DISPATCH_ENABLED=',
            'FULFILLMENT_STATUS_POLLING_ENABLED=',
            'FULFILLMENT_DRIVER=',
        ] as $line) {
            $this->assertStringNotContainsString($line, $env, $line);
        }

        // 範本必須指向真正的確認方式。
        $this->assertStringContainsString('app:staging-readiness', $env);
    }

    /**
     * ⛔ R2:runbook 不得再宣稱「全部能力 flag=false」——那與 Owner DB 開關
     * 矛盾,會讓部署的人把一行沒有作用的 .env 當成「能力一定關閉」的證據。
     */
    public function test_the_runbook_points_at_the_owner_switch_not_the_flags(): void
    {
        $runbook = $this->artifact('staging-runbook.md');

        $this->assertStringNotContainsString('全部能力 flag=false', $runbook);
        $this->assertStringNotContainsString('FULFILLMENT_DRIVER=disabled、', $runbook);

        // 開關的真正位置與真正的驗證方式。
        $this->assertStringContainsString('integration_settings.is_enabled', $runbook);
        $this->assertStringContainsString('app:staging-readiness', $runbook);
        // ⛔ Owner 切開關不需要 queue:restart(R2 的核心保證)。
        $this->assertStringContainsString('不需要', $runbook);
        // ⛔ 沒有 SQL 開關指令。
        $this->assertStringNotContainsString('UPDATE integration_settings', $runbook);
    }

    /** ⛔ secret 欄位只有 placeholder;沒有任何看似真值的內容。 */
    public function test_the_env_template_contains_placeholders_only(): void
    {
        $env = $this->artifact('staging.env.example');

        $this->assertStringContainsString('APP_KEY=<SET-IN-RUNCLOUD-ONLY>', $env);
        $this->assertStringContainsString('DB_PASSWORD=<SET-IN-RUNCLOUD-ONLY>', $env);
        // Laravel encrypted envelope / base64 key 形狀不得出現。
        $this->assertStringNotContainsString('eyJpdiI6', $env);
        $this->assertStringNotContainsString('base64:', $env);
    }

    // ==================================== worker 與 queue 相容性

    public function test_the_worker_timeout_is_below_the_database_retry_after(): void
    {
        $conf = $this->artifact('queue-worker.conf.example');

        $this->assertStringContainsString('--sleep=3 --tries=3 --timeout=60 --max-time=3600', $conf);

        // 靜態反證:timeout 60 < 現行 database retry_after(config 事實)。
        $retryAfter = (int) config('queue.connections.database.retry_after');
        $this->assertSame(90, $retryAfter);
        $this->assertLessThan($retryAfter, 60);
    }

    /** ⛔ worker --tries=3 不覆蓋 job 自身封頂:SubmitFulfillmentOrder 仍是 1。 */
    public function test_the_worker_tries_do_not_override_the_submit_job_cap(): void
    {
        $this->assertSame(1, (new SubmitFulfillmentOrder(1))->tries);

        // conf 內必須明文記載這件事,避免未來被「統一調參」誤刪。
        $conf = $this->artifact('queue-worker.conf.example');
        $this->assertStringContainsString('SubmitFulfillmentOrder::$tries=1', $conf);
    }

    public function test_the_cron_template_has_no_windows_paths(): void
    {
        $cron = $this->artifact('scheduler.cron.example');

        $this->assertStringContainsString('schedule:run', $cron);
        $this->assertStringNotContainsString('C:\\', $cron);
        $this->assertStringNotContainsString('phpstudy', strtolower($cron));
    }

    // ==================================== callback routes 與現實一致

    public function test_documented_callback_routes_match_the_real_route_table(): void
    {
        $uris = collect(Route::getRoutes())->map(fn ($route) => $route->uri());

        foreach ([
            'payments/ecpay/callback',
            'payments/linepay/{reference}/confirm',
            'payments/linepay/{reference}/cancel',
            'up',
            'api/health',
        ] as $uri) {
            $this->assertTrue($uris->contains($uri), $uri);
        }

        foreach (['staging-runbook.md', 'staging-deployment-plan.md'] as $doc) {
            $text = $this->artifact($doc);
            $this->assertStringContainsString('/payments/ecpay/callback', $text, $doc);
            $this->assertStringContainsString('/payments/linepay/{reference}/confirm', $text, $doc);
        }
    }

    // ==================================== scripts 安全性

    /** deploy script:mode guard、fail-stop、無危險指令;⛔ 未執行範本。 */
    public function test_the_deploy_script_is_guarded_and_free_of_dangerous_commands(): void
    {
        $script = $this->artifact('deploy-script.example.sh');

        $this->assertStringContainsString('set -euo pipefail', $script);
        $this->assertStringContainsString('DEPLOY_MODE', $script);
        $this->assertStringContainsString('mode guard', $script);
        $this->assertStringContainsString('queue:restart', $script);
        $this->assertStringContainsString('app:staging-readiness', $script);
        // backup gate 在 migrate 之前(文字順序靜態驗證)。
        $this->assertLessThan(strpos($script, 'artisan migrate'), strpos($script, 'backup gate'));

        foreach (['cloudflare', 'themostpanel.com', 'ecpay.com.tw', 'line.me', 'apikey', 'merchantid'] as $needle) {
            $this->assertStringNotContainsString($needle, strtolower($script), $needle);
        }
    }

    /** post-deploy check:只 GET/HEAD;⛔ 無 POST、表單、provider endpoint。 */
    public function test_the_post_deploy_check_is_read_only(): void
    {
        $script = $this->artifact('staging-post-deploy-check.sh');

        $this->assertStringContainsString('/up', $script);
        $this->assertStringContainsString('/api/health', $script);
        $this->assertStringContainsString('robots.txt', $script);
        $this->assertStringContainsString('X-Robots-Tag', $script);

        foreach (['-X POST', '--data', '-F ', 'themostpanel.com', 'ecpay.com.tw', 'line.me', 'callback'] as $needle) {
            $this->assertStringNotContainsString($needle, $script, $needle);
        }
    }

    /** preflight:APP_KEY 只驗 presence,無任何可輸出值的指令。 */
    public function test_the_preflight_checks_app_key_presence_only(): void
    {
        $script = $this->artifact('staging-preflight.sh');

        $this->assertStringContainsString('APP_KEY=.+', $script);
        $this->assertStringContainsString('只驗有/無', $script);
        // ⛔ 不得有會把 .env 值印出來的指令形狀。
        $this->assertStringNotContainsString('cat "${APP_DIR}/.env"', $script);
        // 可寫性只用 -w 測試,不建立測試檔、不 chmod。
        $this->assertStringContainsString('-w "${APP_DIR}/storage"', $script);
        $this->assertStringNotContainsString('chmod', $script);
        $this->assertStringNotContainsString('chown', $script);
        $this->assertStringNotContainsString('touch ', $script);
    }

    /** ⛔ 部署包全檔 secret scan:無 Laravel 密文/金鑰形狀。 */
    public function test_no_deployment_artifact_contains_secret_shapes(): void
    {
        foreach ([
            'staging-runbook.md', 'staging-deployment-plan.md', 'staging.env.example',
            'queue-worker.conf.example', 'scheduler.cron.example',
            'deploy-script.example.sh', 'staging-preflight.sh',
            'staging-post-deploy-check.sh', 'staging-input-checklist.md',
        ] as $file) {
            $text = $this->artifact($file);

            $this->assertStringNotContainsString('eyJpdiI6', $text, $file);
            $this->assertStringNotContainsString('base64:', $text, $file);
            // 40+ base64 字元且含 4 個以上數字才視為疑似金鑰
            // (排除文件裡「word/word/word」型的路徑詞鏈)。
            preg_match_all('#[A-Za-z0-9+/]{40,}={0,2}#', $text, $matches);
            $suspicious = array_values(array_filter(
                $matches[0],
                fn (string $candidate) => preg_match_all('/[0-9]/', $candidate) >= 4,
            ));
            $this->assertSame([], $suspicious, $file.' 含疑似金鑰的長 base64 字串');
        }
    }

    // ==================================== R1:shell runtime 反證(真的執行)

    /** 找到 bash;⛔ 找不到就明確 fail,不假裝通過。 */
    private function bash(): string
    {
        foreach ([
            'C:\\Program Files\\Git\\bin\\bash.exe',
            'C:\\Program Files\\Git\\usr\\bin\\bash.exe',
            '/usr/bin/bash',
            '/bin/bash',
        ] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        $this->fail('⛔ 找不到 bash(Git Bash/Linux bash);runtime 反證無法執行。');
    }

    /**
     * 建立 fake-curl bin 目錄;⛔ 永不連網,依 URL 回 fixture。
     *
     * R1:fixture 現在會模擬 D-103 的「單次 302 → 商品 canonical」。
     * `FAKE_CURL_REDIR` 讓每個測試改寫那一次轉址的行為,用來反證腳本
     * 在錯誤 Location、外站 Location、target 仍 redirect 時真的會 fail。
     */
    private function makeFakeCurl(): string
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'fake-curl-'.uniqid();
        mkdir($dir, 0777, true);

        $script = <<<'SH'
#!/usr/bin/env bash
# fake curl:⛔ 不連網;依 URL 回 fixture(1 H1、meta noindex、
# X-Robots noindex、robots Disallow)。FAKE_CURL_MODE=fail 模擬 transport failure。
#
# D-103 fixture:
#   /services/instagram/followers  →  302 + Location: <base>/product/ig%E8%B2%B7%E7%B2%89%E7%B5%B2/
#   該 canonical target             →  200(單一 H1、meta noindex)
#
# FAKE_CURL_REDIR 可改寫 302 那一步,用來反證腳本會 fail closed:
#   wrong-path | other-host | with-query | no-location | not-302 | target-redirects
MODE="${FAKE_CURL_MODE:-ok}"
REDIR="${FAKE_CURL_REDIR:-ok}"
CANON_PATH='/product/ig%E8%B2%B7%E7%B2%89%E7%B5%B2/'
HEAD=0
OUT=""
URL=""
prev=""
for a in "$@"; do
    if [ "$prev" = "-o" ]; then OUT="$a"; fi
    case "$a" in
        -sSI|-I) HEAD=1 ;;
        https://*|http://*) URL="$a" ;;
    esac
    prev="$a"
done
if [ "$MODE" = "fail" ]; then exit 6; fi

# base = scheme://host[:port]
BASE_ONLY="$(printf '%s' "$URL" | sed -E 's#^(https?://[^/]+).*#\1#')"

is_service_url=0
case "$URL" in
    */services/instagram/followers) is_service_url=1 ;;
esac
is_canonical=0
case "$URL" in
    */product/*) is_canonical=1 ;;
esac

if [ "$HEAD" = "1" ]; then
    if [ "$is_service_url" = "1" ]; then
        case "$REDIR" in
            no-location) printf 'HTTP/2 302\r\nx-robots-tag: noindex, nofollow\r\n\r\n' ;;
            not-302)     printf 'HTTP/2 200\r\nx-robots-tag: noindex, nofollow\r\n\r\n' ;;
            wrong-path)  printf 'HTTP/2 302\r\nlocation: %s/product/wrong-slug/\r\n\r\n' "$BASE_ONLY" ;;
            other-host)  printf 'HTTP/2 302\r\nlocation: https://evil.example.invalid%s\r\n\r\n' "$CANON_PATH" ;;
            with-query)  printf 'HTTP/2 302\r\nlocation: %s%s?utm=x\r\n\r\n' "$BASE_ONLY" "$CANON_PATH" ;;
            *)           printf 'HTTP/2 302\r\nlocation: %s%s\r\n\r\n' "$BASE_ONLY" "$CANON_PATH" ;;
        esac
        exit 0
    fi
    # canonical target 自己再轉一次 → chain(必須被抓出來)
    if [ "$is_canonical" = "1" ] && [ "$REDIR" = "target-redirects" ]; then
        printf 'HTTP/2 302\r\nlocation: %s/somewhere-else/\r\n\r\n' "$BASE_ONLY"
        exit 0
    fi
    printf 'HTTP/2 200\r\nx-robots-tag: noindex, nofollow\r\n\r\n'
    exit 0
fi

case "$URL" in
    */robots.txt) BODY="User-agent: *
Disallow: /" ;;
    */up|*/api/health) BODY='ok' ;;
    *) BODY='<html><head><meta name="robots" content="noindex, nofollow"></head><body><h1>fixture</h1></body></html>' ;;
esac
if [ -n "$OUT" ]; then printf '%s' "$BODY" > "$OUT"; fi
printf '200'
SH;

        file_put_contents($dir.DIRECTORY_SEPARATOR.'curl', $script);
        @chmod($dir.DIRECTORY_SEPARATOR.'curl', 0755);

        return $dir;
    }

    /**
     * 在受控 PATH＋TMPDIR 下執行 shell script;回 [exitCode, output]。
     *
     * ⛔ Windows 的 escapeshellarg 會毀掉內嵌引號,所以把整段 inner
     * command 寫成 wrapper 檔再交給 bash 執行——cmd 層只有兩個引號安全
     * 的參數(bash.exe 與 wrapper 路徑)。
     */
    private function runScript(string $scriptWinPath, string $args, string $fakeBinDir, string $tmpDir, string $extraExports = ''): array
    {
        $bash = $this->bash();

        $cyg = fn (string $winPath): string => "\"\$(cygpath -u '".$this->msys($winPath)."' 2>/dev/null || echo '".$this->msys($winPath)."')\"";

        $wrapper = $tmpDir.DIRECTORY_SEPARATOR.'wrapper-'.uniqid().'.sh';
        $lines = implode("\n", [
            '#!/usr/bin/env bash',
            'FAKEBIN='.$cyg($fakeBinDir),
            'export PATH="$FAKEBIN:$PATH"',
            'export TMPDIR='.$cyg($tmpDir),
            $extraExports,
            'bash '.$cyg($scriptWinPath).' '.$args.' 2>&1',
            '',
        ]);
        file_put_contents($wrapper, $lines);

        $output = [];
        $exit = 0;
        exec('"'.$bash.'" "'.$wrapper.'"', $output, $exit);

        @unlink($wrapper);

        return [$exit, implode("\n", $output)];
    }

    /** 單引號安全化(路徑不得含單引號;反斜線轉正斜線交給 cygpath)。 */
    private function msys(string $path): string
    {
        $this->assertStringNotContainsString("'", $path);

        return str_replace('\\', '/', $path);
    }

    /** ⛔ P0 反證:全流程 fixture 下 post-deploy check 必須 exit 0,無 unbound variable。 */
    public function test_the_post_deploy_check_passes_end_to_end_against_fixtures(): void
    {
        $fake = $this->makeFakeCurl();
        $tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pdc-tmp-'.uniqid();
        mkdir($tmp, 0777, true);

        [$exit, $output] = $this->runScript(
            base_path(self::DIR.'staging-post-deploy-check.sh'),
            "'https://staging.example.invalid'",
            $fake,
            $tmp,
        );

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('全部通過', $output);
        $this->assertStringNotContainsString('unbound variable', $output);
        // tempfile 清理:受控 TMPDIR 內不得殘留。
        $this->assertSame([], glob($tmp.DIRECTORY_SEPARATOR.'*') ?: [], 'tempfile 未清理');
    }

    /** ⛔ transport failure:明確 BLOCKER、exit 非 0、無 unbound variable、tempfile 清理。 */
    public function test_the_post_deploy_check_fails_closed_on_transport_failure(): void
    {
        $fake = $this->makeFakeCurl();
        $tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pdc-tmp-'.uniqid();
        mkdir($tmp, 0777, true);

        [$exit, $output] = $this->runScript(
            base_path(self::DIR.'staging-post-deploy-check.sh'),
            "'https://staging.example.invalid'",
            $fake,
            $tmp,
            'export FAKE_CURL_MODE=fail;',
        );

        $this->assertSame(1, $exit, $output);
        $this->assertStringContainsString('BLOCKER', $output);
        $this->assertStringContainsString('transport-failure', $output);
        $this->assertStringNotContainsString('unbound variable', $output);
        $this->assertSame([], glob($tmp.DIRECTORY_SEPARATOR.'*') ?: [], 'tempfile 未清理');
    }

    /** base URL 限 https 根網址:非 https、帶 path、帶 userinfo 全部拒絕。 */
    public function test_the_post_deploy_check_rejects_bad_base_urls(): void
    {
        $fake = $this->makeFakeCurl();
        $tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pdc-tmp-'.uniqid();
        mkdir($tmp, 0777, true);

        foreach ([
            "'http://staging.example.invalid'",
            "'https://staging.example.invalid/path'",
            "'https://user@staging.example.invalid'",
            "'https://staging.example.invalid/?q=1'",
        ] as $bad) {
            [$exit, $output] = $this->runScript(
                base_path(self::DIR.'staging-post-deploy-check.sh'),
                $bad,
                $fake,
                $tmp,
            );

            $this->assertSame(1, $exit, $bad.' => '.$output);
            $this->assertStringContainsString('根網址', $output, $bad);
            $this->assertStringNotContainsString('unbound variable', $output, $bad);
        }

        $this->assertSame([], glob($tmp.DIRECTORY_SEPARATOR.'*') ?: []);
    }

    /**
     * ⛔ P1 反證:BACKUP_DIR 在 app tree 內時,deploy script 必須在
     * maintenance／mysqldump／git fetch 之前就停止——以「一被呼叫就寫
     * marker」的 fake php/git/mysqldump 證明它們 0 次被叫。
     */
    public function test_the_deploy_script_guard_stops_before_any_side_effect(): void
    {
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'deploy-guard-'.uniqid();
        $app = $root.DIRECTORY_SEPARATOR.'app';
        mkdir($app.DIRECTORY_SEPARATOR.'public', 0777, true);
        file_put_contents($app.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'index.php', '<?php');

        $badBackup = $app.DIRECTORY_SEPARATOR.'storage-backups';
        mkdir($badBackup, 0777, true);

        // sentinel bin:php/git/mysqldump 一被呼叫就寫 marker。
        $bin = $root.DIRECTORY_SEPARATOR.'bin';
        mkdir($bin, 0777, true);
        $marker = $root.DIRECTORY_SEPARATOR.'SIDE-EFFECT-MARKER';
        $sentinel = "#!/usr/bin/env bash\nprintf touched > \"\$(cygpath -u ".escapeshellarg($marker).' 2>/dev/null || echo '.escapeshellarg($marker).")\"\nexit 1\n";
        foreach (['php', 'git', 'mysqldump', 'composer'] as $tool) {
            file_put_contents($bin.DIRECTORY_SEPARATOR.$tool, $sentinel);
            @chmod($bin.DIRECTORY_SEPARATOR.$tool, 0755);
        }

        $exports = 'export DEPLOY_MODE=git;'
            .'export APP_PATH="$(cygpath -u '.escapeshellarg($app).' 2>/dev/null || echo '.escapeshellarg($app).')";'
            .'export BACKUP_DIR="$(cygpath -u '.escapeshellarg($badBackup).' 2>/dev/null || echo '.escapeshellarg($badBackup).')";'
            .'export PHP_BIN=php;export TARGET_COMMIT=deadbeef;';

        [$exit, $output] = $this->runScript(
            base_path(self::DIR.'deploy-script.example.sh'),
            '',
            $bin,
            $root,
            $exports,
        );

        $this->assertSame(2, $exit, $output);
        $this->assertStringContainsString('path guard', $output);
        $this->assertStringContainsString('位於 APP_PATH', $output);
        // ⛔ 誠實訊息:尚未進 maintenance,而非宣稱維持 maintenance。
        $this->assertStringContainsString('尚未進入 maintenance', $output);
        // ⛔ php/git/mysqldump/composer 0 次被叫。
        $this->assertFileDoesNotExist($marker);
    }

    // ==================================== R1:D-103 單次 302 驗收

    /** 每個 D-103 情境共用的執行器;回 [exitCode, output]。 */
    private function runPostDeploy(string $redirMode = 'ok'): array
    {
        $fake = $this->makeFakeCurl();
        $tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pdc-tmp-'.uniqid();
        mkdir($tmp, 0777, true);

        $result = $this->runScript(
            base_path(self::DIR.'staging-post-deploy-check.sh'),
            "'https://staging.example.invalid'",
            $fake,
            $tmp,
            $redirMode === 'ok' ? '' : 'export FAKE_CURL_REDIR='.$redirMode.';',
        );

        // ⛔ 每條路徑都必須清乾淨 tempfile,失敗路徑也一樣。
        $this->assertSame([], glob($tmp.DIRECTORY_SEPARATOR.'*') ?: [], 'tempfile 未清理');

        return $result;
    }

    /**
     * ⛔ 成功路徑:單次 302 直達 canonical,target 200。
     *
     * 這是這次 R1 的核心——舊腳本期待 `/services/instagram/followers`
     * 直接 200,因此在正確的 staging 上誤報 BLOCKER。
     */
    public function test_the_check_accepts_a_single_302_to_the_product_canonical(): void
    {
        [$exit, $output] = $this->runPostDeploy();

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('全部通過', $output);
        $this->assertStringContainsString('回 302', $output);
        $this->assertStringContainsString('Location 精確等於 canonical', $output);
        $this->assertStringContainsString('canonical target 回 200', $output);
        $this->assertStringNotContainsString('unbound variable', $output);
        $this->assertStringNotContainsString('BLOCKER', $output);
    }

    /**
     * ⛔ 錯誤 Location／外站 Location／缺 Location／非 302／target 仍 redirect
     * 一律 fail closed。
     *
     * 「外站」那一條特別重要:如果只驗「有 302 且最後 200」,一個把使用者
     * 導去別的網域的設定錯誤會完全通過驗收。
     *
     * @return array<string, array{0: string}>
     */
    public static function badRedirectProvider(): array
    {
        return [
            '錯誤 path' => ['wrong-path'],
            '外站 host' => ['other-host'],
            '帶 query' => ['with-query'],
            '缺 Location' => ['no-location'],
            '不是 302' => ['not-302'],
            'target 仍 redirect（chain）' => ['target-redirects'],
        ];
    }

    #[DataProvider('badRedirectProvider')]
    public function test_the_check_fails_closed_on_a_bad_redirect(string $mode): void
    {
        [$exit, $output] = $this->runPostDeploy($mode);

        $this->assertSame(1, $exit, $mode.' 應該 exit 1；實際輸出：'.$output);
        $this->assertStringContainsString('BLOCKER', $output, $mode);
        $this->assertStringNotContainsString('全部通過', $output, $mode);
        // ⛔ set -u 下不得因未初始化變數而中止(那會變成看不懂的失敗)。
        $this->assertStringNotContainsString('unbound variable', $output, $mode);
    }

    /** ⛔ 腳本不得用 curl -L:跟隨轉址會把 chain 掩蓋成漂亮的 200。 */
    public function test_the_check_never_follows_redirects(): void
    {
        $script = $this->artifact('staging-post-deploy-check.sh');

        foreach ([' -L ', '--location', '-sSL', '-L"'] as $needle) {
            $this->assertStringNotContainsString($needle, $script, $needle);
        }

        // 仍必須是 GET/HEAD-only(既有安全限制不因本輪放寬)。
        foreach (['-X POST', '--data', '-F ', 'callback'] as $needle) {
            $this->assertStringNotContainsString($needle, $script, $needle);
        }
    }

    /**
     * ⛔ 不得把 302 擅自升級成 301:正式永久 redirect 屬 M5。
     *
     * 驗的是「比較 status 的那一行」而不是全文有無 `301` 字樣——腳本註解
     * 裡本來就寫著「302 不得改 301」,用全文掃描會把那句提醒本身判成違規。
     */
    public function test_the_check_expects_302_not_a_permanent_redirect(): void
    {
        $script = $this->artifact('staging-post-deploy-check.sh');

        // 實際做判斷的那一行:必須比對 302。
        $this->assertMatchesRegularExpression(
            '/\[\s*"\$REDIR_CODE"\s*=\s*"302"\s*\]/',
            $script,
            '腳本必須明確要求 302',
        );

        // ⛔ 任何「期待 301」的判斷式都不得存在。
        $this->assertDoesNotMatchRegularExpression(
            '/\[\s*"\$REDIR_CODE"\s*=\s*"301"\s*\]/',
            $script,
            '⛔ 不得期待 301：正式永久 redirect 屬 M5',
        );
    }

    // ==================================== R1:artisan executable bit

    /**
     * ⛔ Git index 必須把 `artisan` 記成 100755。
     *
     * RunCloud Supervisor 初次啟動時是 `FATAL ... artisan is not executable`;
     * 在 VPS 上 `chmod 755` 只能救當下那一次,下一次 checkout 會再壞一次。
     * 真正的修法是把 mode 存進 Git。
     *
     * ⛔ 這裡刻意讀 `git ls-files --stage`,不看工作目錄的檔案權限:
     * 本 repo `core.fileMode=false`,而且 Windows 的 working-tree mode
     * 根本不可信——用 `is_executable()` 驗會得到與 Linux 部署無關的答案。
     */
    public function test_git_records_artisan_as_executable(): void
    {
        $output = [];
        $exit = 0;
        exec('git -C '.escapeshellarg(base_path()).' ls-files --stage -- artisan 2>&1', $output, $exit);

        $this->assertSame(0, $exit, '無法讀取 git index：'.implode("\n", $output));
        $this->assertNotEmpty($output);

        // 格式:<mode> <sha> <stage>\t<path>
        $this->assertMatchesRegularExpression(
            '/^100755 [0-9a-f]{40} 0\tartisan$/',
            trim($output[0]),
            'artisan 在 Git index 內必須是 100755；實際：'.trim($output[0]),
        );
    }

    /** guard 的其他 fail-closed 分支:不存在的 BACKUP_DIR 同樣在任何 side effect 前停止。 */
    public function test_the_deploy_script_guard_rejects_a_missing_backup_dir(): void
    {
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'deploy-guard2-'.uniqid();
        $app = $root.DIRECTORY_SEPARATOR.'app';
        mkdir($app.DIRECTORY_SEPARATOR.'public', 0777, true);
        file_put_contents($app.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'index.php', '<?php');

        $bin = $root.DIRECTORY_SEPARATOR.'bin';
        mkdir($bin, 0777, true);
        $marker = $root.DIRECTORY_SEPARATOR.'SIDE-EFFECT-MARKER';
        $sentinel = "#!/usr/bin/env bash\nprintf touched > \"\$(cygpath -u ".escapeshellarg($marker).' 2>/dev/null || echo '.escapeshellarg($marker).")\"\nexit 1\n";
        foreach (['php', 'git', 'mysqldump', 'composer'] as $tool) {
            file_put_contents($bin.DIRECTORY_SEPARATOR.$tool, $sentinel);
            @chmod($bin.DIRECTORY_SEPARATOR.$tool, 0755);
        }

        $exports = 'export DEPLOY_MODE=git;'
            .'export APP_PATH="$(cygpath -u '.escapeshellarg($app).' 2>/dev/null || echo '.escapeshellarg($app).')";'
            .'export BACKUP_DIR=/nonexistent-backup-dir-r1;'
            .'export PHP_BIN=php;export TARGET_COMMIT=deadbeef;';

        [$exit, $output] = $this->runScript(
            base_path(self::DIR.'deploy-script.example.sh'),
            '',
            $bin,
            $root,
            $exports,
        );

        $this->assertSame(2, $exit, $output);
        $this->assertStringContainsString('path guard', $output);
        $this->assertFileDoesNotExist($marker);
    }
}
