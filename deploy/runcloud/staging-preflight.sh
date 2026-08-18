#!/usr/bin/env bash
#
# M4C staging 唯讀 preflight(RunCloud / Linux)。
#
# ⛔ 只做本機唯讀檢查:不寫 .env、不 migrate、不改 DNS/Cloudflare、
# 不啟動任何外部呼叫。真正部署與 sandbox E2E 另案批准。
#
set -u

APP_DIR="${1:-$(pwd)}"
FAIL=0

note() { printf '%s\n' "$*"; }
check() { # label, condition(0=ok)
    if [ "$2" -eq 0 ]; then note "  [ok] $1"; else note "  [BLOCKER] $1"; FAIL=1; fi
}

note "== staging preflight(唯讀)@ ${APP_DIR}"

# PHP 與 cURL
PHP_BIN="${PHP_BIN:-php}"
"$PHP_BIN" -v >/dev/null 2>&1; check "php 可執行(${PHP_BIN})" $?
"$PHP_BIN" -r 'exit(version_compare(PHP_VERSION, "8.2.0", ">=") ? 0 : 1);'; check "PHP >= 8.2" $?
"$PHP_BIN" -r 'exit(extension_loaded("curl") ? 0 : 1);'; check "ext-curl 已載入" $?
"$PHP_BIN" -r '$v = curl_version()["version"] ?? "0"; exit(version_compare($v, "8.4.0", ">=") ? 0 : 1);'
if [ $? -eq 0 ]; then note "  [ok] libcurl >= 8.4(ongoing transfer cap)"; else note "  [blocked] libcurl < 8.4:TheMostPanel dispatch 能力將維持 fail closed(非部署 blocker)"; fi

# document root 與檔案佈局
[ -f "${APP_DIR}/public/index.php" ]; check "document root 應指向 public/(public/index.php 存在)" $?
[ -f "${APP_DIR}/.env" ]; check ".env 存在(⛔ 本 script 不讀 secret 值)" $?

# .env 非機密鍵檢查(只 grep 鍵與安全值,不輸出其他內容)
env_is() { grep -Eq "^$1=$2\s*$" "${APP_DIR}/.env"; }
env_is APP_ENV staging; check "APP_ENV=staging" $?
env_is APP_DEBUG false; check "APP_DEBUG=false" $?
grep -Eq '^APP_URL=https://' "${APP_DIR}/.env"; check "APP_URL 為 https://" $?
env_is ALLOW_INDEXING false; check "ALLOW_INDEXING=false(staging 不可被索引)" $?
grep -Eq '^QUEUE_CONNECTION=(database|redis)\s*$' "${APP_DIR}/.env"; check "QUEUE_CONNECTION 非 sync" $?

# Laravel 唯讀 readiness(本機 CLI,無外部呼叫)
( cd "${APP_DIR}" && "$PHP_BIN" artisan app:staging-readiness --json >/dev/null 2>&1 )
check "php artisan app:staging-readiness(exit 0)" $?

note ""
note "== 人工 checklist(本 script 不代辦)"
note "  - RunCloud web app 指向 ${APP_DIR}/public;HTTPS 憑證有效"
note "  - supervisor/systemd:php artisan queue:work --tries=1(⛔ tries=1,不重試花錢 job)"
note "  - cron:* * * * * php artisan schedule:run(scheduler 內含 10 分鐘輪詢 gate)"
note "  - log rotation 與 storage/logs 權限;DB 備份排程"
note "  - maintenance:php artisan down/up;rollback:git revert + php artisan config:clear"
note "  - 綠界/LINE Pay callback URL 以 staging domain 設定(sandbox 憑證;另案批准後才啟用)"

exit $FAIL
