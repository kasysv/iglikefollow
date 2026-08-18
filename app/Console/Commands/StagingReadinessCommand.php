<?php

namespace App\Console\Commands;

use App\Enums\IntegrationEnvironment;
use App\Enums\IntegrationProvider;
use App\Models\IntegrationSetting;
use App\Services\Fulfillment\TheMostPanelCurlCapability;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * `app:staging-readiness` — the truth about this deployment, safely.
 *
 * ⛔ Status only. No secret, no PII, no provider response, no full service id
 * ever appears in any output mode. Credential rows are reported by PRESENCE
 * (row exists / enabled / fully configured) — nothing is decrypted to answer
 * "is it there".
 *
 * ⛔ Two vocabularies, deliberately separate:
 *   - `blocker`  — this deployment is unsafe or broken (wrong env, http URL,
 *     debug on, indexable staging, sync queue, missing tables, pending
 *     migrations). Exit code goes non-zero.
 *   - `blocked`  — a capability that is intentionally OFF. That is the
 *     expected default state, not an error; it only becomes a blocker under
 *     `--strict-live-readiness`, which asks "could payments/invoices/dispatch
 *     actually run right now".
 *
 * The report builder is static so the Owner-only readiness page renders the
 * exact same facts — one source of truth, two surfaces.
 */
class StagingReadinessCommand extends Command
{
    protected $signature = 'app:staging-readiness {--json : 機器可讀輸出} {--strict-live-readiness : 未開啟的付款/發票/派單能力視為失敗}';

    protected $description = 'Staging 部署前的唯讀 readiness 檢查(無 secret、無外部呼叫)';

    public function handle(): int
    {
        $report = self::report((bool) $this->option('strict-live-readiness'));

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->table(
                ['check', 'value', 'status'],
                array_map(
                    fn (array $check) => [$check['label'], $check['value'], $check['status']],
                    $report['checks'],
                ),
            );
            $this->line('blockers: '.$report['blockers'].';blocked(能力未開,預期狀態): '.$report['blocked']);
        }

        return $report['blockers'] === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * The one report both the CLI and the Owner page render.
     *
     * @return array{strict: bool, checks: list<array{key: string, label: string, value: string, status: string}>, blockers: int, blocked: int}
     */
    public static function report(bool $strict = false): array
    {
        $checks = [];

        // ---- 部署基礎(錯了就是 blocker) ----
        $env = app()->environment();
        $checks[] = self::check('app_env', 'APP_ENV', $env, $env === 'staging' ? 'ok' : 'blocker');

        $url = (string) config('app.url');
        $checks[] = self::check('app_url_https', 'APP_URL 使用 HTTPS', $url, str_starts_with($url, 'https://') ? 'ok' : 'blocker');

        $debug = (bool) config('app.debug');
        $checks[] = self::check('app_debug_off', 'APP_DEBUG 關閉', $debug ? 'true' : 'false', $debug ? 'blocker' : 'ok');

        // ⛔ 可索引的 staging 是 blocker:staging 永遠不該進搜尋結果。
        $indexing = (bool) config('seo.allow_indexing');
        $checks[] = self::check('allow_indexing_off', 'ALLOW_INDEXING 關閉', $indexing ? 'true' : 'false', $indexing ? 'blocker' : 'ok');

        $queueDriver = (string) config('queue.default');
        $checks[] = self::check('queue_not_sync', 'Queue driver 非 sync', $queueDriver, $queueDriver !== 'sync' ? 'ok' : 'blocker');

        foreach (['cache', 'jobs', 'failed_jobs'] as $table) {
            [$value, $status] = self::tableAvailability($table);
            $checks[] = self::check('table_'.$table, 'table `'.$table.'` 可用', $value, $status);
        }

        [$pendingValue, $pendingStatus] = self::pendingMigrations();
        $checks[] = self::check('migrations_pending', 'Migration 無 pending', $pendingValue, $pendingStatus);

        // ---- 能力開關(off 是預期的 blocked;strict 才算失敗) ----
        $capabilityStatus = fn (bool $on): string => $on ? 'ok' : ($strict ? 'blocker' : 'blocked');

        $sandboxPayment = (bool) config('integrations.sandbox_payment_enabled');
        $checks[] = self::check('sandbox_payment', 'Sandbox 付款能力', $sandboxPayment ? 'enabled' : 'not enabled', $capabilityStatus($sandboxPayment));

        $sandboxInvoice = (bool) config('integrations.sandbox_invoice_enabled');
        $checks[] = self::check('sandbox_invoice', 'Sandbox 發票能力', $sandboxInvoice ? 'enabled' : 'not enabled', $capabilityStatus($sandboxInvoice));

        $stagingDispatchFlag = (bool) config('fulfillment.staging.themostpanel_dispatch_enabled');
        $dispatchDriver = (string) config('fulfillment.driver');
        $dispatchSwitch = (bool) config('fulfillment.dispatch_enabled');
        $dispatchOn = $stagingDispatchFlag && $dispatchSwitch && $dispatchDriver === 'themostpanel';
        $checks[] = self::check(
            'themostpanel_staging_dispatch',
            'TheMostPanel staging 派單能力',
            'flag='.($stagingDispatchFlag ? 'on' : 'off').';driver='.$dispatchDriver.';dispatch='.($dispatchSwitch ? 'on' : 'off'),
            $capabilityStatus($dispatchOn),
        );

        $endpoint = (string) config('integrations.endpoints.themostpanel.staging');
        $checks[] = self::check(
            'themostpanel_staging_endpoint',
            'TheMostPanel staging endpoint(版本控制固定)',
            $endpoint === 'https://themostpanel.com/api/v2' ? 'exact' : 'unexpected',
            $endpoint === 'https://themostpanel.com/api/v2' ? 'ok' : 'blocker',
        );

        $capability = TheMostPanelCurlCapability::fromRuntime()->supportsOngoingTransferCap();
        $checks[] = self::check(
            'curl_transfer_cap',
            'cURL ongoing-transfer cap(libcurl ≥ 8.4)',
            $capability ? 'supported' : 'unsupported',
            $capability ? 'ok' : ($strict ? 'blocker' : 'blocked'),
        );

        $polling = (bool) config('fulfillment.status_polling_enabled');
        $checks[] = self::check('status_polling', '履約狀態輪詢', $polling ? 'enabled' : 'not enabled', $polling ? 'ok' : 'blocked');

        // ---- credential presence(⛔ 只看存在性,不解密) ----
        foreach ([
            ['ecpay_payment_sandbox', '綠界付款(sandbox)credential', IntegrationProvider::EcpayPayment, IntegrationEnvironment::Sandbox],
            ['line_pay_sandbox', 'LINE Pay(sandbox)credential', IntegrationProvider::LinePay, IntegrationEnvironment::Sandbox],
            ['ecpay_invoice_sandbox', '綠界發票(sandbox)credential', IntegrationProvider::EcpayInvoice, IntegrationEnvironment::Sandbox],
            ['themostpanel_production', 'TheMostPanel credential', IntegrationProvider::TheMostPanel, IntegrationEnvironment::Production],
        ] as [$key, $label, $provider, $environment]) {
            [$value, $status] = self::credentialPresence($provider, $environment, $strict);
            $checks[] = self::check('credential_'.$key, $label, $value, $status);
        }

        $blockers = count(array_filter($checks, fn (array $check) => $check['status'] === 'blocker'));
        $blocked = count(array_filter($checks, fn (array $check) => $check['status'] === 'blocked'));

        return [
            'strict' => $strict,
            'checks' => $checks,
            'blockers' => $blockers,
            'blocked' => $blocked,
        ];
    }

