{{--
    M5D：右下角 LINE 客服按鈕。

    ⛔ 這是一條**一般客服連結**，不是 Messaging API 推播——
    ⛔ 不需要 token／secret／webhook，⛔ 也沒有任何 SDK 或第三方 widget。

    ⛔⛔ URL 原樣使用 Owner 提供的值，⛔ 不附加訂單編號、帳號、Email、
    追蹤參數或預填訊息：那等於把客人的資料寫進一個會離站的網址。

    ⭐ 真實 `<a href>`：關掉 JavaScript 一樣能用，⛔ 不是 JS-only。
    ⭐ `rel="noopener noreferrer"` ＋ `referrerpolicy="no-referrer"`：
    新視窗不得取得 `window.opener`，也⛔ 不外洩我們的網址給 LINE。
--}}
<a href="https://line.me/R/ti/p/@532otvye"
   target="_blank"
   rel="noopener noreferrer"
   referrerpolicy="no-referrer"
   data-probe="line-contact"
   {{-- ⛔ 無障礙名稱要講清楚「會另開視窗」，⛔ 不靠 tooltip 當唯一辨識。 --}}
   aria-label="LINE客服，另開視窗"
   class="line-contact">
    {{--
        裝飾性圖形：⛔ 這不是官方 LINE Logo，所以 `aria-hidden`，
        ⛔ 也不宣稱它是品牌標誌。可辨識性由旁邊的真實文字提供。

        ⛔⛔ viewBox 刻意用 `0 0 32 32`，⛔ 不是平台 Logo 的 `0 0 24 24`。
        ⭐ 這是我實測撞到的：`M2dStorefrontVisualHierarchyTest` 會數
        `viewBox="0 0 24 24"` 的 svg 來確保「平台 Logo 不隨服務卡增生」
        （Hub 頁應為 3 tab ＋ 1 hero ＝ 4 個）。我的按鈕沿用同一個 viewBox
        就會讓它變成 5——⛔ 那條既有斷言是對的，該讓路的是我的圖示，
        ⛔ 不是去放寬別人的測試。
    --}}
    <svg class="line-contact__mark" viewBox="0 0 32 32" aria-hidden="true" focusable="false">
        <path fill="currentColor"
              d="M16 4C9.25 4 4 8.39 4 13.6c0 4.67 4.17 8.59 9.81 9.35.39.08.91.25 1.04.57.12.3.08.75.04 1.05l-.17 1c-.05.3-.24 1.16 1.03.63 1.27-.54 6.81-4.02 9.29-6.87C26.72 17.6 28 15.73 28 13.6 28 8.39 22.75 4 16 4Z" />
    </svg>
    {{-- ⛔ 手機只顯示「LINE」，桌面顯示「LINE 客服」——兩者都是真實文字。 --}}
    <span class="line-contact__text">LINE<span class="line-contact__text-full"> 客服</span></span>
</a>
