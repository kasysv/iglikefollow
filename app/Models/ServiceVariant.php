<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ServiceVariant extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'is_featured' => 'boolean',
        'unit_price' => 'decimal:4',
        'first_published_at' => 'datetime',
    ];

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published');
    }

    /** 數量邊界檢查；⛔ 伺服器端唯一真實來源，不信任前端送出的值。 */
    public function quantityIsValid(int $quantity): bool
    {
        return $quantity >= $this->min_quantity
            && $quantity <= $this->max_quantity
            && $quantity % $this->step_quantity === 0;
    }

    /** 依單價重算金額；⛔ 前端送來的任何價格欄位一律忽略。 */
    public function amountFor(int $quantity): int
    {
        return (int) round($quantity * (float) $this->unit_price);
    }
}
