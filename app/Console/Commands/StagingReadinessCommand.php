<?php

namespace App\Console\Commands;

use App\Enums\FulfillmentPayloadType;
use App\Enums\IntegrationEnvironment;
use App\Enums\IntegrationProvider;
use App\Models\FulfillmentMapping;
use App\Models\ProviderService;
use App\Services\Fulfillment\FulfillmentDispatchGate;
use App\Services\Fulfillment\TheMostPanelCurlCapability;
use App\Services\Integrations\LiveIntegration;
use App\Services\Integrations\ProviderEndpoints;
use App\Support\QuantityCompatibility;
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

        /*
         * ⛔ M4C:逐項回報三個 Owner 通道,不再有一個「付款能力」總結。
         *
         * 一個布林值答不出 Owner 真正需要知道的事:哪一個通道開著、哪一個
         * credential 還缺欄位、以及這台機器到底會不會對外送出請求。
         *
         * ⛔ 單一事實來源:直接問 `LiveIntegration`,與 adapter 完全同源;
         * 不讀已 deprecated 的 sandbox env 旗標,也沒有第二套 alias flags。
         */
        $outbound = LiveIntegration::outboundAllowed();
        $checks[] = self::check(
            'outbound_allowed',
            '此環境可對外送出交易請求',
            $outbound ? 'allowed' : 'blocked(local/testing)',
            $outbound ? 'ok' : 'blocked',
        );

        foreach ([
            ['ecpay_payment', '綠界付款通道', IntegrationProvider::EcpayPayment],
            ['line_pay', 'LINE Pay 通道', IntegrationProvider::LinePay],
            ['ecpay_invoice', '綠界發票通道', IntegrationProvider::EcpayInvoice],
        ] as [$key, $label, $provider]) {
            [$value, $status] = self::channelReadiness($provider, $strict);
            $checks[] = self::check('channel_'.$key, $label, $value, $status);
        }

        // ⛔ 端點必須與版本控制中的白名單完全一致;不符是部署上要修的問題。
        foreach ([
            ['ecpay_payment', '綠界付款端點', ProviderEndpoints::ecpayPayment()],
            ['line_pay', 'LINE Pay API 端點', ProviderEndpoints::linePayApi()],
            ['ecpay_invoice_issue', '綠界發票開立端點', ProviderEndpoints::ecpayInvoiceIssue()],
            ['ecpay_invoice_query', '綠界發票查詢端點', ProviderEndpoints::ecpayInvoiceQuery()],
        ] as [$key, $label, $resolved]) {
            $checks[] = self::check(
                'endpoint_'.$key,
                $label.'(版本控制固定)',
                $resolved !== null ? 'exact' : 'unexpected',
                $resolved !== null ? 'ok' : 'blocker',
            );
        }

        /*
         * ⛔ R1:自動派單逐項回報,與 runtime 完全同源。
         *
         * 不再讀已 deprecated 的 dispatch/staging/polling env 旗標——一個
         * 不影響行為的旗標出現在 readiness 裡,只會讓人以為它還是一道防線。
         * 逐項分開,是因為修法完全不同:開關關著要 Owner 去開;端點不符是
         * 部署要修;runtime 不足要升級主機的 libcurl。
         */
        /*
         * ⛔ presence-based:readiness 任何路徑都不解密(encrypter-spy 測試
         * 釘住),所以 owner_switch 用 raw presence 判斷,而不是叫 gate 解出
         * API Key。live 的技術半邊(端點、runtime)本來就不需要解密。
         */
        $dispatchPresence = self::channelPresence(IntegrationProvider::TheMostPanel);
        $dispatchOwnerSwitch = $dispatchPresence['enabled'] && $dispatchPresence['complete'];
        $dispatchLive = $dispatchOwnerSwitch
            && app()->environment('staging', 'production')
            && FulfillmentDispatchGate::liveCapable();
        $checks[] = self::check(
            'themostpanel_dispatch',
            'TheMostPanel 自動派單',
            'owner_switch='.($dispatchOwnerSwitch ? 'on' : 'off').';live='.($dispatchLive ? 'yes' : 'no'),
            $capabilityStatus($dispatchLive),
        );

        $endpointExact = ProviderEndpoints::theMostPanelDispatch() !== null;
        $checks[] = self::check(
            'themostpanel_endpoint',
            'TheMostPanel 派單端點(版本控制固定)',
            $endpointExact ? 'exact' : 'unexpected',
            $endpointExact ? 'ok' : 'blocker',
        );

        // ⛔ 經 container 解析:預設就是真實 runtime,測試可描述其他機器。
        $capability = app(TheMostPanelCurlCapability::class)->supportsOngoingTransferCap();
        $checks[] = self::check(
            'curl_transfer_cap',
            'cURL 傳輸中止能力(ext-curl;short-write 不挑版本)',
            $capability ? 'supported' : 'unsupported',
            $capability ? 'ok' : ($strict ? 'blocker' : 'blocked'),
        );

        // ⛔ R1:輪詢跟隨自動派單總開關,沒有獨立旗標;presence-based,不解密。
        $checks[] = self::check(
            'status_polling',
            '履約狀態輪詢(隨自動派單總開關)',
            $dispatchLive ? 'enabled' : 'not enabled',
            $dispatchLive ? 'ok' : 'blocked',
        );

        // ---- mapping readiness(3.4;⛔ 不洩漏任何 ID) ----
        [$mappingValue, $mappingStatus] = self::mappingReadiness($strict);
        $checks[] = self::check('fulfillment_mappings', '可派單 mapping', $mappingValue, $mappingStatus);

        /*
         * ---- credential presence(⛔ 只看存在性,不解密) ----
         *
         * ⛔ M4C:全部改看 production 那一列。runtime 只讀它,所以回報 sandbox
         * 列的狀態等於回報一個不影響任何行為的數字。
         */
        foreach ([
            ['ecpay_payment', '綠界付款 credential', IntegrationProvider::EcpayPayment, IntegrationEnvironment::Production],
            ['line_pay', 'LINE Pay credential', IntegrationProvider::LinePay, IntegrationEnvironment::Production],
            ['ecpay_invoice', '綠界發票 credential', IntegrationProvider::EcpayInvoice, IntegrationEnvironment::Production],
            ['themostpanel', 'TheMostPanel credential', IntegrationProvider::TheMostPanel, IntegrationEnvironment::Production],
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

    /**
     * One Owner channel: credential completeness, the Owner switch, and whether
     * it could actually run right now.
     *
     * ⛔ 三件事分開回報,因為它們的修法完全不同:缺欄位要 Owner 去填、開關關著
     * 要 Owner 去開、環境不允許外呼則兩者都不是問題(本機就該是這樣)。
     * 併成一個 'not enabled' 會讓 Owner 不知道該做什麼。
     *
     * ⛔ 只回欄位名稱與布林狀態,不解密、不輸出任何值。
     *
     * ⛔ R1:presence-based——readiness 的鐵律是「任何路徑都不解密」(有
     * encrypter-spy 測試釘住),所以這裡以 raw query 看 payload 是否存在,
     * 而不是叫 model cast 解出每一個欄位。代價是一個誠實記錄的角落:密文
     * 存在但已損壞時,這裡回報 stored,而 runtime 的完整檢查會在實際使用時
     * fail closed。readiness 的職責是「不碰密鑰地說出目前狀態」,不是替
     * runtime 預演解密。
     *
     * @return array{0: string, 1: string}
     */
    private static function channelReadiness(IntegrationProvider $provider, bool $strict): array
    {
        try {
            $presence = self::channelPresence($provider);

            $live = LiveIntegration::outboundAllowed()
                && $presence['enabled']
                && $presence['complete'];

            $value = 'credential='.($presence['complete'] ? 'complete' : 'missing:'.implode(',', $presence['missing']))
                .';owner_switch='.($presence['enabled'] ? 'on' : 'off')
                .';live='.($live ? 'yes' : 'no');

            return [$value, $live ? 'ok' : ($strict ? 'blocker' : 'blocked')];
        } catch (Throwable) {
            return ['unavailable', 'blocker'];
        }
    }

    /**
     * Presence-only look at one provider's production row. ⛔ Raw query,
     * no Eloquent, no encrypted cast — nothing here can ever decrypt.
     *
     * 缺整份 payload 時,所有 secret 欄位名稱都可以列出來(不需要解密);
     * ⛔ 「payload 存在但缺其中一個欄位」這種部分缺漏,只有解密才分得出來,
     * 所以這裡一律視為 stored——runtime 的完整檢查會擋住真正的缺漏。
     *
     * @return array{enabled: bool, complete: bool, missing: list<string>}
     */
    private static function channelPresence(IntegrationProvider $provider): array
    {
        $row = DB::table('integration_settings')
            ->where('provider', $provider->value)
            ->where('environment', IntegrationEnvironment::Production->value)
            ->first(['is_enabled', 'identifier', 'credentials']);

        $identifierOk = $provider->identifierLabel() === null || filled($row?->identifier);
        $payloadStored = filled($row?->credentials);

        $missing = [];

        if (! $identifierOk) {
            $missing[] = $provider->identifierLabel();
        }

        if (! $payloadStored) {
            foreach ($provider->secretKeys() as $key) {
                $missing[] = $provider->secretLabel($key);
            }
        }

        return [
            'enabled' => (bool) ($row->is_enabled ?? false),
            'complete' => $identifierOk && $payloadStored,
            'missing' => $missing,
        ];
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
     * ⛔ Presence only, R1-hardened: raw query builder, no Eloquent model,
     * no encrypted cast, no hasSecret()/isFullyConfigured()/secret() — the
     * ciphertext is never decrypted on any readiness path. "stored" means
     * exactly that: an encrypted payload exists. It never claims the payload
     * is complete, decryptable or that the key works.
     *
     * @return array{0: string, 1: string}
     */
    private static function credentialPresence(
        IntegrationProvider $provider,
        IntegrationEnvironment $environment,
        bool $strict,
    ): array {
        try {
            $rows = DB::table('integration_settings')
                ->where('provider', $provider->value)
                ->where('environment', $environment->value)
                ->get(['is_enabled', 'identifier', 'credentials']);

            if ($rows->count() === 0) {
                return ['absent', $strict ? 'blocker' : 'blocked'];
            }

            if ($rows->count() > 1) {
                // 多列無法辨識,部署上是要修的問題。
                return ['duplicate rows', 'blocker'];
            }

            $row = $rows->first();

            $identifierRequired = $provider->identifierLabel() !== null;
            $identifierState = $identifierRequired
                ? (filled($row->identifier) ? 'present' : 'missing')
                : 'not-required';
            $payloadStored = filled($row->credentials);

            $value = 'present;enabled='.($row->is_enabled ? 'yes' : 'no')
                .';encrypted_payload='.($payloadStored ? 'stored' : 'missing')
                .';identifier='.$identifierState;

            $usable = (bool) $row->is_enabled
                && $payloadStored
                && ($identifierState !== 'missing');

            return [$value, $usable ? 'ok' : ($strict ? 'blocker' : 'blocked')];
        } catch (Throwable) {
            return ['unavailable', 'blocker'];
        }
    }

    /**
     * Mapping readiness without leaking a single ID (3.4).
     *
     * ⛔ `enabled_compatible` re-verifies every enabled mapping against the
     * SAME rules the mapping form enforces: provider row exists and is
     * available, payload type is the supported enum value, and
     * QuantityCompatibility::assess() says compatible. `is_enabled` alone is
     * never trusted; malformed or missing provider rows fail closed.
     *
     * @return array{0: string, 1: string}
     */
    private static function mappingReadiness(bool $strict): array
    {
        try {
            $total = FulfillmentMapping::query()->count();
            $enabled = FulfillmentMapping::query()->where('is_enabled', true)->with('serviceVariant')->get();

            $compatible = 0;

            foreach ($enabled as $mapping) {
                $variant = $mapping->serviceVariant;

                if ($variant === null) {
                    continue;
                }

                if ($mapping->payload_type !== FulfillmentPayloadType::LinkQuantity) {
                    continue;
                }

                $service = ProviderService::query()
                    ->where('provider', $mapping->provider)
                    ->where('provider_service_id', $mapping->provider_service_id)
                    ->where('is_available', true)
                    ->first();

                if ($service === null) {
                    continue;
                }

                if (QuantityCompatibility::assess($variant, $service)->compatible) {
                    $compatible++;
                }
            }

            $value = 'total='.$total.';enabled='.$enabled->count().';enabled_compatible='.$compatible;

            return [$value, $compatible > 0 ? 'ok' : ($strict ? 'blocker' : 'blocked')];
        } catch (Throwable) {
            return ['unavailable', 'blocker'];
        }
    }
}
