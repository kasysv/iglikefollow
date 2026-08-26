<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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

    /**
     * 這個商品項目的**所有**履約批次，依 sequence 排序。
     *
     * ⭐ Owner 可以在自行於 SMM PANEL 取消舊單後，於本站建立更換履約，
     * 因此一個商品項目可以有一條批次鏈：第 1 批（原始）→ 第 2 批 → …
     *
     * ⛔ 這裡由 `hasOne` 改成 `hasMany`。原本的「最多一筆」由 `order_item_id`
     * 單欄 unique 保證；現在改為 unique `(order_item_id, sequence_no)`——
     * **初始批次（sequence 1）仍然只可能有一筆**，那道防重複派單的最終防線
     * ⛔ 沒有被放寬。
     */
    public function fulfillmentOrders(): HasMany
    {
        return $this->hasMany(FulfillmentOrder::class)->orderBy('sequence_no');
    }

    /**
     * 第 1 批（原始履約）。
     *
     * ⛔ 用 `sequence_no = 1` 而不是「最舊的一筆」：sequence 是這條鏈的定義
     * 欄位，⛔ 而 id／created_at 只是碰巧同序。
     */
    public function initialFulfillmentOrder(): HasOne
    {
        return $this->hasOne(FulfillmentOrder::class)->where('sequence_no', 1);
    }

    /**
     * 目前生效的那一批（鏈尾）。
     *
     * ⭐ 這是「這個商品項目現在的履約狀態」的唯一來源：公開頁與後台摘要都讀它，
     * ⛔ 而不是隨便拿一筆。
     */
    public function latestFulfillmentOrder(): HasOne
    {
        return $this->hasOne(FulfillmentOrder::class)->ofMany('sequence_no', 'max');
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
