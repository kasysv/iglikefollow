<?php

namespace Tests\Feature\Operations;

use App\Jobs\SubmitFulfillmentOrder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
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
            'PAYMENTS_SANDBOX_ENABLED=false',
            'INVOICE_SANDBOX_ENABLED=false',
            'FULFILLMENT_DISPATCH_ENABLED=false',
            'FULFILLMENT_STAGING_THEMOSTPANEL_DISPATCH_ENABLED=false',
            'FULFILLMENT_STATUS_POLLING_ENABLED=false',
            'THEMOSTPANEL_READ_ONLY_ENABLED=false',
            'FULFILLMENT_DRIVER=disabled',
            'ALLOW_INDEXING=false',
            'APP_ENV=staging',
            'APP_DEBUG=false',
            'QUEUE_CONNECTION=database',
        ] as $line) {
            $this->assertStringContainsString($line, $env, $line);
        }
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

    /** 建立 fake-curl bin 目錄;⛔ 永不連網,依 URL 回 fixture。 */
    private function makeFakeCurl(string $mode = 'ok'): string
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'fake-curl-'.uniqid();
        mkdir($dir, 0777, true);

        $script = <<<'SH'
#!/usr/bin/env bash
# fake curl:⛔ 不連網;依 URL 回 fixture(1 H1、meta noindex、
# X-Robots noindex、robots Disallow)。FAKE_CURL_MODE=fail 模擬 transport failure。
MODE="${FAKE_CURL_MODE:-ok}"
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
if [ "$HEAD" = "1" ]; then
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
