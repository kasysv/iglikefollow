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

    /**
     * 數量邊界檢查；⛔ 伺服器端唯一真實來源，不信任前端送出的值。
     *
     * 也包含「這個數量算得出整數台幣」——設定不合規時，⛔ 寧可讓客人買不到，
     * 也不能靜默四捨五入成一個沒有公告過的金額。
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
            && $quantity % $this->step_quantity === 0
            && Money::divides($rate, $quantity)
            && Money::isPayable($rate, $quantity);
    }

    /**
     * The smallest quantity a customer can actually buy, if any.
     *
     * ⛔ Not simply min_quantity: the purchase rule is `quantity % step === 0`,
     * so when the minimum is not itself a multiple of the step — min 101 with
     * step 100 — the minimum is not purchasable and the first real candidate is
     * 200. Validating from the minimum would check quantities nobody can buy.
     *
     * Returns null when the range contains no multiple of the step at all,
     * which means the variant cannot be bought at any quantity.
     */
    public function firstPurchasableQuantity(): ?int
    {
        $min = (int) $this->min_quantity;
        $max = (int) $this->max_quantity;
        $step = max(1, (int) $this->step_quantity);

        // >= min 的第一個 step 倍數。
        $first = (int) (ceil($min / $step) * $step);

        return $first <= $max ? $first : null;
    }

    /**
     * The first purchasable quantity whose amount is not whole NT dollars.
     *
     * Checking every step up to max would be unbounded work for a large range,
     * but it is not necessary: rate × quantity repeats its remainder against
     * SCALE on a cycle of at most SCALE / gcd(rate × step, SCALE) steps, so a
     * failure — if one exists at all — always appears within that many steps of
     * the first purchasable quantity.
     */
    public function firstNonIntegerQuantity(): ?int
    {
        $rate = $this->pendingUnitPriceMills();

        // 還沒有價格或格式有誤就沒有東西可以檢查；由欄位驗證負責報錯。
        // ⛔ 負數與零由 sellingPriceProblem() 處理，不在這裡靜默放行。
        if ($rate === null || $rate <= 0) {
            return null;
        }

        $start = $this->firstPurchasableQuantity();

        // 整段範圍都沒有合法數量，那不是「某個數量算不出整數」的問題。
        if ($start === null) {
            return null;
        }

        $max = (int) $this->max_quantity;
        $step = max(1, (int) $this->step_quantity);

        // 週期上限：超過這個步數後的餘數必定重複，⛔ 不需要掃到 max。
        $period = intdiv(Money::SCALE, self::gcd($rate * $step, Money::SCALE));

        for ($i = 0, $q = $start; $q <= $max && $i < $period; $i++, $q += $step) {
            if (! Money::divides($rate, $q)) {
                return $q;
            }
        }

        return null;
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

    private static function gcd(int $a, int $b): int
    {
        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }

        return max(1, $a);
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
