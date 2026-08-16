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
        return $quantity >= $this->min_quantity
            && $quantity <= $this->max_quantity
            && $quantity % $this->step_quantity === 0
            && Money::divides($this->unitPriceMills(), $quantity);
    }

    /**
     * The first purchasable quantity whose amount is not whole NT dollars.
     *
     * Checking every step from min to max would be unbounded work for a large
     * range, but it is not necessary: rate × quantity is a multiple of SCALE on
     * a cycle of at most SCALE / gcd(rate, SCALE) steps, so a failure — if one
     * exists at all — always appears within that many steps of the minimum.
     */
    public function firstNonIntegerQuantity(): ?int
    {
        // ⛔ 用「待儲存」的值而非 getRawOriginal()：這個檢查會在 saving 時執行，
        // 要擋的正是還沒寫進資料庫的新價格。
        $raw = trim((string) ($this->attributes['unit_price'] ?? ''));

        // 還沒有價格就沒有東西可以檢查；格式錯誤由欄位驗證負責報錯。
        if ($raw === '' || ! preg_match('/^-?\d+(\.\d{1,4})?$/', $raw)) {
            return null;
        }

        $rate = Money::toMills($raw);
        $min = (int) $this->min_quantity;
        $max = (int) $this->max_quantity;
        $step = max(1, (int) $this->step_quantity);

        if ($rate === 0) {
            return null;
        }

        // 週期上限：超過這個步數後的餘數必定重複，⛔ 不需要掃到 max。
        $period = intdiv(Money::SCALE, self::gcd(abs($rate * $step), Money::SCALE));

        for ($i = 0, $q = $min; $q <= $max && $i < $period; $i++, $q += $step) {
            if (! Money::divides($rate, $q)) {
                return $q;
            }
        }

        return null;
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
