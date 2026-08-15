# IGLIKEFOLLOW Code Rules

控制層位於 `C:\Users\Kasy\Desktop\IGLIKFOLLOW`。施工前依序讀取根目錄 `AGENTS.md`、`00-Control/START-HERE.md`、`00-Control/PROJECT-RULES.md`、`00-Control/ACTIVE-DISCUSSION.md` 與目前 milestone。

- 本專案為 Laravel 12＋Blade＋Tailwind CSS＋Alpine.js；不要重新引入 Next.js 或 React，除非使用者另行批准。
- 正式系統、DNS、Cloudflare、RunCloud、金流、履約 API、SQL、301、canonical、robots 與 sitemap 不得在未批准時修改。
- 所有主要 SEO 內容、H1、內鏈與 metadata 必須存在於初始 HTML。
- 預設 fail-closed noindex；只有 `APP_ENV=production` 且 `ALLOW_INDEXING=true` 才能開放索引。
- 前端價格與付款成功狀態永遠不可信；後端必須重新驗價並驗證金流通知。
- 目前 checkout 是 mock，禁止連接正式金流、建立真實訂單或寫入正式資料庫。
- 商品與搜尋意圖不同才拆可索引 URL；同意圖的數量／SKU 留在同一頁。
- 每次修改必須包含測試、SEO 回歸、變更清單與 rollback。
- 不複製 Apple、BYMYFANS、MFTW 或其他網站的文案、Logo、圖片、CSS 或程式碼。

## UI baseline

首版採「C 高轉換結構＋A 高級排版」；這是可修改基線，不是永久鎖定。手機流程固定為選方案 → 填目標 → 選付款 → 後端處理。
