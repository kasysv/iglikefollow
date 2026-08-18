<?php

return [

    /*
    |--------------------------------------------------------------------------
    | 履約 driver
    |--------------------------------------------------------------------------
    |
    | ⛔ 只有兩個合法值：
    |
    |   disabled — 預設。永不連網、永不派單。
    |   fake     — 只允許 local／testing，供測試使用。
    |
    | ⛔ 這裡沒有、也不會有 `themostpanel`：M4A 沒有任何 HTTP client。真實派單
    | 需要先驗證 service ID、target 轉換、狀態與錯誤 contract 以及人工對帳流程，
    | 那是 M4B 與 M4C 的事，不是一個設定值可以打開的東西。
    |
    */

    'driver' => env('FULFILLMENT_DRIVER', 'disabled'),

    /*
    |--------------------------------------------------------------------------
    | 自動派單總開關
    |--------------------------------------------------------------------------
    |
    | ⛔ 預設關閉，且與 mapping 的 is_enabled 是兩回事。
    |
    | mapping 啟用代表「這個對應是正確的」；這個開關才代表「可以真的送出去」。
    | 兩者分開，是為了讓「設定看起來沒問題」不會被讀成「開始下單」。
    |
    */

    'dispatch_enabled' => env('FULFILLMENT_DISPATCH_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Fake provider
    |--------------------------------------------------------------------------
    |
    | ⛔ 非機密設定，只給測試用。這裡不放 endpoint、不放 API key——credential
    | 一律加密存在 integration_settings，由 Owner 從後台輸入。
    |
    */

    'fake' => [
        'order_id_prefix' => 'FAKE-',
    ],

    /*
    |--------------------------------------------------------------------------
    | Staging 專用能力(M4C-STAGING-READINESS-A)
    |--------------------------------------------------------------------------
    |
    | ⛔ default off,而且只在 APP_ENV=staging 有意義:production 在 gate 與
    | container 都無條件 fail closed,local 永遠拿不到真實 dispatch driver。
    | 打開這個 flag 本身不夠——driver、endpoint、runtime capability、
    | enabled credential 與 dispatch 總開關每一項都要另外成立。
    |
    */

    'staging' => [
        'themostpanel_dispatch_enabled' => env('FULFILLMENT_STAGING_THEMOSTPANEL_DISPATCH_ENABLED', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | 履約狀態輪詢(status polling)
    |--------------------------------------------------------------------------
    |
    | ⛔ default off,只允許 staging。它只負責挑選可同步的履約列並排入
    | SyncFulfillmentStatus jobs;自己永不呼叫 provider,也永不重送 add。
    | interval 固定每 10 分鐘(scheduler 內寫死):在取得 provider
    | rate-limit contract 之前不開放調整。
    |
    */

    'status_polling_enabled' => env('FULFILLMENT_STATUS_POLLING_ENABLED', false),

    // 每輪最多排入的列數;⛔ 固定上限,不由後台輸入。
    'status_polling_batch_limit' => 50,

];
