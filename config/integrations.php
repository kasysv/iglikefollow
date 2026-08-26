<?php

/*
|--------------------------------------------------------------------------
| 串接能力與端點白名單
|--------------------------------------------------------------------------
|
| ⛔ 這個檔案不放任何 credential：沒有 MerchantID、HashKey、HashIV、Channel
| Secret 或 API key，也不從 .env 讀取它們。credential 一律加密存在
| integration_settings，由 Owner 從後台輸入。
|
| 端點寫在這裡而不是資料庫，是因為「可以被後台輸入的網址」等於一個 SSRF
| 破口——這台伺服器會拿著憑證去連任何有人填進去的主機。放在版本控制裡，
| 改動才會被 review。
|
| ⛔ M4C 之後,「這個通道要不要收款」不再由這個檔案或 .env 決定,而是由
| Owner 在後台切換 production `integration_settings.is_enabled`。端點白名單
| 仍然留在這裡:它是安全邊界,不是營運開關。
|
*/

return [

    /*
     | 每個 provider 的正式端點。
     |
     | ⛔ 真實字串必須與 App\Services\Integrations\ProviderEndpoints 的常數
     | 完全一致;adapter 一律透過那個類別取值,任何不一致都 fail closed。
     | 兩處並存是刻意的:設定被竄改時,adapter 在送出任何東西之前就停下來。
     */
    'endpoints' => [
        // 綠界全方位金流 V5(2026-08-24 核對官方文件 developers.ecpay.com.tw/16449)。
        'ecpay_payment' => [
            'sandbox' => 'https://payment-stage.ecpay.com.tw/Cashier/AioCheckOut/V5',
            'production' => 'https://payment.ecpay.com.tw/Cashier/AioCheckOut/V5',
        ],
        // LINE Pay Online API v4 base(2026-08-24 核對 developers-pay.line.me/online-api-v4)。
        'line_pay' => [
            'sandbox' => 'https://sandbox-api-pay.line.me',
            'production' => 'https://api-pay.line.me',
        ],
        // 綠界 B2C 電子發票開立(2026-08-24 核對 developers.ecpay.com.tw/22040)。
        'ecpay_invoice' => [
            'sandbox' => 'https://einvoice-stage.ecpay.com.tw/B2CInvoice/Issue',
            'production' => 'https://einvoice.ecpay.com.tw/B2CInvoice/Issue',
        ],
        // 結果不明時的唯讀查詢端點(2026-08-24 核對 developers.ecpay.com.tw/22108)。
        // ⛔ 只用來「確認是否已開出」,不重開。
        'ecpay_invoice_query' => [
            'sandbox' => 'https://einvoice-stage.ecpay.com.tw/B2CInvoice/GetIssue',
            'production' => 'https://einvoice.ecpay.com.tw/B2CInvoice/GetIssue',
        ],
        /*
         | TheMostPanel 派單 API(R1)。
         |
         | ⛔ staging 與 production runtime 都只讀 `production` 這一個鍵,
         | 並與 App\Services\Integrations\ProviderEndpoints 的常數整串比對;
         | 不依 APP_ENV 拼接 config key——一個新環境名稱不該成為一個沒人
         | review 過的端點來源。⛔ 後台與 env 都不可輸入這個網址。
         |
         | 「要不要派單」不由這裡決定:那是 Owner 後台的自動派單總開關
         | (production integration_settings.is_enabled)。
         |
         | 唯讀探針用的位址另放在下方 `themostpanel_read_only.endpoint`,
         | 與交易端點分開,避免有人把「可以查詢」誤讀成「可以下單」。
         */
        'themostpanel' => [
            'production' => 'https://themostpanel.com/api/v2',
        ],

        /*
         | LINE Messaging API 的 Push Message 端點(新訂單通知)。
         |
         | ⛔ 只有 production 一列:LINE Messaging API 沒有本專案要用的 sandbox。
         | ⛔ 與 `line_pay` 是兩個不同的服務,只是名字都有 LINE。
         | ⛔ 不從 env 讀:這個請求會帶著 Channel Access Token 出去,
         |   一個可被 .env 改寫的 URL 等於把金鑰送去任何人指定的主機。
         */
        'line_order_notification' => [
            'production' => 'https://api.line.me/v2/bot/message/push',
        ],
    ],

    /*
     | ⛔ R1:`enablable` 已整組移除。
     |
     | 它曾是「哪些 provider 可以被啟用」的 code 層批准清單;M4C 初版把付款與
     | 發票交還 Owner 後,只剩自動派單仍被它鎖住。Owner 於 2026-08-24 明確
     | 推翻:自動派單總開關也放進同一個後台。於是這份清單沒有任何消費者了,
     | ⛔ 整組刪除而不是留一個空陣列——留著它,下一個人會以為還有東西在讀。
     */

    /*
     | TheMostPanel 唯讀探針（M4B-RO）。
     |
     | ⛔ 與自動派單總開關是完全不同的兩件事，刻意分開：
     | 這個開關只允許「查詢」`services`／`balance`／單筆 `status`，永遠不會
     | 讓 `add` 或自動派單變成可能。把兩者合成一個開關，就是讓「我想看看回應
     | 長什麼樣」與「開始花錢下單」共用同一個決定。
     |
     | ⛔ 預設關閉，且只從 env 讀取——沒有任何後台介面可以打開它。
     */
    'themostpanel_read_only' => [
        'enabled' => env('THEMOSTPANEL_READ_ONLY_ENABLED', false),

        /*
         | ⛔ 固定在版本控制中，後台與 CLI 都不可輸入或覆寫。
         |
         | 一個可由管理介面編輯的 URL 就是一個 SSRF 缺口——而這個請求會帶著
         | 我們的 API key。探針另外以完全相同的字串再比對一次，設定被竄改時
         | 在送出任何東西之前就停下來。
         |
         | ⛔ 放在這裡而不是 `endpoints`：那一組代表可執行交易的端點，且既有
         | 安全測試要求其 production 一律為空。查詢與下單必須看得出來是兩件事。
         */
        'endpoint' => 'https://themostpanel.com/api/v2',
    ],

    /*
     | TheMostPanel 服務目錄同步（M4B-CATALOG-B1）。
     |
     | ⛔ 第二道獨立開關，與上面的唯讀探針總閘缺一不可：transport 總閘管
     | 「可不可以連」，這個管「可不可以把 services 回應寫成本地 catalog」。
     | 合成一個開關，就是讓「看一眼回應」與「改寫本地目錄」共用同一個決定。
     |
     | ⛔ 預設關閉、只從 env 讀取、沒有後台介面；CLI 另需 --approved-once。
     | 打開它也不會解鎖 add／自動派單——那由完全不同的設定與 gateway 綁定管。
     */
    'themostpanel_catalog_sync' => [
        'enabled' => env('THEMOSTPANEL_CATALOG_SYNC_ENABLED', false),
    ],

    /*
     | ⛔ DEPRECATED／IGNORED — 付款與發票的營運開關已移至後台。
     |
     | `INVOICE_GATEWAY`、`INVOICE_SANDBOX_ENABLED`、`PAYMENTS_SANDBOX_ENABLED`
     | 這三個 env 旗標在 M4C 之後不再參與任何 runtime 決定。唯一的營運事實是
     | production `integration_settings.is_enabled`(見
     | App\Services\Integrations\LiveIntegration)。
     |
     | ⛔ 保留這兩個鍵只為相容既有部署的 .env 檔不致噴錯,值一律被忽略;
     | 已有測試證明把它們設成 false 也不會蓋過 Owner 的後台開關。
     | ⛔ 不要在新程式碼裡讀它們。
     */
    'invoice' => [
        'gateway' => env('INVOICE_GATEWAY', 'fake'),
        'sandbox_enabled' => env('INVOICE_SANDBOX_ENABLED', false),
    ],

    'payments' => [
        'sandbox_enabled' => env('PAYMENTS_SANDBOX_ENABLED', false),
    ],

];
