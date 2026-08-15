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
| 結構：platform → service → variants[] → quantity{}
|
| 一個服務可有多個 variant（例如粉絲的真人／台灣／華人款）。variant 與數量
| 級距最終都由後台 API 提供；此處只是等待被取代的 placeholder。
|
| ⛔ min / max / unit_price 全為 mock，不是正式售價或正式級距。
| ⛔ 正式值仍為 unknown（ACTIVE ISSUE-01），取得前不得對外承諾。
|
*/

return [
    /*
     * 後台 API 接上前，數量的邊界值來源。api_backed=false 代表目前是 placeholder。
     */
    'quantity_source' => [
        'api_backed' => false,
        'note' => '數量上下限與單價待後台 API 提供；目前為本機 placeholder。',
    ],

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
                    'summary' => '為帳號增加粉絲數，可選擇不同粉絲來源與品質。',
                    'goal' => '帳號規模',
                    'card_blurb' => '建立帳號整體規模。',
                    'featured_service' => true,
                    'input_label' => 'Instagram 帳號',
                    'input_hint' => '例如：username 或 instagram.com/username',
                    'delivery' => '輸入公開帳號後依所選服務項目交付。',
                    'quantity_unit' => '個',
                    'variants' => [
                        'ig-followers-standard' => [
                            'label' => '一般粉絲',
                            'description' => '標準粉絲來源。',
                            'quantity' => ['min' => 100, 'max' => 50000, 'step' => 100, 'default' => 1000, 'unit_price' => 0.59],
                            'featured' => true,
                        ],
                        'ig-followers-real' => [
                            'label' => '真人粉絲',
                            // ⛔ 不得寫互動率、真人程度、速度或品質等無第一方證據的宣稱。
                            'description' => '真人帳號來源。',
                            'quantity' => ['min' => 100, 'max' => 20000, 'step' => 100, 'default' => 1000, 'unit_price' => 0.95],
                        ],
                        'ig-followers-taiwan' => [
                            'label' => '台灣粉絲',
                            'description' => '以台灣地區帳號為主。',
                            'quantity' => ['min' => 100, 'max' => 10000, 'step' => 100, 'default' => 500, 'unit_price' => 1.60],
                        ],
                    ],
                ],
                'post-likes' => [
                    'slug' => 'post-likes',
                    'name' => 'Instagram 單篇貼文讚',
                    'summary' => '指定單一貼文，一次性增加該篇的讚數。',
                    'input_label' => 'Instagram 貼文網址',
                    'input_hint' => '例如：instagram.com/p/xxxxxxxxx',
                    'delivery' => '貼一條貼文連結，送一次，一次完畢。',
                    'goal' => '單篇互動',
                    'card_blurb' => '為指定貼文增加讚數。',
                    'quantity_unit' => '個',
                    'variants' => [
                        'ig-post-likes-standard' => [
                            'label' => '一般讚',
                            'description' => '標準來源，指定貼文一次交付。',
                            'quantity' => ['min' => 50, 'max' => 20000, 'step' => 50, 'default' => 500, 'unit_price' => 0.66],
                            'featured' => true,
                        ],
                    ],
                ],
                'auto-likes' => [
                    'slug' => 'auto-likes',
                    'name' => 'Instagram 自動貼文讚',
                    'summary' => '預付篇數，之後發布的新貼文依序自動獲得讚。',
                    'input_label' => 'Instagram 帳號（須為公開帳號）',
                    'input_hint' => '例如：username；帳號必須公開才能自動交付',
                    'delivery' => '預付篇數，新貼文依序自動交付，用完為止、無時間限制。',
                    'goal' => '自動經營',
                    'card_blurb' => '新貼文自動獲得讚。',
                    'quantity_unit' => '篇',
                    'variants' => [
                        'ig-auto-likes-standard' => [
                            'label' => '自動讚',
                            'description' => '依購買篇數，對之後的新貼文自動交付。',
                            'quantity' => ['min' => 5, 'max' => 200, 'step' => 5, 'default' => 30, 'unit_price' => 42.67],
                            'featured' => true,
                        ],
                    ],
                ],
                'comments' => [
                    'slug' => 'comments',
                    'name' => 'Instagram 貼文留言',
                    'summary' => '為指定貼文增加留言則數。',
                    'input_label' => 'Instagram 貼文網址',
                    'input_hint' => '例如：instagram.com/p/xxxxxxxxx',
                    'delivery' => '指定貼文後依方案交付留言則數。',
                    'goal' => '單篇互動',
                    'card_blurb' => '為指定貼文增加留言。',
                    'quantity_unit' => '則',
                    'variants' => [
                        'ig-comments-standard' => [
                            'label' => '一般留言',
                            'description' => '隨機留言內容。',
                            'quantity' => ['min' => 5, 'max' => 500, 'step' => 5, 'default' => 30, 'unit_price' => 23.00],
                            'featured' => true,
                        ],
                    ],
                ],
                'video-views' => [
                    'slug' => 'video-views',
                    'name' => 'Instagram Reel／IGTV 影片觀看',
                    'summary' => '為 Reel 或 IGTV 影片增加觀看次數。',
                    'input_label' => 'Instagram 影片網址',
                    'input_hint' => '例如：instagram.com/reel/xxxxxxxxx',
                    'delivery' => '指定影片後依方案交付觀看次數。',
                    'goal' => '影片曝光',
                    'card_blurb' => '增加影片觀看次數。',
                    'quantity_unit' => '次',
                    'variants' => [
                        'ig-views-standard' => [
                            'label' => '一般觀看',
                            'description' => '標準觀看來源。',
                            'quantity' => ['min' => 500, 'max' => 200000, 'step' => 500, 'default' => 5000, 'unit_price' => 0.124],
                            'featured' => true,
                        ],
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
                    'delivery' => '輸入公開網址後依所選服務項目交付。',
                    'quantity_unit' => '個',
                    'variants' => [
                        'fb-followers-standard' => [
                            'label' => '一般粉絲',
                            'description' => '標準粉絲來源。',
                            'quantity' => ['min' => 100, 'max' => 50000, 'step' => 100, 'default' => 1000, 'unit_price' => 0.66],
                            'featured' => true,
                        ],
                    ],
                ],
                'post-likes' => [
                    'slug' => 'post-likes',
                    'name' => 'Facebook 貼文讚',
                    'summary' => '為指定的 Facebook 貼文增加讚數。',
                    'input_label' => 'Facebook 貼文網址',
                    'input_hint' => '例如：facebook.com/yourpage/posts/123456',
                    'delivery' => '指定貼文後依方案交付讚數。',
                    'quantity_unit' => '個',
                    'variants' => [
                        'fb-post-likes-standard' => [
                            'label' => '一般讚',
                            'description' => '標準來源。',
                            'quantity' => ['min' => 50, 'max' => 20000, 'step' => 50, 'default' => 500, 'unit_price' => 0.72],
                            'featured' => true,
                        ],
                    ],
                ],
                'comments' => [
                    'slug' => 'comments',
                    'name' => 'Facebook 貼文留言／粉專評論',
                    'summary' => '為貼文增加留言，或為粉專增加評論。',
                    'input_label' => 'Facebook 貼文或粉專網址',
                    'input_hint' => '例如：facebook.com/yourpage/posts/123456',
                    'delivery' => '指定貼文或粉專後依方案交付。',
                    'quantity_unit' => '則',
                    'variants' => [
                        'fb-comments-standard' => [
                            'label' => '一般留言',
                            'description' => '隨機留言內容。',
                            'quantity' => ['min' => 5, 'max' => 500, 'step' => 5, 'default' => 30, 'unit_price' => 24.67],
                            'featured' => true,
                        ],
                        'fb-comments-review' => [
                            'label' => '粉專 5 星評論',
                            'description' => '粉絲專頁評論，非貼文留言。',
                            'quantity' => ['min' => 5, 'max' => 200, 'step' => 5, 'default' => 20, 'unit_price' => 38.00],
                        ],
                    ],
                ],
                'video-views' => [
                    'slug' => 'video-views',
                    'name' => 'Facebook Reel／影片觀看',
                    'summary' => '為 Facebook Reel 或影片增加觀看次數。',
                    'input_label' => 'Facebook 影片網址',
                    'input_hint' => '例如：facebook.com/reel/123456',
                    'delivery' => '指定影片後依方案交付觀看次數。',
                    'quantity_unit' => '次',
                    'variants' => [
                        'fb-views-standard' => [
                            'label' => '一般觀看',
                            'description' => '標準觀看來源。',
                            'quantity' => ['min' => 500, 'max' => 200000, 'step' => 500, 'default' => 5000, 'unit_price' => 0.136],
                            'featured' => true,
                        ],
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
