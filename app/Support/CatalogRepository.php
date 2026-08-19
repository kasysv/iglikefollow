<?php

namespace App\Support;

use App\Models\Faq;
use App\Models\Platform;
use App\Models\Service;
use App\Models\ServiceVariant;
use Illuminate\Support\Collection;

/**
 * Single read path for storefront catalog data.
 *
 * Public callers only ever see published rows. There is deliberately no
 * fallback to config/catalog.php: if the database is empty the storefront
 * shows an honest empty state rather than silently serving seed fixtures.
 *
 * Draft records are reachable only through the *ForPreview methods, which
 * callers must gate behind an authenticated admin.
 */
class CatalogRepository
{
    /** @return Collection<int, Platform> */
    public function publishedPlatforms(): Collection
    {
        return Platform::query()
            ->published()
            ->with(['services' => fn ($q) => $q->published()->with(['variants' => fn ($v) => $v->published()])])
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * Platforms shown in public navigation.
     *
     * Only published platforms appear: linking to a draft produced a 404 for
     * visitors, because findPlatform() correctly refuses to serve drafts.
     * A platform with no sellable services yet still shows as an honest
     * "準備中" empty state, but it must be reachable to be linked.
     */
    public function navigablePlatforms(): Collection
    {
        return Platform::query()
            ->published()
            ->withCount(['services' => fn ($q) => $q->published()])
            ->orderBy('sort_order')
            ->get();
    }

    public function findPlatform(string $slug): ?Platform
    {
        return Platform::query()
            ->published()
            ->where('slug', $slug)
            /*
             * ⛔ M2-C(D-103):公開列表只列有 product_slug 的服務——published
             * 但尚無 slug(如 auto-likes 過渡期)沒有可指的 guest 頁,列出
             * 就是壞連結,也違反「公開 HTML 商品內鏈 0 個 /services/...」。
             */
            ->with(['services' => fn ($q) => $q->published()->whereNotNull('product_slug')->orderBy('sort_order')
                ->with(['variants' => fn ($v) => $v->published()->orderBy('sort_order')])])
            ->first();
    }

    /** Draft-inclusive lookup for authenticated preview only. */
    public function findPlatformForPreview(string $slug): ?Platform
    {
        return Platform::query()
            ->whereIn('status', ['published', 'draft'])
            ->where('slug', $slug)
            ->with(['services' => fn ($q) => $q->whereIn('status', ['published', 'draft'])->orderBy('sort_order')
                ->with(['variants' => fn ($v) => $v->whereIn('status', ['published', 'draft'])->orderBy('sort_order')])])
            ->first();
    }

    public function findService(string $platformSlug, string $serviceSlug): ?Service
    {
        $platform = Platform::query()->published()->where('slug', $platformSlug)->first();

        if (! $platform) {
            return null;
        }

        return Service::query()
            ->published()
            ->where('platform_id', $platform->id)
            ->where('slug', $serviceSlug)
            ->with([
                'platform',
                'variants' => fn ($q) => $q->published()->orderBy('sort_order'),
                'contentSections' => fn ($q) => $q->published()->orderBy('sort_order'),
                'faqs' => fn ($q) => $q->published()->orderBy('sort_order'),
            ])
            ->first();
    }

    /**
     * D-103 `/product/{slug}/` canonical 查找:published service+published
     * platform,只認 `product_slug`。⛔ shape 不合 allowlist 直接 null,
     * 不進 DB 查詢。
     */
    public function findServiceByProductSlug(string $productSlug): ?Service
    {
        if (! Service::isValidProductSlug($productSlug)) {
            return null;
        }

        return Service::query()
            ->published()
            ->where('product_slug', $productSlug)
            ->whereHas('platform', fn ($p) => $p->published())
            ->with([
                'platform',
                'variants' => fn ($q) => $q->published()->orderBy('sort_order'),
                'contentSections' => fn ($q) => $q->published()->orderBy('sort_order'),
                'faqs' => fn ($q) => $q->published()->orderBy('sort_order'),
            ])
            ->first();
    }

    public function findServiceForPreview(string $platformSlug, string $serviceSlug): ?Service
    {
        $platform = Platform::query()
            ->whereIn('status', ['published', 'draft'])
            ->where('slug', $platformSlug)
            ->first();

        if (! $platform) {
            return null;
        }

        return Service::query()
            ->whereIn('status', ['published', 'draft'])
            ->where('platform_id', $platform->id)
            ->where('slug', $serviceSlug)
            ->with([
                'platform',
                'variants' => fn ($q) => $q->whereIn('status', ['published', 'draft'])->orderBy('sort_order'),
                'contentSections' => fn ($q) => $q->whereIn('status', ['published', 'draft'])->orderBy('sort_order'),
                'faqs' => fn ($q) => $q->whereIn('status', ['published', 'draft'])->orderBy('sort_order'),
            ])
            ->first();
    }

    /**
     * Published variants keyed by id, used as the checkout allow-list.
     *
     * Rebuilt from the database on every request so a submitted variant key
     * can never widen what the server accepts, and draft or archived variants
     * are never purchasable.
     *
     * @return Collection<int, ServiceVariant>
     */
    public function purchasableVariants(): Collection
    {
        return ServiceVariant::query()
            ->published()
            ->whereHas('service', fn ($q) => $q->published()
                ->whereHas('platform', fn ($p) => $p->published()))
            ->with('service.platform')
            ->get()
            ->keyBy('id');
    }

    public function findPurchasableVariant(int|string $id): ?ServiceVariant
    {
        return $this->purchasableVariants()->get((int) $id);
    }

    /** @return Collection<int, Faq> */
    public function globalFaqs(): Collection
    {
        return Faq::query()
            ->published()
            ->where('scope', 'global')
            ->orderBy('sort_order')
            ->get();
    }

    /** @return Collection<int, Faq> */
    public function platformFaqs(Platform $platform): Collection
    {
        return Faq::query()
            ->published()
            ->where('scope', 'platform')
            ->where('platform_id', $platform->id)
            ->orderBy('sort_order')
            ->get();
    }
}
