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
        'ecpay_payment' => [
            'sandbox' => '',
            'production' => '',
        ],
        'line_pay' => [
            'sandbox' => '',
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
        'ecpay_payment' => [
            'sandbox' => false,
            'production' => false,
        ],
        'line_pay' => [
            'sandbox' => false,
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

];
