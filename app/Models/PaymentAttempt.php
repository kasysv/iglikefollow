<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

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
     * A fresh, unique payment reference: `PAY` + 12 顆大寫英數字,共 15 字。
     *
     * ⛔ 純英數字,沒有連字號。這個值會原樣成為綠界的 `MerchantTradeNo`,
     * 而 AioCheckOut V5 的規格是 String(20)、唯一、只准英數字——staging 實測
     * 舊的 `PAY-XXXXXXXXXXXX` 就是被 `10200031 MerchantTradeNo Must be
     * Number or English Letter` 拒絕的。隨機熵維持原本的 12 碼。
     */
    public static function newReference(): string
    {
        return 'PAY'.strtoupper(Str::random(12));
    }

    public function isSuccessful(): bool
    {
        return $this->status === PaymentStatus::Succeeded;
    }
}
