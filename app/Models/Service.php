<?php

namespace App\Models;

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
        'first_published_at' => 'datetime',
    ];

    public function platform(): BelongsTo
    {
        return $this->belongsTo(Platform::class);
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
}
