<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Platform extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'first_published_at' => 'datetime',
    ];

    public function services(): HasMany
    {
        return $this->hasMany(Service::class)->orderBy('sort_order');
    }

    public function faqs(): HasMany
    {
        return $this->hasMany(Faq::class);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published');
    }

    /** slug 一旦首次發布即鎖定，改 URL 必須走未來的 SEO migration workflow。 */
    public function isSlugLocked(): bool
    {
        return $this->first_published_at !== null;
    }
}
