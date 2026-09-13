<?php

namespace App\Support;

use App\Models\Faq;
use App\Models\Platform;
use App\Models\Service;
use App\Models\SiteSetting;
use Illuminate\Support\Collection;

/**
 * M5B：公開頁的最小 JSON-LD 圖譜。
 *
 * ⛔ 純資料組裝：⛔ 不外呼、⛔ 不寫 DB、⛔ 不 serialize 整個 Model。
 * ⭐ 每個欄位都顯式挑出來，所以將來新增一個 DB 欄位不會自動漏進 HTML。
 *
 * ⛔⛔ 所有絕對 URL 一律走 `CanonicalUrl`（只讀 `config('app.url')`）。
 * ⭐ 這是 M5A 學到的：`route()`／`url()` 是 request-aware 的，
 * `Host: evil.test` 會讓整份圖譜宣稱自己屬於攻擊者的網域——而 Schema
 * 的 `@id`／`url` 正是「這一頁是誰」的機器可讀宣告，被污染的後果比
 * 一條壞連結嚴重得多。
 */
final class StorefrontStructuredData
{
    /**
     * 品牌方形標誌。
     *
     * ⭐ Google 的 Organization logo 要求「至少 112x112px、可被抓取與索引、
     * 在純白背景下正確」；這張是 361x361 的既有品牌原圖。
     * ⛔ 不用 715x143 的 wordmark——它是橫式字標，不是方形 logo。
     * ⛔ 也不用 IG／FB 的平台 Logo 當本站品牌（那是別人的商標）。
     */
    private const LOGO_PATH = '/images/iglikefollow-mark.png';

    /** 麵包屑第一層，與所有頁面可見的「首頁」anchor 一致。 */
    private const HOME_NAME = '首頁';

    /**
     * `/faq` 麵包屑末層，與 `faq.blade.php` 寫死的可見文字一致。
     *
     * ⛔ 這不等於該頁的 H1（H1 是「IGLIKEFOLLOW 購買與訂單常見問題」）。
     * ⭐ 測試會直接從渲染後的 DOM 抽出 `aria-current="page"` 那一格比對，
     * 所以哪天 Blade 改字，這個常數會被測試抓出來。
     */
    private const FAQ_BREADCRUMB_NAME = '常見問題';

    /**
     * 首頁：Organization ＋ WebSite ＋ WebPage。
     *
     * @return array<string, mixed>
     */
    public function home(?SiteSetting $settings): array
    {
        $canonical = CanonicalUrl::to('/');

        $page = [
            '@type' => 'WebPage',
            '@id' => $canonical.'#webpage',
            'url' => $canonical,
            'name' => $this->homeH1($settings),
            'isPartOf' => ['@id' => $this->websiteId()],
            'about' => ['@id' => $this->organizationId()],
        ];

        $description = $this->clean($settings?->home_intro) ?? $this->homeIntroFallback();

        if ($description !== null) {
            $page['description'] = $description;
        }

        return $this->graph([
            $this->organization($settings),
            $this->website($settings),
            $page,
        ]);
    }

    /**
     * 平台 Hub：CollectionPage ＋ BreadcrumbList。
     *
     * @return array<string, mixed>
     */
    public function platform(Platform $platform, ?SiteSetting $settings): array
    {
        $canonical = CanonicalUrl::to('/services/'.$platform->slug);

        $page = [
            '@type' => 'CollectionPage',
            '@id' => $canonical.'#webpage',
            'url' => $canonical,
            'name' => $this->platformH1($platform),
            'isPartOf' => ['@id' => $this->websiteId()],
            'breadcrumb' => ['@id' => $canonical.'#breadcrumb'],
        ];

        $description = $this->clean($platform->tagline);

        if ($description !== null) {
            $page['description'] = $description;
        }

        return $this->graph([
            $this->organization($settings),
            $this->website($settings),
            $page,
            $this->breadcrumb($canonical, [
                [self::HOME_NAME, CanonicalUrl::to('/')],
                // ⭐ Hub 自己那一層沿用可見麵包屑：只有平台名，且不是連結。
                [$this->clean($platform->name) ?? $platform->slug, null],
            ]),
        ]);
    }

