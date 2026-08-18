#!/usr/bin/env bash
# =====================================================================
# M4C staging post-deploy 唯讀驗收(GET/HEAD only)
# =====================================================================
# 用法:bash staging-post-deploy-check.sh https://<staging-host>[:port]
# ⛔ 只對 Owner 傳入的 base URL 做 GET/HEAD;不送表單、不觸發任何付款
# 通知路由、不付款、不開票、不打供應商、不登入、不建訂單、不跟隨轉址。
#
# R1:fetch 以一般函式呼叫執行(⛔ 不用 command substitution 包函式),
# BODY_FILE/CODE 都活在主 shell;trap 保證任何離開路徑(正常、BLOCKER、
# transport failure、未預期中止)都不殘留 tempfile。
set -u

BASE="${1:?用法:staging-post-deploy-check.sh https://<staging-host>[:port]}"
FAIL=0
BODY_FILE=""
CODE=""

# ⛔ base URL 只接受 https 根網址:拒絕 userinfo、path、query、fragment。
case "$BASE" in
    */) BASE="${BASE%/}" ;;
esac
if ! printf '%s' "$BASE" | grep -Eq '^https://[A-Za-z0-9.-]+(:[0-9]+)?$'; then
    echo "⛔ base URL 必須是 https://host[:port] 根網址(不含 userinfo/path/query/fragment)。" >&2
    exit 1
fi

cleanup_body() {
    if [ -n "${BODY_FILE:-}" ] && [ -f "${BODY_FILE:-}" ]; then
        rm -f "$BODY_FILE"
    fi
    BODY_FILE=""
}
trap cleanup_body EXIT

note() { printf '%s\n' "$*"; }
check() { if [ "$2" -eq 0 ]; then note "  [ok] $1"; else note "  [BLOCKER] $1"; FAIL=1; fi; }

# 一般函式呼叫:在主 shell 設定 CODE 與 BODY_FILE。
# ⛔ curl transport failure → CODE 設為 'transport-failure',由呼叫端
# 轉成明確 BLOCKER,絕不因空值或 unset 變數讓 set -u 中止。
fetch() { # $1 = url
    cleanup_body
    BODY_FILE="$(mktemp)"
    CODE="$(curl -sS -o "$BODY_FILE" -w '%{http_code}' --max-time 20 "$1" 2>/dev/null)" || CODE=""
    if [ -z "$CODE" ] || [ "$CODE" = "000" ]; then
        CODE="transport-failure"
    fi
}

header_has_noindex() { # $1 = url;transport failure 視為未通過
    curl -sSI --max-time 20 "$1" 2>/dev/null | grep -iq '^x-robots-tag:.*noindex'
}

note "== post-deploy 唯讀驗收 @ ${BASE}"

# 健康端點(GET;兩者皆為現行 routes:/up 與 /api/health)
fetch "${BASE}/up"
check "/up 回 200(實際 ${CODE})" "$([ "$CODE" = "200" ]; echo $?)"
fetch "${BASE}/api/health"
check "/api/health 回 200(實際 ${CODE})" "$([ "$CODE" = "200" ]; echo $?)"

# 核心公開頁:200＋noindex header/meta＋單一 H1
for PATHNAME in "/" "/services/instagram" "/services/instagram/followers"; do
    fetch "${BASE}${PATHNAME}"
    check "${PATHNAME} 回 200(實際 ${CODE})" "$([ "$CODE" = "200" ]; echo $?)"

    if [ "$CODE" = "200" ]; then
        H1="$(grep -o '<h1' "$BODY_FILE" | wc -l | tr -d ' ')"
        check "${PATHNAME} 單一 H1(實際 ${H1})" "$([ "$H1" = "1" ]; echo $?)"
        grep -q '<meta name="robots" content="noindex' "$BODY_FILE"
        check "${PATHNAME} meta noindex" $?
    else
        check "${PATHNAME} 單一 H1(未取得 body)" 1
        check "${PATHNAME} meta noindex(未取得 body)" 1
    fi

    header_has_noindex "${BASE}${PATHNAME}"
    check "${PATHNAME} X-Robots-Tag noindex" $?
done

# robots.txt:staging 必須全面 Disallow
fetch "${BASE}/robots.txt"
check "/robots.txt 回 200(實際 ${CODE})" "$([ "$CODE" = "200" ]; echo $?)"
if [ "$CODE" = "200" ]; then
    grep -q 'Disallow: /' "$BODY_FILE"
    check "/robots.txt 含 Disallow: /" $?
else
    check "/robots.txt 含 Disallow: /(未取得 body)" 1
fi

cleanup_body
note ""
if [ "$FAIL" -eq 0 ]; then
    note "== 全部通過(唯讀)。"
else
    note "== 存在 BLOCKER,請人工處置;⛔ 不要在未修復前開放任何能力 flag。"
fi
exit $FAIL
