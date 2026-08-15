<?php

/*
|--------------------------------------------------------------------------
| 服務目錄草稿（local mock only）
|--------------------------------------------------------------------------
|
| 服務項目依 90-Data/URL-Inventory/2026-08-15-product-fulfillment-mapping.md
| 的 11 個第一方確認商品建立。判讀規則：H1／內文＝實際商品，舊 slug 與 title
| 不可作為履約事實。
|
| price / quantity 全為 mock UI 資料，不是正式 SKU 或售價。正式價格、量級距、
| 速度與保固仍為 unknown（ACTIVE ISSUE-01），取得前不得對外承諾。
|
*/

return [
    'platforms' => [
        'instagram' => [
            'slug' => 'instagram',
            'name' => 'Instagram',
            'tagline' => 'Instagram 粉絲、讚、留言與影片觀看服務。',
            'available' => true,
            'services' => [
                'followers' => [
                    'slug' => 'followers',
                    'name' => 'Instagram 粉絲',
                    'summary' => '為帳號增加粉絲數，依數量選擇方案。',
                    'input_label' => 'Instagram 帳號',
                    'input_hint' => '例如：username 或 instagram.com/username',
                    'delivery' => '輸入公開帳號後依方案交付。',
                    'plans' => [
                        'ig-followers-500' => ['label' => '500 位粉絲', 'quantity' => 500, 'price' => 320],
                        'ig-followers-1000' => ['label' => '1,000 位粉絲', 'quantity' => 1000, 'price' => 590, 'featured' => true],
                        'ig-followers-2500' => ['label' => '2,500 位粉絲', 'quantity' => 2500, 'price' => 1290],
                    ],
                ],
                'post-likes' => [
                    'slug' => 'post-likes',
                    'name' => 'Instagram 單篇貼文讚',
                    'summary' => '指定單一貼文，一次性增加該篇的讚數。',
                    'input_label' => 'Instagram 貼文網址',
                    'input_hint' => '例如：instagram.com/p/xxxxxxxxx',
                    'delivery' => '貼一條貼文連結，送一次，一次完畢。',
                    'plans' => [
                        'ig-post-likes-200' => ['label' => '200 個讚', 'quantity' => 200, 'price' => 150],
                        'ig-post-likes-500' => ['label' => '500 個讚', 'quantity' => 500, 'price' => 330, 'featured' => true],
                        'ig-post-likes-1000' => ['label' => '1,000 個讚', 'quantity' => 1000, 'price' => 620],
                    ],
                ],
                'auto-likes' => [
                    'slug' => 'auto-likes',
                    'name' => 'Instagram 自動貼文讚',
                    'summary' => '預付篇數，之後發布的新貼文依序自動獲得讚。',
                    'input_label' => 'Instagram 帳號（須為公開帳號）',
                    'input_hint' => '例如：username；帳號必須公開才能自動交付',
                    'delivery' => '預付 N 篇份，新貼文依序自動交付，用完為止、無時間限制。',
                    'plans' => [
                        'ig-auto-likes-10' => ['label' => '10 篇份', 'quantity' => 10, 'price' => 480],
                        'ig-auto-likes-30' => ['label' => '30 篇份', 'quantity' => 30, 'price' => 1280, 'featured' => true],
                        'ig-auto-likes-60' => ['label' => '60 篇份', 'quantity' => 60, 'price' => 2380],
                    ],
                ],
                'comments' => [
                    'slug' => 'comments',
                    'name' => 'Instagram 貼文留言',
                    'summary' => '為指定貼文增加留言則數。',
                    'input_label' => 'Instagram 貼文網址',
                    'input_hint' => '例如：instagram.com/p/xxxxxxxxx',
                    'delivery' => '指定貼文後依方案交付留言則數。',
                    'plans' => [
                        'ig-comments-10' => ['label' => '10 則留言', 'quantity' => 10, 'price' => 260],
                        'ig-comments-30' => ['label' => '30 則留言', 'quantity' => 30, 'price' => 690, 'featured' => true],
                        'ig-comments-50' => ['label' => '50 則留言', 'quantity' => 50, 'price' => 1080],
                    ],
                ],
                'video-views' => [
                    'slug' => 'video-views',
                    'name' => 'Instagram Reel／IGTV 影片觀看',
                    'summary' => '為 Reel 或 IGTV 影片增加觀看次數。',
                    'input_label' => 'Instagram 影片網址',
                    'input_hint' => '例如：instagram.com/reel/xxxxxxxxx',
                    'delivery' => '指定影片後依方案交付觀看次數。',
                    'plans' => [
                        'ig-views-1000' => ['label' => '1,000 次觀看', 'quantity' => 1000, 'price' => 180],
                        'ig-views-5000' => ['label' => '5,000 次觀看', 'quantity' => 5000, 'price' => 620, 'featured' => true],
                        'ig-views-10000' => ['label' => '10,000 次觀看', 'quantity' => 10000, 'price' => 1080],
                    ],
                ],
            ],
        ],

        'facebook' => [
            'slug' => 'facebook',
            'name' => 'Facebook',
            'tagline' => 'Facebook 粉絲、讚、留言評論與影片觀看服務。',
            'available' => true,
            'services' => [
                'followers' => [
                    'slug' => 'followers',
                    'name' => 'Facebook 粉專／個人／社團粉絲',
                    'summary' => '為粉絲專頁、個人檔案或社團增加粉絲。',
                    'input_label' => 'Facebook 粉專、個人檔案或社團網址',
                    'input_hint' => '例如：facebook.com/yourpage',
                    'delivery' => '輸入公開網址後依方案交付。',
                    'plans' => [
                        'fb-followers-500' => ['label' => '500 位粉絲', 'quantity' => 500, 'price' => 360],
                        'fb-followers-1000' => ['label' => '1,000 位粉絲', 'quantity' => 1000, 'price' => 660, 'featured' => true],
                        'fb-followers-2500' => ['label' => '2,500 位粉絲', 'quantity' => 2500, 'price' => 1420],
                    ],
                ],
                'post-likes' => [
                    'slug' => 'post-likes',
                    'name' => 'Facebook 貼文讚',
                    'summary' => '為指定的 Facebook 貼文增加讚數。',
                    'input_label' => 'Facebook 貼文網址',
                    'input_hint' => '例如：facebook.com/yourpage/posts/123456',
                    'delivery' => '指定貼文後依方案交付讚數。',
                    'plans' => [
                        'fb-post-likes-200' => ['label' => '200 個讚', 'quantity' => 200, 'price' => 170],
                        'fb-post-likes-500' => ['label' => '500 個讚', 'quantity' => 500, 'price' => 360, 'featured' => true],
                        'fb-post-likes-1000' => ['label' => '1,000 個讚', 'quantity' => 1000, 'price' => 680],
                    ],
                ],
                'comments' => [
                    'slug' => 'comments',
                    'name' => 'Facebook 貼文留言／粉專評論',
                    'summary' => '為貼文增加留言，或為粉專增加評論。',
                    'input_label' => 'Facebook 貼文或粉專網址',
                    'input_hint' => '例如：facebook.com/yourpage/posts/123456',
                    'delivery' => '指定貼文或粉專後依方案交付。',
                    'plans' => [
                        'fb-comments-10' => ['label' => '10 則留言', 'quantity' => 10, 'price' => 280],
                        'fb-comments-30' => ['label' => '30 則留言', 'quantity' => 30, 'price' => 740, 'featured' => true],
                        'fb-comments-50' => ['label' => '50 則留言', 'quantity' => 50, 'price' => 1150],
                    ],
                ],
                'video-views' => [
                    'slug' => 'video-views',
                    'name' => 'Facebook Reel／影片觀看',
                    'summary' => '為 Facebook Reel 或影片增加觀看次數。',
                    'input_label' => 'Facebook 影片網址',
                    'input_hint' => '例如：facebook.com/reel/123456',
                    'delivery' => '指定影片後依方案交付觀看次數。',
                    'plans' => [
                        'fb-views-1000' => ['label' => '1,000 次觀看', 'quantity' => 1000, 'price' => 200],
                        'fb-views-5000' => ['label' => '5,000 次觀看', 'quantity' => 5000, 'price' => 680, 'featured' => true],
                        'fb-views-10000' => ['label' => '10,000 次觀看', 'quantity' => 10000, 'price' => 1180],
                    ],
                ],
            ],
        ],

        'threads' => [
            'slug' => 'threads',
            'name' => 'Threads',
            'tagline' => '尚未開放；服務資料準備中。',
            'available' => false,
            'unavailable_note' => '目前沒有可販售的 Threads 服務資料。開放前不會顯示方案或價格。',
            'services' => [],
        ],
    ],
];