    /**
     * 商品頁：WebPage → mainEntity Service ＋ BreadcrumbList。
     *
     * ⛔ 本輪不輸出 Product／Offer／AggregateOffer／價格／評分／庫存。
     * ⭐ 一個服務有多個數量方案，把某一個單價當成「這一頁的價格」會誤導；
     * 價格 rich result 要另依實際方案與最低購買量核對。
     *
     * @return array<string, mixed>
     */
    public function product(Service $service, ?SiteSetting $settings): array
    {
        $canonical = $service->primaryUrl();
        $platform = $service->platform;

        $serviceNode = [
            '@type' => 'Service',
            '@id' => $canonical.'#service',
            'name' => $this->serviceH1($service),
            'url' => $canonical,
            'provider' => ['@id' => $this->organizationId()],
        ];

        $description = $this->clean($service->summary);

        if ($description !== null) {
            $serviceNode['description'] = $description;
        }

        $page = [
            '@type' => 'WebPage',
            '@id' => $canonical.'#webpage',
            'url' => $canonical,
            'name' => $this->serviceH1($service),
            'isPartOf' => ['@id' => $this->websiteId()],
            'breadcrumb' => ['@id' => $canonical.'#breadcrumb'],
            'mainEntity' => ['@id' => $canonical.'#service'],
        ];

        if ($description !== null) {
            $page['description'] = $description;
        }

        $trail = [[self::HOME_NAME, CanonicalUrl::to('/')]];

        /*
         * ⛔ 第二層沿用可見麵包屑的 exact anchor「{平台名}服務」，
         * ⭐ 不是平台的 `h1`——Schema 必須反映使用者看得到的那串字。
         */
        if ($platform !== null && filled($platform->slug)) {
            $trail[] = [
                ($this->clean($platform->name) ?? $platform->slug).'服務',
                CanonicalUrl::to('/services/'.$platform->slug),
            ];
        }

        // ⛔ 最後一層用可見麵包屑的 `$service->name`，⛔ 不是 H1。
        $trail[] = [$this->clean($service->name) ?? $service->slug, null];

        return $this->graph([
            $this->organization($settings),
            $this->website($settings),
            $page,
            $serviceNode,
            $this->breadcrumb($canonical, $trail),
        ]);
    }

    /**
     * `/faq`：FAQPage ＋ BreadcrumbList；⛔ 無有效問答時退回 WebPage。
     *
     * ⛔ 只採本頁實際顯示的已發布 global FAQ。
     * ⭐ Google 已於 2026-05-07 停止顯示 FAQ rich result；這裡只是
     * schema.org 語義標記，⛔ 不承諾任何搜尋呈現或 GEO／AEO 效果。
     *
     * @param  Collection<int, Faq>  $faqs
     * @return array<string, mixed>
     */
    public function faq(Collection $faqs, string $h1, ?string $intro, ?SiteSetting $settings): array
    {
        $canonical = CanonicalUrl::to('/faq');

        $questions = [];

        foreach ($faqs as $faq) {
            $question = $this->clean($faq->question);
            $answer = $this->clean($faq->answer);

            // ⛔ 半題不輸出：只有問題沒有答案的 Question 不描述任何可見內容。
            if ($question === null || $answer === null) {
                continue;
            }

            $questions[] = [
                '@type' => 'Question',
                'name' => $question,
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    /*
                     * ⛔ Blade 以 `{{ }}` 純文字輸出答案，所以這裡也送純文字。
                     * ⭐ 若擅自把其中的字元當 HTML 處理，標記就會與可見內容
                     * 不一致——那正是規範禁止的事。
                     */
                    'text' => $answer,
                ],
            ];
        }

        $page = [
            // ⛔ fail closed：沒有任何有效問答就不是 FAQPage。
            '@type' => $questions === [] ? 'WebPage' : 'FAQPage',
            '@id' => $canonical.'#webpage',
            'url' => $canonical,
            'name' => $this->clean($h1) ?? '常見問題',
            'isPartOf' => ['@id' => $this->websiteId()],
            'breadcrumb' => ['@id' => $canonical.'#breadcrumb'],
        ];

        $description = $this->clean($intro);

        if ($description !== null) {
            $page['description'] = $description;
        }

        if ($questions !== []) {
            $page['mainEntity'] = $questions;
        }

