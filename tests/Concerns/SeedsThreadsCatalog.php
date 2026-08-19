<?php

namespace Tests\Concerns;

use App\Models\Platform;
use App\Models\Service;
use App\Models\ServiceVariant;

/**
 * 測試用 Threads 目錄:重現 Owner 在本機後台建立的結構(published 平台
 * +3 個 published 服務與其 variant 等級標籤)。
 *
 * ⛔ config/catalog.php 是凍結的 seed fixture、不含 Threads;dev DB 的
 * Threads 是 Owner 後台資料。M2-C importer 要求 9 個服務齊備,測試以
 * 此 trait 補齊,數值為虛構 mock,不代表實際售價。
 */
trait SeedsThreadsCatalog
{
    private function seedThreadsCatalog(): void
    {
        $threads = Platform::query()->where('slug', 'threads')->first();

        if ($threads === null) {
            $threads = Platform::factory()->create(['slug' => 'threads', 'name' => 'Threads', 'status' => 'published']);
        } else {
            $threads->forceFill(['status' => 'published'])->saveQuietly();
        }

        $services = [
            'followers' => ['name' => 'Threads 粉絲', 'input_kind' => 'account', 'labels' => ['台灣粉絲', '台灣頂級粉絲', '台灣真人粉絲']],
            'post-likes' => ['name' => 'Threads 貼文讚', 'input_kind' => 'post_url', 'labels' => ['台灣貼文讚(慢速)', '台灣貼文讚(快速)', '台灣頂級貼文讚', '台灣真人貼文讚']],
            'post-boost' => ['name' => 'Threads 瀏覽次數‧轉發‧分享', 'input_kind' => 'post_url', 'labels' => ['台灣貼文瀏覽次數', '台灣貼文轉發次數', '台灣貼文分享次數']],
        ];

        foreach ($services as $slug => $data) {
            $service = Service::query()->where('platform_id', $threads->id)->where('slug', $slug)->first();

            if ($service === null) {
                $service = Service::factory()->create([
                    'platform_id' => $threads->id,
                    'slug' => $slug,
                    'name' => $data['name'],
                    'input_kind' => $data['input_kind'],
                    'input_label' => $data['input_kind'] === 'account' ? 'Threads 帳號' : 'Threads 貼文網址',
                    'status' => 'published',
                ]);
            }

            foreach ($data['labels'] as $i => $label) {
                if (! ServiceVariant::query()->where('service_id', $service->id)->where('label', $label)->exists()) {
                    ServiceVariant::factory()->published()->create([
                        'service_id' => $service->id,
                        'label' => $label,
                        'sort_order' => $i,
                    ]);
                }
            }
        }
    }
}
