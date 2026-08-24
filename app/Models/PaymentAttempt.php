<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentAttempt extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'status' => PaymentStatus::class,
        'completed_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * A fresh payment reference: `IGNF` + 15 顆隨機數字,固定 19 字。
     *
     * Owner 指定的格式。⛔ 每一條規則都有理由:
     *
     *  - **純英數字**:這個值原樣成為綠界的 `MerchantTradeNo`,AioCheckOut V5
     *    只准 String(20) 英數字——staging 實測連字號被 `10200031` 拒絕。
     *  - **19 字,不做滿 20**:保留 1 字空間。
     *  - **15 位數字固定長度、保留前導 0**:這是字串,不是會進位變長的數值;
     *    `random_int()` 逐位產生(密碼學安全),⛔ 不用可猜的流水號、時間戳
     *    或截斷的自增 ID——付款編號可被猜中,就是可被撞單。
     *  - 10^15 的空間讓碰撞機率可忽略;DB 的 unique constraint 仍是最終防線。
     */
    public static function newReference(): string
    {
        $digits = '';

        for ($i = 0; $i < 15; $i++) {
            $digits .= (string) random_int(0, 9);
        }

        return 'IGNF'.$digits;
    }

    public function isSuccessful(): bool
    {
        return $this->status === PaymentStatus::Succeeded;
    }
}
