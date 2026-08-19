<?php

namespace App\Support;

/**
 * D-103 固定 mapping:internal `platform.slug/service.slug` → `product_slug`。
 *
 * ⛔ 唯一事實來源(importer 與 seeder 共用),只依 slug 對應、不做名稱
 * 模糊匹配;comments 與 auto-likes 不在表內,product_slug 必須保持 null。
 */
final class ProductSlugMap
{
    public const MAP = [
        'instagram/followers' => 'ig買粉絲',
        'instagram/post-likes' => 'ig買like',
        'instagram/video-views' => 'ig影片觀看',
        'facebook/followers' => 'fb買粉絲',
        'facebook/post-likes' => 'fb買like',
        'facebook/video-views' => 'fb影片觀看',
        'threads/followers' => 'threads買粉絲',
        'threads/post-likes' => 'threads買讚',
        'threads/post-boost' => 'threads貼文瀏覽',
    ];

    public static function for(string $platformSlug, string $serviceSlug): ?string
    {
        return self::MAP[$platformSlug.'/'.$serviceSlug] ?? null;
    }
}
