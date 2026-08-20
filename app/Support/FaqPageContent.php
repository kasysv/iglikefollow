<?php

namespace App\Support;

use RuntimeException;

/**
 * R5:`/faq` 頁的固定 metadata 與首頁精選 key。
 *
 * 唯一來源=repo 內 R5 fixture,與 `m2c:apply-r5-faq` 共用同一份檔案,
 * ⛔ 避免頁面 Title/H1/首段與 DB 內容各自漂移。這裡只讀 `page` 段:
 * 問答文字仍只從 DB 的 published 列讀取,不從 fixture 直接輸出。
 */
class FaqPageContent
{
    /** @var array<string, mixed>|null */
    private static ?array $cache = null;

    public function seoTitle(): string
    {
        return $this->value('seo_title');
    }

    public function metaDescription(): string
    {
        return $this->value('meta_description');
    }

    public function h1(): string
    {
        return $this->value('h1');
    }

    public function intro(): string
    {
        return $this->value('intro');
    }

    /** @return list<string> */
    public function homeFeaturedKeys(): array
    {
        $keys = $this->page()['home_featured_keys'] ?? null;

        if (! is_array($keys)) {
            throw new RuntimeException('R5 fixture 缺少 home_featured_keys。');
        }

        return array_values(array_map('strval', $keys));
    }

    private function value(string $field): string
    {
        $value = $this->page()[$field] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException("R5 fixture page.{$field} 非有效字串。");
        }

        return $value;
    }

    /** @return array<string, mixed> */
    private function page(): array
    {
        if (self::$cache === null) {
            $decoded = json_decode((string) file_get_contents(database_path('fixtures/m2c-r5-faq.json')), true);

            if (! is_array($decoded) || ! is_array($decoded['page'] ?? null)) {
                throw new RuntimeException('R5 fixture 缺失或無法解析。');
            }

            self::$cache = $decoded['page'];
        }

        return self::$cache;
    }

    /** 測試用:清掉 process 內快取。 */
    public static function flush(): void
    {
        self::$cache = null;
    }
}
