<?php

/*
|--------------------------------------------------------------------------
| M5A：舊站 URL → 新站 canonical 的唯一對照表
|--------------------------------------------------------------------------
|
| ⛔⛔ 這份 mapping 只存在一份。
|
| ⭐ 施工單明確要求「15條legacy資料只存在一份」，理由很實際：同一條轉址
| 若同時散落在 route closure、middleware 與 controller，改一處而漏另一處
| 的那天，外面的舊連結會靜默進入一個沒人預期的目標——⛔ 而 301 是永久的，
| 搜尋引擎與外部網站會把錯誤結果一起記住。
|
| ⛔ key 一律是**已正規化**的 path：小寫、無尾斜線、raw UTF-8（不是
| percent-encoded）。正規化由 `CanonicalUrl` 負責，⛔ 這裡不重複實作。
|
| ⛔ value 是**最終** canonical，不是中繼站：`/product/ig粉絲/` 直接指向
| `/product/ig買粉絲/`，⛔ 不先補尾斜線再轉一次。任何一條 legacy 都必須
| 一跳到位。
|
| ⛔ 商品級 `/services/{platform}/{service}` 的 9 條 alias **不在這裡**：
| 它們由 `ProductSlugMap` 這個既有的唯一事實來源推導（施工單 §2.3），
| ⛔ 在這裡再抄一份就是第二份 mapping。
*/

return [

    /*
    |--------------------------------------------------------------------------
    | 15 條 legacy path
    |--------------------------------------------------------------------------
    */

    'legacy' => [
        // → /product/ig買粉絲/
        '/product/ig粉絲' => '/product/ig買粉絲/',
        '/product/買粉絲' => '/product/ig買粉絲/',
        '/product/instagramfollow' => '/product/ig買粉絲/',
        '/product/free' => '/product/ig買粉絲/',
        '/ig買粉絲推薦' => '/product/ig買粉絲/',
        '/product/ig買follow' => '/product/ig買粉絲/',

        // → /product/ig買like/
        '/product/ig買讚' => '/product/ig買like/',

        // → /product/ig影片觀看/
        '/product/互粉互讚' => '/product/ig影片觀看/',

        // → /product/fb買like/
        '/product/facebook買讚' => '/product/fb買like/',
        '/product/臉書買讚' => '/product/fb買like/',
        '/product/facebookpagelike' => '/product/fb買like/',

        // → /product/fb影片觀看/
        '/product/買粉推薦' => '/product/fb影片觀看/',

        // → Hub
        '/product-category/instagram' => '/services/instagram',
        '/product-category/facebook' => '/services/facebook',

        // → 首頁
        '/shop' => '/',
    ],

    /*
    |--------------------------------------------------------------------------
    | 410 Gone
    |--------------------------------------------------------------------------
    |
    | ⭐ Owner 明確指定 `/cart/` 與 `/my-account/` 採**真正的 410**。
    |
    | ⛔ 不是 301 到首頁：那會告訴搜尋引擎「這個頁面搬到首頁了」，
    | 而事實是它**不再存在**——新站根本沒有購物車與會員中心。
    | ⛔ 也不是 404：410 明確表示「確定移除、不會回來」，
    | 讓 Google 更快把它從索引移除。
    */

    'gone' => [
        '/cart',
        '/my-account',
    ],

];
