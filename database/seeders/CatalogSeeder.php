<?php

namespace Database\Seeders;

use App\Models\Faq;
use App\Models\Platform;
use App\Models\Service;
use App\Models\ServiceVariant;
use App\Models\SiteSetting;
use Illuminate\Database\Seeder;

/**
 * Imports config/catalog.php into the database.
 *
 * Idempotent by design: it matches on slug and updates in place, so running it
 * repeatedly never duplicates rows and never clobbers a published record's
 * first_published_at. config/catalog.php stays as a seed fixture only — it is
 * no longer a runtime fallback for the storefront.
 */
class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedSiteSettings();

        foreach (config('catalog.platforms') as $platformData) {
            $platform = Platform::withTrashed()->updateOrCreate(
                ['slug' => $platformData['slug']],
                [
                    'name' => $platformData['name'],
                    'eyebrow' => $platformData['name'],
                    'h1' => $platformData['name'].' 社群成長服務',
                    'tagline' => $platformData['tagline'],
                    'intro' => $platformData['unavailable_note'] ?? null,
                    'seo_title' => $platformData['name'].' 社群成長服務｜IGLIKEFOLLOW',
                    'meta_description' => $platformData['tagline'],
                    'status' => $platformData['available'] ? 'published' : 'draft',
                    'sort_order' => $this->platformOrder($platformData['slug']),
                    'deleted_at' => null,
                ]
            );

            $platform->forceFill([
                'first_published_at' => $platform->first_published_at
                    ?? ($platform->status === 'published' ? now() : null),
            ])->saveQuietly();

            $this->seedServices($platform, $platformData['services']);
        }

        $this->seedGlobalFaqs();
    }

    private function seedSiteSettings(): void
    {
        if (SiteSetting::current()) {
            return;
        }

        SiteSetting::create([
            'company_name' => 'IGLIKEFOLLOW',
            'home_eyebrow' => 'Social growth services',
            'home_h1' => '多平台社群服務，一次選好。',
            'home_intro' => 'IGLIKEFOLLOW 提供 Instagram 與 Facebook 的粉絲、讚、留言與影片觀看服務。'
                .'先選擇平台，再選擇需要的服務類型，最後挑選數量方案並免會員結帳。',
            'primary_cta_label' => '選擇平台服務',
            'primary_cta_route' => 'home',
        ]);
    }

    private function seedServices(Platform $platform, array $services): void
    {
        $order = 0;

        foreach ($services as $serviceData) {
            $service = Service::withTrashed()->updateOrCreate(
                ['platform_id' => $platform->id, 'slug' => $serviceData['slug']],
                [
                    'name' => $serviceData['name'],
                    'card_title' => $serviceData['name'],
                    'h1' => $serviceData['name'],
                    'summary' => $serviceData['summary'],
                    'goal' => $serviceData['goal'] ?? null,
                    'card_blurb' => $serviceData['card_blurb'] ?? null,
                    'input_kind' => $this->inputKind($serviceData['input_label']),
                    'input_label' => $serviceData['input_label'],
                    'input_hint' => $serviceData['input_hint'],
                    'delivery_summary' => $serviceData['delivery'],
                    'seo_title' => $serviceData['name'].'｜IGLIKEFOLLOW',
                    'meta_description' => $serviceData['summary'],
                    'is_featured' => ! empty($serviceData['featured_service']),
                    'status' => 'published',
                    'sort_order' => $order++,
                    'deleted_at' => null,
                ]
            );

            $service->forceFill([
                'first_published_at' => $service->first_published_at ?? now(),
            ])->saveQuietly();

            $this->seedVariants($service, $serviceData);
        }
    }

    /**
     * Create missing variants from the fixture; ⛔ never overwrite existing ones.
     *
     * These are mock starting values, but once a variant exists it is managed
     * from the admin: prices, quantity steps and publication status are business
     * decisions someone made deliberately. Re-running the seeder used to reset
     * all of them, which would silently republish a draft that was taken down on
     * purpose and undo corrected quantity steps. A seeder is for filling gaps,
     * not for asserting authority over live data.
     */
    private function seedVariants(Service $service, array $serviceData): void
    {
        $unit = $serviceData['quantity_unit'] ?? '個';
        $order = 0;

        foreach ($serviceData['variants'] as $sku => $variantData) {
            $position = $order++;

            // ⛔ 已存在（含軟刪除）就完全不碰：狀態、價格、數量與上架時間都不動。
            if (ServiceVariant::withTrashed()->where('sku', $sku)->exists()) {
                continue;
            }

            // ⛔ 不在這裡寫 first_published_at：PublishObserver 已經是唯一的發布規則，
            // 它只在 status 真的是 published 時蓋首次發布時間。這裡再蓋一次會讓
            // 從未發布的草稿也帶著發布時間，等於謊稱它曾經上架過——而那個時間戳
            // 還會反過來永久鎖住 slug。
            ServiceVariant::create([
                'sku' => $sku,
                'service_id' => $service->id,
                'label' => $variantData['label'],
                'description' => $variantData['description'],
                'quantity_unit' => $unit,
                'min_quantity' => $variantData['quantity']['min'],
                'max_quantity' => $variantData['quantity']['max'],
                'step_quantity' => $variantData['quantity']['step'],
                'default_quantity' => $variantData['quantity']['default'],
                'unit_price' => $variantData['quantity']['unit_price'],
                'currency' => 'TWD',
                'is_featured' => ! empty($variantData['featured']),
                // fixture 未指定就預設上架；未確認價格組合的商品明確標為草稿。
                'status' => $variantData['status'] ?? 'published',
                'sort_order' => $position,
            ]);
        }
    }

    private function seedGlobalFaqs(): void
    {
        $faqs = [
            ['需要註冊會員嗎？', '不需要。正式流程會以免會員快速結帳為基線。'],
            ['單篇貼文讚與自動貼文讚差在哪裡？', '單篇貼文讚是輸入一條貼文網址，一次性交付該篇的讚數。自動貼文讚是輸入公開帳號並預付篇數，之後發布的新貼文依序自動交付，用完為止。'],
            ['付款後會立即建立訂單嗎？', '正式版必須由後端驗證綠界或 LINE Pay 付款成功後才建立履約流程，不能只相信前端成功頁。'],
            ['現在可以真的付款嗎？', '不可以。目前是本機 mock 骨架，沒有連接任何正式金流或下單平台。'],
        ];

        foreach ($faqs as $index => [$question, $answer]) {
            Faq::withTrashed()->updateOrCreate(
                ['scope' => 'global', 'question' => $question],
                [
                    'answer' => $answer,
                    'status' => 'published',
                    'sort_order' => $index,
                    'deleted_at' => null,
                ]
            );
        }
    }

    private function platformOrder(string $slug): int
    {
        return match ($slug) {
            'instagram' => 0,
            'facebook' => 1,
            default => 2,
        };
    }

    private function inputKind(string $label): string
    {
        return match (true) {
            str_contains($label, '貼文網址') => 'post_url',
            str_contains($label, '影片網址') => 'video_url',
            str_contains($label, '粉專') => 'page_url',
            default => 'account',
        };
    }
}
