#!/usr/bin/env bash
# =====================================================================
# M4C staging post-deploy 唯讀驗收(GET/HEAD only)
# =====================================================================
# 用法:bash staging-post-deploy-check.sh https://<staging-subdomain>
# ⛔ 只對 Owner 傳入的 base URL 做 GET/HEAD;不送表單、不觸發任何付款
# 通知路由、不付款、不開票、不打供應商、不登入、不建訂單。
set -u

BASE="${1:?用法:staging-post-deploy-check.sh https://<staging-base-url>}"
FAIL=0

case "$BASE" in
    https://*) : ;;
    *) echo "⛔ base URL 必須是 https://"; exit 1 ;;
esac
BASE="${BASE%/}"

note() { printf '%s\n' "$*"; }
check() { if [ "$2" -eq 0 ]; then note "  [ok] $1"; else note "  [BLOCKER] $1"; FAIL=1; fi; }

fetch() { # url -> body 存到 $BODY_FILE,回 http code
    BODY_FILE="$(mktemp)"
    curl -sS -o "$BODY_FILE" -w '%{http_code}' --max-time 20 "$1" 2>/dev/null
}

header_has_noindex() { # url
    curl -sSI --max-time 20 "$1" 2>/dev/null | grep -iq '^x-robots-tag:.*noindex'
}

note "== post-deploy 唯讀驗收 @ ${BASE}"

# 健康端點(GET;兩者皆為現行 routes:/up 與 /api/health)
CODE="$(fetch "${BASE}/up")";        check "/up 回 200(實際 ${CODE})" "$([ "$CODE" = "200" ]; echo $?)"
CODE="$(fetch "${BASE}/api/health")"; check "/api/health 回 200(實際 ${CODE})" "$([ "$CODE" = "200" ]; echo $?)"

# 核心公開頁:200＋noindex header＋單一 H1
for PATHNAME in "/" "/services/instagram" "/services/instagram/followers"; do
    CODE="$(fetch "${BASE}${PATHNAME}")"
    check "${PATHNAME} 回 200(實際 ${CODE})" "$([ "$CODE" = "200" ]; echo $?)"
    H1=$(grep -o '<h1' "$BODY_FILE" | wc -l | tr -d ' ')
    check "${PATHNAME} 單一 H1(實際 ${H1})" "$([ "$H1" = "1" ]; echo $?)"
    grep -q '<meta name="robots" content="noindex' "$BODY_FILE"
    check "${PATHNAME} meta noindex" $?
    header_has_noindex "${BASE}${PATHNAME}"
    check "${PATHNAME} X-Robots-Tag noindex" $?
    rm -f "$BODY_FILE"
done

# robots.txt:staging 必須全面 Disallow
CODE="$(fetch "${BASE}/robots.txt")"
check "/robots.txt 回 200(實際 ${CODE})" "$([ "$CODE" = "200" ]; echo $?)"
grep -q 'Disallow: /' "$BODY_FILE"; check "/robots.txt 含 Disallow: /" $?
rm -f "$BODY_FILE"

note ""
if [ "$FAIL" -eq 0 ]; then note "== 全部通過(唯讀)。"; else note "== 存在 BLOCKER,請人工處置;⛔ 不要在未修復前開放任何能力 flag。"; fi
exit $FAIL
