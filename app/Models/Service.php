<?php

namespace App\Models;

use App\Support\CanonicalUrl;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Service extends Model
{
    use HasFactory, SoftDeletes;

    public const INPUT_KINDS = ['account', 'post_url', 'video_url', 'page_url'];

    protected $guarded = [];

    protected $casts = [
        'is_featured' => 'boolean',
        'show_variant_guide' => 'boolean',
        'first_published_at' => 'datetime',
    ];

    public function platform(): BelongsTo
    {
        return $this->belongsTo(Platform::class);
    }

    public function supportsVariantGuide(): bool
    {
        return in_array($this->slug, ['followers', 'post-likes'], true);
    }

    public function showsVariantGuide(): bool
    {
        return $this->supportsVariantGuide() && ($this->show_variant_guide ?? true);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ServiceVariant::class)->orderBy('sort_order');
    }

    public function contentSections(): HasMany
    {
        return $this->hasMany(ServiceContentSection::class)->orderBy('sort_order');
    }

    public function faqs(): HasMany
    {
        return $this->hasMany(Faq::class);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published');
    }

    public function isSlugLocked(): bool
    {
        return $this->first_published_at !== null;
    }

    /**
     * product slug allowlist:Unicode 中文、ASCII 小寫字母、數字與 `-`。
     *
     * ⛔ 禁止 `/`、`?`、`#`、`.`、空白、控制字元與編碼繞過;這是 D-103
     * `/product/` canonical 的 request validation 半邊(另半邊是 DB unique)。
     */
    public static function isValidProductSlug(string $slug): bool
    {
        return $slug !== '' && preg_match('/\A[\p{Han}a-z0-9-]+\z/u', $slug) === 1;
    }

    /**
     * The single primary public URL for this service.
     *
     * ⛔ D-103:有 `product_slug` 的商品,唯一主要形式是尾斜線的
     * `/product/{slug}/`;canonical、內鏈與 route 產生一律用這一個形式。
     * 尚無 product slug(draft/comments/auto-likes)才退回商品級
     * `/services/...` 路由(僅供預覽,不對 guest 成頁)。
     */
    public function primaryUrl(): string
    {
        /*
         * ⛔⛔ M5A-1 R1：改用 trusted origin，⛔ 不再用 request-aware 的
         * `route()`。
         *
         * ⭐ GPT 以真實 HTTP 實測反證：`Host: evil.test` 開商品頁時，
         * `route()` 會用 request 的 Host 組出
         * `http://evil.test/product/...`，於是 **HTML canonical 與所有
         * 商品內鏈都被污染**。初版我只保護了 middleware 與 sitemap，
         * ⛔ 漏掉了頁面本身——那是最容易被搜尋引擎採信的一處。
         *
         * ⭐ `CanonicalUrl::to()` 只讀 `config('app.url')`，
         * 攻擊者碰不到；同時它與 redirect Location／sitemap 使用
         * **同一種** percent-encoded 形式，⛔ 不會出現同頁兩種表示法。
         */
        if (filled($this->product_slug)) {
            return CanonicalUrl::to('/product/'.$this->product_slug.'/');
        }

        /*
         * ⛔ 無 product slug（draft／comments／auto-likes）只供預覽，
         * ⛔ 不對 guest 成頁；但仍不得採任意 Host。
         */
        return CanonicalUrl::to('/services/'.$this->platform->slug.'/'.$this->slug);
    }
}