    /** @return array{key: string, label: string, value: string, status: string} */
    private static function check(string $key, string $label, string $value, string $status): array
    {
        return ['key' => $key, 'label' => $label, 'value' => $value, 'status' => $status];
    }

    /** @return array{0: string, 1: string} */
    private static function tableAvailability(string $table): array
    {
        try {
            if (! Schema::hasTable($table)) {
                return ['missing', 'blocker'];
            }

            $count = DB::table($table)->count();

            return ['rows='.$count, 'ok'];
        } catch (Throwable) {
            return ['unavailable', 'blocker'];
        }
    }

    /** @return array{0: string, 1: string} */
    private static function pendingMigrations(): array
    {
        try {
            $migrator = app('migrator');
            $files = $migrator->getMigrationFiles(database_path('migrations'));

            if (! $migrator->repositoryExists()) {
                return ['repository missing', 'blocker'];
            }

            $ran = $migrator->getRepository()->getRan();
            $pending = count(array_diff(array_keys($files), $ran));

            return ['pending='.$pending, $pending === 0 ? 'ok' : 'blocker'];
        } catch (Throwable) {
            return ['unavailable', 'blocker'];
        }
    }

    /**
     * ⛔ Presence only — hasSecret()/isFullyConfigured() never decrypt.
     *
     * @return array{0: string, 1: string}
     */
    private static function credentialPresence(
        IntegrationProvider $provider,
        IntegrationEnvironment $environment,
        bool $strict,
    ): array {
        try {
            $settings = IntegrationSetting::query()
                ->where('provider', $provider)
                ->where('environment', $environment)
                ->get();

            if ($settings->count() === 0) {
                return ['absent', $strict ? 'blocker' : 'blocked'];
            }

            if ($settings->count() > 1) {
                // 多列無法辨識,部署上是要修的問題。
                return ['duplicate rows', 'blocker'];
            }

            $setting = $settings->first();
            $value = 'present;enabled='.($setting->is_enabled ? 'yes' : 'no')
                .';configured='.($setting->isFullyConfigured() ? 'yes' : 'no');

            $usable = $setting->is_enabled && $setting->isFullyConfigured();

            return [$value, $usable ? 'ok' : ($strict ? 'blocker' : 'blocked')];
        } catch (Throwable) {
            return ['unavailable', 'blocker'];
        }
    }
}
