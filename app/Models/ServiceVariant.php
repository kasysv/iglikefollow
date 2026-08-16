<?php

namespace App\Models;

use App\Support\Money;
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

    /**
     * The unit price in mills (ten-thousandths of NT$), exactly as stored.
     *
     * Read from the raw column rather than the decimal cast, so a price like
     * 0.1234 survives without a float ever being involved.
     */
    public function unitPriceMills(): int
    {
        return Money::toMills((string) $this->getRawOriginal('unit_price'));
    }

    /**
     * 依單價重算應付金額（整數台幣）。
     *
     * ⛔ 前端送來的任何價格欄位一律忽略；⛔ 全程整數運算，不使用 binary float。
     */
    public function amountFor(int $quantity): int
    {
        return Money::total($this->unitPriceMills(), $quantity);
    }
}
