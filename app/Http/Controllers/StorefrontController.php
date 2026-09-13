<?php

namespace App\Http\Controllers;

use App\Models\Service;
use App\Models\ServiceVariant;
use App\Models\SiteSetting;
use App\Support\CanonicalUrl;
use App\Support\CatalogRepository;
use App\Support\CheckoutSession;
use App\Support\FaqPageContent;
use App\Support\StorefrontStructuredData;
use App\Support\VariantGuide;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class StorefrontController extends Controller
{
    public function __construct(
        private readonly CatalogRepository $catalog,
        private readonly CheckoutSession $checkout,
        // M5B：只組裝 JSON-LD，⛔ 不查 DB、⛔ 不外呼。
        private readonly StorefrontStructuredData $structuredData,
    ) {}

    /**
     * R5:共通 FAQ 頁(`/faq`)。
     *
     * global FAQ 的唯一完整 owner:9 題 published 全文只在此頁。⛔ draft
     * (訂單查詢功能尚未驗收)與 soft-deleted 列由 published() 擋掉;
     * 平台／商品專屬 12 題不在這裡複製全文,只給描述性導覽內鏈。
     */
    public function faq(FaqPageContent $content): View
    {
        /*
         * ⛔ M5B：Schema 與畫面**共用同一個** collection，⛔ 不另查一次。
         * ⭐ 再查一次就會有兩份可能不同的清單——標記宣告的問答必須恰好是
         * 這一頁渲染出來的那幾題。
         */
        $faqs = $this->catalog->globalFaqs();
        $h1 = $content->h1();
        $intro = $content->intro();

        return view('storefront.faq', [
            'faqs' => $faqs,
            'platforms' => $this->catalog->navigablePlatforms(),
            'title' => $content->seoTitle(),
            'description' => $content->metaDescription(),
            'h1' => $h1,
            'intro' => $intro,
            'structuredData' => $this->structuredData->faq(
                $faqs,
                $h1,
                $intro,
                SiteSetting::current(),
            ),
            /*
             * ⛔ M5A-1 R1：改用 trusted origin。
             * ⭐ `route()` 是 request-aware 的，`Host: evil.test` 會讓
             * canonical 變成攻擊者的網域（GPT 實測反證）。
             */
            'canonical' => CanonicalUrl::to('/faq'),
        ]);
    }

    public function home(FaqPageContent $content): View
    {
        $settings = SiteSetting::current();

        return view('storefront.home', [
            'platforms' => $this->catalog->navigablePlatforms(),
            // R5:首頁只重複核准的精選題;完整 9 題以 /faq 為唯一 owner。
            'faqs' => $this->catalog->featuredGlobalFaqs($content->homeFeaturedKeys()),
            'settings' => $settings,
            // M2-C:首頁 self-canonical(全站仍 noindex,canonical 只是宣告主形式)。
            // ⛔ M5A-1 R1：trusted origin,⛔ 不用 request-aware 的 `url()`。
            'canonical' => CanonicalUrl::to('/'),
            // CTA 目的地由設定的固定目標決定，⛔ 不再取「排序第一個」而隨排序漂移。
            'ctaUrl' => $settings?->ctaUrl() ?? route('home').'#platforms',
            // ⛔ M5B：沿用**同一個** `$settings`，⛔ 不再查一次單例。
            'structuredData' => $this->structuredData->home($settings),
        ]);
    }

    public function platform(Request $request, string $platform): View|Response
    {
        $preview = $this->wantsPreview($request);

        $record = $preview
            ? $this->catalog->findPlatformForPreview($platform)
            : $this->catalog->findPlatform($platform);

        abort_if($record === null, 404);

        $view = view('storefront.platform', [
            'platform' => $record,
            'faqs' => $this->catalog->platformFaqs($record),
            'isPreview' => $preview,
            // ⛔ preview 不輸出可索引 canonical。
            // ⛔ M5A-1 R1：trusted origin(preview 仍不輸出 canonical)。
            'canonical' => $preview ? null : CanonicalUrl::to('/services/'.$record->slug),
            /*
             * ⛔⛔ M5B：preview 一律不輸出圖譜。
             * ⭐ 草稿平台的名稱與 tagline 還沒對外，而 JSON-LD 是給機器讀的
             * ——⛔ 即使頁面是 noindex，也不該把未發布內容寫成結構化宣告。
             */
            'structuredData' => $preview
                ? null
                : $this->structuredData->platform($record, SiteSetting::current()),
        ]);

        return $preview ? $this->noindex($view) : $view;
    }

    /**
     * D-103 canonical 商品頁:`/product/{slug}/`。
     *
     * ⛔ 唯一一致形式=尾斜線;非尾斜線請求在本機以 302 收斂到主形式
     * (正式 301 屬 M5,不在本輪)。slug shape 不合 allowlist 一律 404。
     */
    public function product(Request $request, string $product): View|Response|RedirectResponse
    {
        $record = $this->catalog->findServiceByProductSlug($product);

        abort_if($record === null, 404);

        $canonical = $record->primaryUrl();

        /*
         * ⛔⛔ M5A：尾斜線收斂已**整個移到** `CanonicalUrlRedirect`。
         *
         * ⭐ 原本這裡有一段 302，還帶著 `runningUnitTests()` 的例外
         * ——因為測試 client 的 `prepareUrlForRequest()` 會 `trim($uri,'/')`，
         * 永遠送不出尾斜線，那段收斂在測試裡會自我迴圈。
         *
         * ⛔ 那個例外的代價是：真正的收斂行為**從來沒有被測到**。
         * ⭐ 現在由 middleware 統一處理（施工單要求「一個集中 resolver」），
         * 而 M5A 測試以 `Request::create()` 直接餵 kernel——它保留尾斜線，
         * ⭐ 所以兩個方向都測得到，⛔ 不再需要任何 runtime 例外。
         */
        return $this->renderServicePage($request, $record, preview: false, canonical: $canonical);
    }

    public function service(Request $request, string $platform, string $service): View|Response|RedirectResponse
    {
        $preview = $this->wantsPreview($request);

        if ($preview) {
            $record = $this->catalog->findServiceForPreview($platform, $service);

            abort_if($record === null, 404);

            return $this->renderServicePage($request, $record, preview: true, canonical: null);
        }

        $record = $this->catalog->findService($platform, $service);

        /*
         * ⛔ D-103：商品級 `/services/...` 不得形成可索引第二頁。
         *
         * ⛔⛔ M5A：guest 的收斂已由 `CanonicalUrlRedirect` 以 **301** 處理，
         * 而且是在 controller 之前——所以走到這裡的 guest request，
         * 代表該組合**不在** `ProductSlugMap` 裡（例如 comments／auto-likes）。
         *
         * ⭐ 那種情況維持 404：⛔ 不新增 SEO 頁、⛔ 不轉址（施工單 §2.3）。
         *
         * ⛔ 這裡刻意不再自己 redirect：同一條規則若同時存在於 middleware
         * 與 controller，兩邊有一天會不一致，而 301 是永久的。
         */
        abort_if($record === null, 404);

        abort(404);
    }

    /** 商品頁共用渲染;product canonical 與 authenticated preview 都走這裡。 */
    private function renderServicePage(Request $request, Service $record, bool $preview, ?string $canonical): View|Response
    {
        $resumed = $this->resumedSelection($request, $record)
            ?? $this->selectionFromOldInput($record);

        $settings = SiteSetting::current();

        $view = view('storefront.service', [
            'service' => $record,
            'platform' => $record->platform,
            'isPreview' => $preview,
            'canonical' => $canonical,
            'resumedVariantId' => $resumed['variant']->id ?? null,
            'resumedQuantity' => $resumed['quantity'] ?? null,
            'variantGuide' => $record->showsVariantGuide()
                ? ($settings?->variantGuide() ?? VariantGuide::defaults())
                : null,
            /*
             * ⛔⛔ M5B：preview 一律不輸出圖譜（同 Hub 的理由）。
             *
             * ⭐ 圖譜**不**含 resume 狀態：`$resumed` 只影響畫面預選的方案，
             * ⛔ 不改商品身份。返回修改與直接進站必須得到同一份標記，
             * 否則同一個 canonical 會出現兩種機器可讀描述。
             */
            'structuredData' => $preview
                ? null
                : $this->structuredData->product($record, $settings),
        ]);

        return $preview ? $this->noindex($view) : $view;
    }

    /**
     * The selection to restore after "返回修改".
     *
     * Gated on a one-shot session marker rather than a query parameter, so the
     * product page keeps a single crawlable URL. The marker is consumed on
     * read: refreshing the clean URL afterwards shows the featured item again.
     *
     * @return array{variant: ServiceVariant, quantity: int}|null
     */
    private function resumedSelection(Request $request, Service $record): ?array
    {
        if (! $this->checkout->pullResume($request)) {
            return null;
        }

        $selection = $this->checkout->resolve($request);

        // ⛔ 別的服務的選擇不得套用到這一頁。
        return $selection !== null && $selection['variant']->service->is($record)
            ? $selection
            : null;
    }

    /**
     * The selection to restore after a failed /checkout/start.
     *
     * A rejected quantity should not also discard the chosen item, but the old
     * variant is re-checked against this service's published list first: an
     * unknown, draft, archived or foreign id is ignored rather than trusted.
     *
     * @return array{variant: ServiceVariant, quantity: int}|null
     */
    private function selectionFromOldInput(Service $record): ?array
    {
        $oldVariant = old('variant');

        if ($oldVariant === null) {
            return null;
        }

        $variant = $this->catalog->findPurchasableVariant($oldVariant);

        if ($variant === null || ! $variant->service->is($record)) {
            return null;
        }

        $quantity = old('quantity');

        return [
            'variant' => $variant,
            'quantity' => is_numeric($quantity) ? (int) $quantity : $variant->default_quantity,
        ];
    }

    /**
     * Draft preview is available only to an authenticated admin user.
     *
     * Guests never see draft content, so ?preview=1 is inert in public hands.
     */
    private function wantsPreview(Request $request): bool
    {
        if (! $request->boolean('preview')) {
            return false;
        }

        $user = $request->user();

        return $user !== null && ($user->isOwner() || $user->isEditor());
    }

    /**
     * Preview responses carry their own noindex header.
     *
     * They must not rely on the site-wide IndexingPolicy: once the public site
     * is opened to indexing, a preview URL would otherwise become indexable and
     * could leak unpublished content into search results.
     */
    private function noindex(View $view): Response
    {
        return response($view)->withHeaders([
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
