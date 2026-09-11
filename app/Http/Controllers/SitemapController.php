<?php

namespace App\Http\Controllers;

use App\Support\CanonicalUrl;
use Illuminate\Http\Response;

/**
 * The 14 indexable canonical URLs — nothing else.
 *
 * ⛔⛔ sitemap 是我們對搜尋引擎的**主動聲明**：「這些是我要你收錄的頁」。
 * ⭐ 因此它是一份 allowlist，⛔ 不是「把所有 route 掃出來再濾掉幾個」。
 *
 * ⛔ 明確**不含**（施工單 §3）：staging URL、query、fragment、
 * redirect 來源（15 條 legacy）、商品級 services alias、comments、
 * auto-likes、checkout、order-check、admin、payment、health、preview。
 *
 * ⭐ 這些不是「忘了加」，而是每一個都有理由不該被收錄：
 * redirect 來源會讓 Google 一直重爬已經搬走的 URL；
 * alias 與 canonical 內容相同，列入兩者等於要求收錄同一頁的兩個 URL；
 * checkout／order-check 含交易與個資；preview 是未發布內容。
 *
 * ⛔ 不輸出 `lastmod`：我們沒有可信的「內容最後實質變更時間」，
 * ⭐ 拿 `updated_at` 充數只會讓 Google 學會忽略這個欄位。
 */
class SitemapController extends Controller
{
    public function __invoke(): Response
    {
        $urls = array_map(
            static fn (string $path): string => CanonicalUrl::to($path),
            CanonicalUrl::indexablePaths(),
        );

        $xml = $this->render($urls);

        return response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
        ]);
    }

    /**
     * @param  list<string>  $urls
     */
    private function render(array $urls): string
    {
        $lines = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">',
        ];

        foreach ($urls as $url) {
            /*
             * ⛔ 逐字 escape。
             *
             * ⭐ 商品 URL 含中文，rawurlencode 後不含 XML 特殊字元；
             * ⛔ 但仍然 escape：sitemap 是機器讀的 XML，
             * 一個沒 escape 的 `&` 就會讓整份文件變成 invalid，
             * 而 Google 對 invalid sitemap 的處理是**整份丟棄**。
             */
            $lines[] = '  <url><loc>'.htmlspecialchars($url, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</loc></url>';
        }

        $lines[] = '</urlset>';

        return implode("\n", $lines)."\n";
    }
}
