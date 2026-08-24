<?php

return [

    /*
    |--------------------------------------------------------------------------
    | 履約 driver — ⛔ R1 之後只剩「測試路徑選擇器」一個角色
    |--------------------------------------------------------------------------
    |
    | 合法值:
    |
    |   disabled     — 預設。
    |   fake         — 只在 local／testing 有意義:綁定 FakeFulfillmentGateway
    |                  供本機開發與測試。
    |   themostpanel — 只在 testing 有意義:adapter e2e 測試以注入式 fake
    |                  transport 走完整流程。
    |
    | ⛔ staging／production 完全不讀這個值。正式派單的唯一營運開關是 Owner
    | 後台的自動派單總開關(production integration_settings.is_enabled),
    | 加上版本控制中的 exact endpoint 與 runtime 能力——把 driver 改成任何
    | 字串都不會影響正式站的行為。
    |
    */

    'driver' => env('FULFILLMENT_DRIVER', 'disabled'),

    /*
    |--------------------------------------------------------------------------
    | ⛔ DEPRECATED／IGNORED — 自動派單總開關已移至 Owner 後台(R1)
    |--------------------------------------------------------------------------
    |
    | `FULFILLMENT_DISPATCH_ENABLED` 與
    | `FULFILLMENT_STAGING_THEMOSTPANEL_DISPATCH_ENABLED` 在 R1 之後不再參與
    | 任何 runtime 決定。唯一的營運事實是 TheMostPanel production
    | `integration_settings.is_enabled`(見 FulfillmentDispatchGate)。
    |
    | ⛔ 保留鍵只為相容既有部署的 .env 不致噴錯,值一律被忽略;已有測試證明
    | 設成 false 也不會蓋過 Owner 的後台開關。⛔ 不要在新程式碼裡讀它們。
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
    | ⛔ DEPRECATED／IGNORED — staging 專用旗標已無作用(R1)
    |--------------------------------------------------------------------------
    |
    | staging 與 production 現在走同一條路:同一列 Owner credential、同一個
    | Owner 總開關、同一個 exact endpoint。一個只屬於 staging 的旗標已經沒有
    | 對應的決定可做。保留鍵只為 .env 相容,值被忽略。
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
    | ⛔ R1:輪詢跟隨自動派單總開關,不再有獨立的 env 旗標。Owner 打開
    | 「自動派單」,輪詢就會開始;關掉,下一輪就排入 0。
    | `FULFILLMENT_STATUS_POLLING_ENABLED` 已 deprecated/ignored,保留鍵
    | 只為 .env 相容。
    |
    | 輪詢只查已有 provider order ID 的列;⛔ 永不呼叫 add、永不重送訂單。
    | interval 固定每 10 分鐘(scheduler 內寫死):在取得 provider
    | rate-limit contract 之前不開放調整。
    |
    */

    'status_polling_enabled' => env('FULFILLMENT_STATUS_POLLING_ENABLED', false),

    // 每輪最多排入的列數;⛔ 固定上限,不由後台輸入。
    'status_polling_batch_limit' => 50,

];
