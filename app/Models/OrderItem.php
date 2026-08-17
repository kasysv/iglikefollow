<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * What was bought, frozen at the moment of purchase.
 *
 * Every display field is a copy. Reading the name or price back through
 * serviceVariant() would show today's catalogue, not what the customer agreed
 * to, so the relation exists only to point fulfilment at the right product.
 */
class OrderItem extends Model
{
    use HasFactory;

    protected $guarded = [];

    /*
     * 交付對象是客人的 IG 帳號或貼文網址，屬個資，⛔ 不以明文保存。
     * 商品名稱、SKU 與金額不加密：後台需要能搜尋與對帳。
     */
    protected $casts = [
        'target_value' => 'encrypted',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function serviceVariant(): BelongsTo
    {
        return $this->belongsTo(ServiceVariant::class);
    }

    /** 這個商品項目的履約紀錄；⛔ 最多一筆，由 unique index 保證。 */
    public function fulfillmentOrder(): HasOne
    {
        return $this->hasOne(FulfillmentOrder::class);
    }

    /**
     * 顯示用單價字串，例如 "0.1234"。
     *
     * ⛔ 回傳字串而非 float：這是要顯示給人看與對帳的金額，
     * 轉成 float 只會再次引入精度問題。
     */
    public function unitPrice(): string
    {
        return Money::format((int) $this->unit_price_mills);
    }
}
