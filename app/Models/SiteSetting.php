<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteSetting extends Model
{
    use HasFactory;

    /** CTA 只能指向這些既有內部 route，⛔ 不接受任意 URL。 */
    public const CTA_ROUTES = ['home', 'platform', 'service'];

    protected $guarded = [];

    /**
     * The homepage's three selling points, in display order.
     *
     * ⛔ A pair only appears when both halves are filled in. A title with no
     * body (or the reverse) renders as a stray fragment on the homepage, and a
     * half-typed save should not be able to put that in front of customers.
     *
     * Returns an empty array when nothing is configured, which the template
     * reads as "use the built-in defaults" — so the strip never renders blank.
     *
     * @return list<array{title: string, body: string}>
     */
    public function homeHighlights(): array
    {
        $highlights = [];

        foreach ([1, 2, 3] as $i) {
            $title = trim((string) $this->{"home_highlight_{$i}_title"});
            $body = trim((string) $this->{"home_highlight_{$i}_body"});

            if ($title !== '' && $body !== '') {
                $highlights[] = ['title' => $title, 'body' => $body];
            }
        }

        return $highlights;
    }

    /** 單例：永遠只有一筆。 */
    public static function current(): ?self
    {
        return static::query()->orderBy('id')->first();
    }

    public function ctaPlatform(): BelongsTo
    {
        return $this->belongsTo(Platform::class, 'primary_cta_platform_id');
    }

    public function ctaService(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'primary_cta_service_id');
    }

    /**
     * The site's public-facing name.
     *
     * Falls back to the brand so a blank settings row never renders an empty
     * header or an empty accessible label.
     */
    public function displayName(): string
    {
        return filled($this->company_name) ? $this->company_name : 'IGLIKEFOLLOW';
    }

    /**
     * Resolve the homepage CTA to a concrete URL.
     *
     * Anything unresolvable — an unknown route name, a target that was deleted,
     * or a target that is no longer published — degrades to the homepage
     * platform picker. That is always a valid destination, so the site's main
     * button can never 404 or point off-site.
     */
    public function ctaUrl(): string
    {
        $anchor = route('home').'#platforms';

        if (! in_array($this->primary_cta_route, self::CTA_ROUTES, true)) {
            return $anchor;
        }

        if ($this->primary_cta_route === 'platform') {
            $platform = $this->ctaPlatform;

            return $platform?->status === 'published'
                ? route('platform', $platform->slug)
                : $anchor;
        }

        if ($this->primary_cta_route === 'service') {
            $service = $this->ctaService;

            return ($service?->status === 'published' && $service->platform?->status === 'published')
                ? $service->primaryUrl()
                : $anchor;
        }

        return $anchor;
    }
}
