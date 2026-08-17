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
| ⛔ 目前所有端點都留空：本輪禁止任何外部呼叫，M3B-B 才會依官方文件填入。
| TheMostPanel 沒有已證實的 sandbox，⛔ 不得為了「看起來完整」而杜撰一個。
|
*/

return [

    /*
     | 每個 provider 在各環境的 base endpoint。
     | 空字串代表「尚未確認」，adapter 必須 fail closed，不得猜測。
     */
    'endpoints' => [
        // 綠界 AioCheckOut V5 stage（2026-08-16 核對官方文件）。
        // ⛔ production 仍留空：啟用正式收款需要另一次明確批准。
        'ecpay_payment' => [
            'sandbox' => 'https://payment-stage.ecpay.com.tw/Cashier/AioCheckOut/V5',
            'production' => '',
        ],
        // LINE Pay Online API v4 sandbox base（2026-08-16 核對官方文件）。
        'line_pay' => [
            'sandbox' => 'https://sandbox-api-pay.line.me',
            'production' => '',
        ],
        // 綠界 B2C 電子發票 stage（2026-08-17 核對官方文件）。
        // ⛔ production 仍留空：正式開立發票需要另一次明確批准。
        'ecpay_invoice' => [
            'sandbox' => 'https://einvoice-stage.ecpay.com.tw/B2CInvoice/Issue',
            'production' => '',
        ],
        // 結果不明時的唯讀查詢端點；⛔ 只用來「確認是否已開出」，不重開。
        'ecpay_invoice_query' => [
            'sandbox' => 'https://einvoice-stage.ecpay.com.tw/B2CInvoice/GetIssue',
            'production' => '',
        ],
        /*
         | ⛔ 仍為空，而且是刻意的。
         |
         | 這一組 `endpoints` 代表「可用來執行交易的端點」。TheMostPanel 的
         | 派單（`add`）尚未獲准，所以這裡不得有值——既有的安全測試也以
         | 「所有 production 端點皆為空」作為不變式。
         |
         | 唯讀探針用的位址另放在下方 `themostpanel_read_only.endpoint`，
         | 與交易端點分開，避免有人把「可以查詢」誤讀成「可以下單」。
         */
        'themostpanel' => [
            'production' => '',
        ],
    ],

    /*
     | 允許被啟用的 provider／environment 組合。
     |
     | ⛔ 本輪全部為 false。啟用正式交易需要另一次明確批准，而不是有人在後台
     | 按一個開關；把它放在程式碼裡，才不會被偽造的 Livewire payload 打開。
     */
    'enablable' => [
        // sandbox 付款測試已獲批准，Owner 可在後台啟用這兩組設定。
        // ⛔ production 仍為 false：正式收款需要另一次明確批准。
        'ecpay_payment' => [
            'sandbox' => true,
            'production' => false,
        ],
        'line_pay' => [
            'sandbox' => true,
            'production' => false,
        ],
        'ecpay_invoice' => [
            'sandbox' => false,
            'production' => false,
        ],
        // ⛔ 仍為 false：這控制的是「自動派單」，與下方的唯讀探針無關。
        'themostpanel' => [
            'production' => false,
        ],
    ],

    /*
     | TheMostPanel 唯讀探針（M4B-RO）。
     |
     | ⛔ 與 `enablable.themostpanel` 是完全不同的兩件事，刻意分開：
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
     | 發票 gateway 綁定。local／testing 一律使用 Fake，
     | ⛔ 其他環境沒有明確 adapter 就 fail closed，不得默默降級成 Fake。
     */
    'invoice' => [
        'gateway' => env('INVOICE_GATEWAY', 'fake'),

        /*
         | Sandbox 開立發票總開關。
         |
         | ⛔ 預設關閉。關閉時 adapter 一律 fail closed——不送出任何請求，也不
         | 退回 Fake。填了 credential 也不等於開始開立發票。
         |
         | ⛔ production 永遠不受這個開關影響：InvoiceSandboxGuard 另外硬性
         | 拒絕 production 環境。
         */
        'sandbox_enabled' => env('INVOICE_SANDBOX_ENABLED', false),
    ],

    /*
     | Sandbox 付款流程總開關。
     |
     | ⛔ 預設關閉。關閉時 checkout 仍走 local mock，付款 adapter 一律 fail
     | closed——不會退回 Fake，也不會連到任何 endpoint。要實際測試 sandbox
     | 需要在環境中明確打開，並且已在後台填入 sandbox credential。
     |
     | ⛔ production 永遠不受這個開關影響：PaymentGatewayRegistry 另外硬性
     | 拒絕 production 環境。
     */
    'payments' => [
        'sandbox_enabled' => env('PAYMENTS_SANDBOX_ENABLED', false),
    ],

];
