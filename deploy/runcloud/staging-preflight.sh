#!/usr/bin/env bash
#
# M4C staging 唯讀 preflight(RunCloud / Linux)。
#
# ⛔ 只做本機唯讀檢查:不寫 .env、不建立測試檔、不變更任何權限、不
# migrate、不改 DNS/Cloudflare、不啟動任何外部呼叫。⛔ 永不輸出 .env
# 內容、APP_KEY、DB 連線字串或任何 secret 值——APP_KEY 只回報有/無。
#
set -u

APP_DIR="${1:-$(pwd)}"
FAIL=0

note() { printf '%s\n' "$*"; }
check() { if [ "$2" -eq 0 ]; then note "  [ok] $1"; else note "  [BLOCKER] $1"; FAIL=1; fi; }

note "== staging preflight(唯讀)@ ${APP_DIR}"

# ---- PHP 與 platform requirements ----
PHP_BIN="${PHP_BIN:-php}"
"$PHP_BIN" -v >/dev/null 2>&1; check "php 可執行(${PHP_BIN})" $?
"$PHP_BIN" -r 'exit(version_compare(PHP_VERSION, "8.2.0", ">=") ? 0 : 1);'; check "PHP >= 8.2" $?
for EXT in curl pdo_mysql mbstring openssl json; do
    "$PHP_BIN" -r "exit(extension_loaded('${EXT}') ? 0 : 1);"; check "ext-${EXT} 已載入" $?
done
# ⛔ R1(curl 7.68):傳輸中止改由 bounded sink short write 執行,不挑 libcurl
# 版本;ext-curl 存在(上面已檢查)就是完整能力。舊的 >= 8.4 檢查已移除——
# 一個不再對應任何 runtime 行為的門檻留在 preflight 裡,只會讓人以為 staging
# 的 7.68 不能派單,而那正是這一輪修掉的誤解。libcurl 版本僅供參考:
"$PHP_BIN" -r '$v = curl_version()["version"] ?? "unknown"; echo "  [info] libcurl version: {$v} (short-write abort works on any version)" . PHP_EOL;'

# ---- 檔案佈局 ----
[ -f "${APP_DIR}/public/index.php" ]; check "document root 應指向 public/(public/index.php 存在)" $?
[ -f "${APP_DIR}/.env" ]; check ".env 存在(⛔ 本 script 不讀 secret 值)" $?

# ---- .env 非機密鍵(只 grep 鍵與安全值) ----
env_is() { grep -Eq "^$1=$2\s*$" "${APP_DIR}/.env"; }
env_is APP_ENV staging; check "APP_ENV=staging" $?
env_is APP_DEBUG false; check "APP_DEBUG=false" $?
grep -Eq '^APP_URL=https://' "${APP_DIR}/.env"; check "APP_URL 為 https://" $?
env_is ALLOW_INDEXING false; check "ALLOW_INDEXING=false(staging 不可被索引)" $?
grep -Eq '^QUEUE_CONNECTION=(database|redis)\s*$' "${APP_DIR}/.env"; check "QUEUE_CONNECTION 非 sync" $?
# ---- 付款／發票／自動派單／輪詢:⛔ 不再以 env 旗標宣稱「一定關閉」(M4C+R1) ----
#
# ⛔ PAYMENTS_SANDBOX_ENABLED／INVOICE_SANDBOX_ENABLED／
# FULFILLMENT_DISPATCH_ENABLED／FULFILLMENT_STAGING_THEMOSTPANEL_DISPATCH_ENABLED／
# FULFILLMENT_STATUS_POLLING_ENABLED 已全部 deprecated,staging／production
# runtime 完全不讀(FULFILLMENT_DRIVER 亦只剩 local/testing 的測試路徑選擇
# 作用)。把 `=false` 當成「一定關閉」的證據,是一個看起來通過、實際上什麼都
# 沒驗到的檢查——而它會讓人以為部署是安全的。
#
# 真正的開關是 Owner 後台的 production `integration_settings.is_enabled`。
# 這件事只有應用程式自己答得出來,所以交給 `app:staging-readiness` 的
# channel_*／themostpanel_dispatch 逐項回報,⛔ 不在 shell 裡重寫一份會
# 漂移的判斷。
echo "  note: 付款／發票／自動派單是否關閉,請以 'php artisan app:staging-readiness'"
echo "        的 channel_* 與 themostpanel_dispatch 逐項結果為準;⛔ env 旗標已不再是證據。"

# APP_KEY:⛔ 只驗 presence(鍵存在且值非空),完全不輸出值。
grep -Eq '^APP_KEY=.+$' "${APP_DIR}/.env"; check "APP_KEY 已設定(只驗有/無)" $?

# ---- 可寫性(⛔ 只用 -w 測試,不建立檔案) ----
[ -w "${APP_DIR}/storage" ]; check "storage/ 可寫" $?
[ -w "${APP_DIR}/bootstrap/cache" ]; check "bootstrap/cache/ 可寫" $?

# ---- route presence(唯讀 artisan;無外部呼叫) ----
ROUTES="$(cd "${APP_DIR}" && "$PHP_BIN" artisan route:list 2>/dev/null)"
printf '%s' "$ROUTES" | grep -q 'payments/ecpay/callback'; check "route:POST /payments/ecpay/callback 存在" $?
printf '%s' "$ROUTES" | grep -q 'payments/linepay/{reference}/confirm'; check "route:GET /payments/linepay/{reference}/confirm 存在" $?
printf '%s' "$ROUTES" | grep -q 'api/health'; check "route:GET /api/health 存在" $?

# ---- Laravel readiness(本機 CLI,無外部呼叫) ----
( cd "${APP_DIR}" && "$PHP_BIN" artisan app:staging-readiness --json >/dev/null 2>&1 )
check "php artisan app:staging-readiness(exit 0)" $?

note ""
note "== 人工 checklist(本 script 不代辦)"
note "  - RunCloud web app 指向 ${APP_DIR}/public;HTTPS 憑證有效"
note "  - queue worker 依 queue-worker.conf.example(--tries=3 --timeout=60;job \$tries 優先)"
note "  - cron 依 scheduler.cron.example 每分鐘 schedule:run"
note "  - 部署順序與 rollback 分層見 staging-deployment-plan.md"
note "  - 部署後執行 staging-post-deploy-check.sh <https-base-url>"

exit $FAIL
