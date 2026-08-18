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
}
