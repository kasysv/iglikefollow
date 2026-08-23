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
# ⛔ set -u 下所有變數都要先有初值,否則某條失敗路徑會讓腳本以
# 「unbound variable」中止,而不是印出明確的 BLOCKER。
REDIR_HEADERS=""
REDIR_CODE=""
REDIR_LOCATION=""
LOCATION_OK=1
H1=""

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

# ⛔ 只取 header,絕不跟隨轉址(無 -L):要驗「單次 302 直達」就不能讓
# curl 幫忙走完,否則 chain 會被掩蓋成一個漂亮的 200。
# 設定 REDIR_CODE 與 REDIR_LOCATION;transport failure 一律 fail closed。
fetch_redirect() { # $1 = url
    REDIR_HEADERS="$(curl -sSI --max-time 20 "$1" 2>/dev/null)" || REDIR_HEADERS=""
    if [ -z "$REDIR_HEADERS" ]; then
        REDIR_CODE="transport-failure"
        REDIR_LOCATION=""
        return
    fi
    REDIR_CODE="$(printf '%s' "$REDIR_HEADERS" | awk 'toupper($1) ~ /^HTTP/ {print $2}' | tail -n 1)"
    [ -n "$REDIR_CODE" ] || REDIR_CODE="transport-failure"
    REDIR_LOCATION="$(printf '%s' "$REDIR_HEADERS" \
        | grep -i '^location:' | tail -n 1 \
        | sed -e 's/^[Ll][Oo][Cc][Aa][Tt][Ii][Oo][Nn]:[[:space:]]*//' -e 's/[[:space:]]*$//')"
}

# percent-encoding 等價比較:只把 %XX 正規化成大寫,⛔ 不解碼、不放寬
# host/query/fragment 的比對(解碼會讓 %2F 之類的字元混進 path 判斷)。
canon_url() { # $1 = url
    printf '%s' "$1" | sed -e 's/%\([0-9a-fA-F][0-9a-fA-F]\)/%\U\1/g'
}

note "== post-deploy 唯讀驗收 @ ${BASE}"

# 健康端點(GET;兩者皆為現行 routes:/up 與 /api/health)
fetch "${BASE}/up"
check "/up 回 200(實際 ${CODE})" "$([ "$CODE" = "200" ]; echo $?)"
fetch "${BASE}/api/health"
check "/api/health 回 200(實際 ${CODE})" "$([ "$CODE" = "200" ]; echo $?)"

# 核心公開頁:200＋noindex header/meta＋單一 H1
# ⛔ /services/{platform}/{service} 已不在此清單:依 D-103 它是 302 到
# 商品 canonical,不是 200。它由下面的專門區塊驗證。
for PATHNAME in "/" "/services/instagram"; do
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

# =====================================================================
# D-103:/services/{platform}/{service} 單次 302 → 商品 canonical
# =====================================================================
# ⛔ 這裡驗的是「單次直達」,不是「最後有沒有 200」。因此:
#   - 用 HEAD 取 header,⛔ 不用 curl -L(它會把 chain 走成假的成功);
#   - Location 必須精確等於同一個 base host 下的 canonical(允許
#     percent-encoding 等價),⛔ 外站、query、fragment 一律拒絕;
#   - target 由腳本自己直接 GET,必須 200 且本身不再 redirect。
# ⛔ 302 不得改 301:正式永久 redirect 屬 M5,不在部署驗收裡擅自升級。
SERVICE_PATH="/services/instagram/followers"
CANONICAL_PATH="/product/ig%E8%B2%B7%E7%B2%89%E7%B5%B2/"
EXPECTED_LOCATION="${BASE}${CANONICAL_PATH}"

fetch_redirect "${BASE}${SERVICE_PATH}"
check "${SERVICE_PATH} 回 302(實際 ${REDIR_CODE})" "$([ "$REDIR_CODE" = "302" ]; echo $?)"

# ⛔ 精確比對:同 host、同 path、無 query/fragment。只允許 %XX 大小寫差異。
if [ "$(canon_url "$REDIR_LOCATION")" = "$(canon_url "$EXPECTED_LOCATION")" ]; then
    check "${SERVICE_PATH} Location 精確等於 canonical" 0
    LOCATION_OK=0
else
    note "  [BLOCKER] ${SERVICE_PATH} Location 不符"
    note "            期望:${EXPECTED_LOCATION}"
    note "            實際:${REDIR_LOCATION:-（無 Location）}"
    FAIL=1
    LOCATION_OK=1
fi

# target 必須是「直接 200」:自己再 redirect 就代表有 chain。
if [ "$REDIR_CODE" = "302" ] && [ "$LOCATION_OK" -eq 0 ]; then
    fetch_redirect "$REDIR_LOCATION"
    check "canonical target 不再 redirect(實際 ${REDIR_CODE})" \
        "$(case "$REDIR_CODE" in 3??|transport-failure) false ;; *) true ;; esac; echo $?)"

    fetch "$REDIR_LOCATION"
    check "canonical target 回 200(實際 ${CODE})" "$([ "$CODE" = "200" ]; echo $?)"

    if [ "$CODE" = "200" ]; then
        H1="$(grep -o '<h1' "$BODY_FILE" | wc -l | tr -d ' ')"
        check "canonical target 單一 H1(實際 ${H1})" "$([ "$H1" = "1" ]; echo $?)"
        grep -q '<meta name="robots" content="noindex' "$BODY_FILE"
        check "canonical target meta noindex" $?
    else
        check "canonical target 單一 H1(未取得 body)" 1
        check "canonical target meta noindex(未取得 body)" 1
    fi

    header_has_noindex "$REDIR_LOCATION"
    check "canonical target X-Robots-Tag noindex" $?
else
    # ⛔ fail closed:前置條件不成立就不去 GET 一個未經驗證的 Location。
    check "canonical target 驗證(前置 302/Location 未成立)" 1
fi

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