        return $this->graph([
            $this->organization($settings),
            $this->website($settings),
            $page,
            $this->breadcrumb($canonical, [
                [self::HOME_NAME, CanonicalUrl::to('/')],
                /*
                 * ⛔⛔ R1：末層固定用可見麵包屑的「常見問題」，⛔ 不是 H1。
                 *
                 * ⭐ 初版我在這裡放了 `$h1`，而 `/faq` 的 H1 是
                 * 「IGLIKEFOLLOW 購買與訂單常見問題」——可見麵包屑那一格
                 * 寫的卻是「常見問題」（`faq.blade.php` 的 `aria-current`
                 * 那個 `<li>` 是寫死的文字）。GPT 以真實 DOM 反證。
                 *
                 * ⭐ 這與 Hub／商品頁的處理一致：麵包屑沿用**可見麵包屑**的
                 * 字，而頁面節點的 `name` 才沿用 H1——兩者本來就是不同的字，
                 * ⛔ 標記必須各自對應自己那一處可見內容。
                 */
                [self::FAQ_BREADCRUMB_NAME, null],
            ]),
        ]);
    }

    /**
     * 安全 JSON 編碼，可直接放進 `<script type="application/ld+json">`。
     *
     * ⛔⛔ `</script>` 是這裡唯一會變成 XSS 的路徑：JSON 字串裡允許出現
     * `</script>`，但瀏覽器的 HTML parser 看到它就會提前關掉 script 元素，
     * 後面的內容變成 HTML。
     *
     * ⭐ `HEX_TAG` 把 `<` `>` 編成 `<` `>`，`HEX_AMP` 編 `&`，
     * 兩個引號旗標處理 `'` `"`。這些都仍是**合法 JSON**，
     * 標準 parser 解回來與原文逐字相同，⛔ 所以不是靠刪字或改寫內容。
     *
     * ⛔ 不用 `JSON_UNESCAPED_UNICODE`：中文編成 `\uXXXX` 一樣可解析，
     * 而且完全避開輸出端字元編碼的意外。
     *
     * @param  array<string, mixed>  $graph
     */
    public function encode(array $graph): string
    {
        $json = json_encode(
            $graph,
            JSON_UNESCAPED_SLASHES
            | JSON_HEX_TAG
            | JSON_HEX_AMP
            | JSON_HEX_APOS
            | JSON_HEX_QUOT
            | JSON_INVALID_UTF8_SUBSTITUTE
        );

        // ⛔ fail closed：編不出來就一個字都不輸出，⛔ 不輸出半份圖譜。
        return $json === false ? '' : $json;
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @return array<string, mixed>
     */
    private function graph(array $nodes): array
    {
        return [
            '@context' => 'https://schema.org',
            '@graph' => array_values($nodes),
        ];
    }

    /** @return array<string, mixed> */
    private function organization(?SiteSetting $settings): array
    {
        $node = [
            '@type' => 'Organization',
            '@id' => $this->organizationId(),
            'name' => $settings?->displayName() ?? 'IGLIKEFOLLOW',
            'url' => CanonicalUrl::to('/'),
        ];

        $logo = $this->logoUrl();

        if ($logo !== null) {
            $node['logo'] = $logo;
        }

        return $node;
    }

    /** @return array<string, mixed> */
    private function website(?SiteSetting $settings): array
    {
        return [
            '@type' => 'WebSite',
            '@id' => $this->websiteId(),
            'name' => $settings?->displayName() ?? 'IGLIKEFOLLOW',
            'url' => CanonicalUrl::to('/'),
            'publisher' => ['@id' => $this->organizationId()],
            /*
             * ⛔ 本輪不輸出 `potentialAction`／SearchAction：
             * ⭐ 本站沒有站內搜尋頁，宣告一個不存在的搜尋端點是不實標記。
             */
        ];
    }

    /**
     * @param  list<array{0: string, 1: string|null}>  $trail
     * @return array<string, mixed>
     */
    private function breadcrumb(string $canonical, array $trail): array
    {
        $items = [];

        foreach ($trail as $position => [$name, $url]) {
            $item = [
                '@type' => 'ListItem',
                'position' => $position + 1,
                'name' => $name,
            ];

            /*
             * ⛔ 最後一層（目前頁）不帶 `item`：可見麵包屑的那一格也不是
             * 連結，而是 `aria-current="page"` 的純文字。
             */
            if ($url !== null) {
                $item['item'] = $url;
            }

            $items[] = $item;
        }

        return [
            '@type' => 'BreadcrumbList',
            '@id' => $canonical.'#breadcrumb',
            'itemListElement' => $items,
        ];
    }

    private function organizationId(): string
    {
        return CanonicalUrl::to('/').'#organization';
    }

    private function websiteId(): string
    {
        return CanonicalUrl::to('/').'#website';
    }

    /** ⛔ 只有檔案真的在才宣告 logo，⛔ 不指向不存在的資產。 */
    private function logoUrl(): ?string
    {
        return is_file(public_path(ltrim(self::LOGO_PATH, '/')))
            ? CanonicalUrl::to(self::LOGO_PATH)
            : null;
    }

    /*
     |---------------------------------------------------------------------
     | 可見文字的 fallback
     |
     | ⛔⛔ 以下三個必須與 Blade 的 fallback **逐字相同**。
     | ⭐ 一旦分歧，Schema 就會宣告一個頁面上並不存在的名稱——那正是
     | Google 結構化資料規範禁止的事。測試直接比對兩邊的字串。
     |---------------------------------------------------------------------
     */

    private function homeH1(?SiteSetting $settings): string
    {
        return $this->clean($settings?->home_h1) ?? '多平台社群服務，一次選好。';
    }

    private function homeIntroFallback(): string
    {
        return 'IGLIKEFOLLOW 提供 Instagram 與 Facebook 的粉絲、讚、留言與影片觀看服務。先選擇平台，再選擇需要的服務類型，最後挑選數量方案並免會員結帳。';
    }

    private function platformH1(Platform $platform): string
    {
        return $this->clean($platform->h1)
            ?? (($this->clean($platform->name) ?? $platform->slug).' 社群成長服務');
    }

    private function serviceH1(Service $service): string
    {
        return $this->clean($service->h1) ?? $this->clean($service->name) ?? $service->slug;
    }

    /** 空字串與只有空白的欄位一律當成「沒有值」，⛔ 不輸出空節點。 */
    private function clean(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
