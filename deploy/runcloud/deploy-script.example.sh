#!/usr/bin/env bash
# =====================================================================
# M4C staging deploy script 範本(⛔ 未執行;Owner 審閱後才用)
# =====================================================================
# ⛔ mode guard:一般 Git deployment 與 RunCloud Atomic deployment 的
# release path 生命週期不同(Atomic 由 RunCloud clone/activate/symlink,
# 部分步驟屬於其 deployment script hooks)。在 Owner 回覆方案之前,本
# 範本只支援 DEPLOY_MODE=git;Atomic 需另一份對應 hooks 的範本。
# ⛔ 不含網域供應商操作、機密值、金流、發票、供應商 API 或訂單指令。
set -euo pipefail

# ---- 參數(全部顯式;缺一即停) ----
DEPLOY_MODE="${DEPLOY_MODE:?請設定 DEPLOY_MODE=git(Atomic 尚未支援,見上方 mode guard)}"
APP_PATH="${APP_PATH:?請設定 APP_PATH=/home/<runcloud-user>/webapps/<app-name>}"
PHP_BIN="${PHP_BIN:?請設定 PHP_BIN=/usr/bin/php}"
TARGET_COMMIT="${TARGET_COMMIT:?請設定 TARGET_COMMIT=<要部署的精確 commit>}"
BACKUP_DIR="${BACKUP_DIR:?請設定 BACKUP_DIR=<資料庫備份目錄>}"

if [ "$DEPLOY_MODE" != "git" ]; then
    echo "⛔ mode guard:只支援 DEPLOY_MODE=git;Atomic 需依 RunCloud hooks 另立範本。" >&2
    exit 2
fi

# ---- R1 path guard(⛔ maintenance 尚未開啟:失敗就直接退出,誠實地
# 不宣稱站台在 maintenance;任何 backup/checkout/migrate 都未發生) ----
guard_fail() {
    echo "⛔ path guard:$1(站台尚未進入 maintenance,未做任何變更)。" >&2
    exit 2
}

echo "== 0/8 path guards(canonical absolute paths,fail closed)"
[ -d "$APP_PATH" ] || guard_fail "APP_PATH 不存在或不是目錄:${APP_PATH}"
[ -d "$BACKUP_DIR" ] || guard_fail "BACKUP_DIR 不存在或不是目錄:${BACKUP_DIR}"

APP_REAL="$(realpath "$APP_PATH" 2>/dev/null)" || guard_fail "APP_PATH 無法 canonical 化"
BACKUP_REAL="$(realpath "$BACKUP_DIR" 2>/dev/null)" || guard_fail "BACKUP_DIR 無法 canonical 化"

[ -f "${APP_REAL}/public/index.php" ] || guard_fail "APP_PATH/public/index.php 不存在(public root 錯誤)"
[ -w "$BACKUP_REAL" ] || guard_fail "BACKUP_DIR 不可寫"

# ⛔ dump 絕不可落在 application/web tree:等於 app path、位於其任一
# 子目錄(含 public/release tree)一律 fail closed。
case "${BACKUP_REAL}/" in
    "${APP_REAL}/"*)
        guard_fail "BACKUP_DIR(${BACKUP_REAL})位於 APP_PATH(${APP_REAL})內"
        ;;
esac

cd "$APP_REAL"

fail_stop() {
    # ⛔ 任一步失敗:保留 maintenance,絕不自稱已 rollback。
    echo "" >&2
    echo "⛔ 部署在步驟「$1」失敗。站台維持 maintenance(php artisan down)。" >&2
    echo "   請人工判斷:code 可 git revert;資料庫 rollback 需逐支 migration 評估" >&2
    echo "   並先驗證備份(${BACKUP_DIR}),⛔ 不得直接以整庫備份覆蓋。" >&2
    exit 1
}

echo "== 1/8 確認 commit 與環境"
git fetch --all --quiet || fail_stop "git fetch"
git rev-parse --verify "${TARGET_COMMIT}^{commit}" >/dev/null || fail_stop "commit 驗證"
grep -Eq '^APP_ENV=staging$' .env || fail_stop "APP_ENV=staging 檢查"

echo "== 2/8 maintenance on"
"$PHP_BIN" artisan down || fail_stop "artisan down"

echo "== 3/8 backup gate(migrate 之前必須有可驗證備份)"
STAMP="$(date +%Y%m%d-%H%M%S)"
# 依 Owner 的 DB 方案填入實際 dump 指令;⛔ dump 檔不可進 web root。
mysqldump --defaults-extra-file="${HOME}/.my.cnf" "<staging-db-name>" \
    > "${BACKUP_REAL}/pre-deploy-${STAMP}.sql" || fail_stop "database backup"
[ -s "${BACKUP_REAL}/pre-deploy-${STAMP}.sql" ] || fail_stop "backup 非空驗證"

echo "== 4/8 checkout 指定 commit 與 dependencies"
git checkout --detach "${TARGET_COMMIT}" || fail_stop "git checkout"
composer install --no-dev --prefer-dist --no-interaction || fail_stop "composer install"

echo "== 5/8 migrate(backup gate 已過)"
"$PHP_BIN" artisan migrate --force || fail_stop "migrate"

echo "== 6/8 cache 重建"
"$PHP_BIN" artisan config:cache || fail_stop "config:cache"
"$PHP_BIN" artisan route:cache || fail_stop "route:cache"
"$PHP_BIN" artisan view:cache || fail_stop "view:cache"

echo "== 7/8 queue worker reload(long-lived process 必須吃到新版)"
"$PHP_BIN" artisan queue:restart || fail_stop "queue:restart"

echo "== 8/8 readiness(blocker 即停,維持 maintenance)"
"$PHP_BIN" artisan app:staging-readiness || fail_stop "app:staging-readiness"

"$PHP_BIN" artisan up || fail_stop "artisan up"
echo "== 完成:請再執行 staging-post-deploy-check.sh <https-base-url> 做唯讀驗收。"
