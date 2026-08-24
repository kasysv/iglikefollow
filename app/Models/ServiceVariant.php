<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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

    /** 這個款式的供應商對應；⛔ 每個 provider 最多一筆。 */
    public function fulfillmentMappings(): HasMany
    {
        return $this->hasMany(FulfillmentMapping::class);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published');
    }

    /**
     * 數量邊界檢查；⛔ 伺服器端唯一真實來源，不信任前端送出的值。
     *
     * ⛔ M3A:最低量與最高量之間的**任何正整數**都合法。`step_quantity`
     * 不再參與判斷——它正是 Owner 輸入 100 卻被瀏覽器導向 10／110 的來源。
     * legacy 欄位仍留在 DB,但不得再限制顧客。
     *
     * 仍然保留的是「收不收得到錢」:四捨五入後不足 1 元或會溢位的數量依舊
     * 買不到。⛔ 放寬倍數不等於放寬金額。
     */
    public function quantityIsValid(int $quantity): bool
    {
        $rate = $this->unitPriceMills();

        // ⛔ 免費或負價的服務項目不可販售：那不是折扣，是收不到錢或倒貼。
        if ($rate <= 0 || $quantity <= 0) {
            return false;
        }

        return $quantity >= $this->min_quantity
            && $quantity <= $this->max_quantity
            && Money::isPayable($rate, $quantity);
    }

    /**
     * The smallest quantity a customer can actually buy, if any.
     *
     * ⛔ M3A: with the step rule gone this is simply the minimum — every
     * integer in range is purchasable. It is kept as a named method rather than
     * inlined because provider-compatibility and the fulfilment card both ask
     * "what is the real lowest orderable quantity", and that question should
     * have exactly one answer.
     *
     * ⛔ Still clamped to at least 1: zero is never a purchasable quantity
     * (`quantityIsValid()` rejects it), so a legacy `min_quantity` of 0 starts
     * at 1, not 0. Returns null when the range itself is empty.
     */
    public function firstPurchasableQuantity(): ?int
    {
        $min = max(1, (int) $this->min_quantity);
        $max = (int) $this->max_quantity;

        return $min <= $max ? $min : null;
    }

    /**
     * The lowest quantity in range that cannot actually be charged, if any.
     *
     * ⛔ M3A: this used to find the first quantity producing a fractional TWD
     * total, and that was a blocking fault. Fractional totals are now rounded
     * half-up and are perfectly ordinary, so that question no longer decides
     * anything. What still matters is the one thing rounding cannot fix: a
     * total that rounds to less than NT$1, or one that overflows.
     *
     * Only the minimum needs testing. `rate × quantity` is monotonic in
     * quantity for a positive rate, so if the smallest orderable quantity
     * clears NT$1 every larger one does too — and overflow is checked at the
     * maximum, the only place it can first appear.
     */
    public function firstUnpayableQuantity(): ?int
    {
        $rate = $this->pendingUnitPriceMills();

        // 還沒有價格或格式有誤就沒有東西可以檢查；由欄位驗證負責報錯。
        // ⛔ 負數與零由 sellingPriceProblem() 處理，不在這裡靜默放行。
        if ($rate === null || $rate <= 0) {
            return null;
        }

        $start = $this->firstPurchasableQuantity();

        // 整段範圍都沒有合法數量，那是另一個問題(結構),不在這裡報。
        if ($start === null) {
            return null;
        }

        if (! Money::isPayable($rate, $start)) {
            return $start;
        }

        // 溢位只可能最先出現在最大值。
        $max = (int) $this->max_quantity;

        return Money::isPayable($rate, $max) ? null : $max;
    }

    /**
     * The unit price about to be saved, in mills, or null if unusable.
     *
     * ⛔ Reads the pending attribute rather than getRawOriginal(): these checks
     * run while saving, and what must be validated is the new price, not the
     * one already stored.
     */
    public function pendingUnitPriceMills(): ?int
    {
        $raw = trim((string) ($this->attributes['unit_price'] ?? ''));

        if ($raw === '' || ! preg_match('/^-?\d+(\.\d{1,4})?$/', $raw)) {
            return null;
        }

        return Money::toMills($raw);
    }

    /**
     * The unit price in mills (ten-thousandths of NT$), exactly as stored.
     *
     * Read from the raw column rather than the decimal cast, so a price like
     * 0.1234 survives without a float ever being involved.
     */
    public function unitPriceMills(): int
    {
        // ⛔ 讀 raw attribute，不經 decimal cast：cast 會把 0.1234 變成 float。
        return Money::toMills((string) ($this->attributes['unit_price'] ?? $this->getRawOriginal('unit_price')));
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
