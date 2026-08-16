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
        'ecpay_invoice' => [
            'sandbox' => '',
            'production' => '',
        ],
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
        'themostpanel' => [
            'production' => false,
        ],
    ],

    /*
     | 發票 gateway 綁定。local／testing 一律使用 Fake，
     | ⛔ 其他環境沒有明確 adapter 就 fail closed，不得默默降級成 Fake。
     */
    'invoice' => [
        'gateway' => env('INVOICE_GATEWAY', 'fake'),
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
