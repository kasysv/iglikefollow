<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of an order's timeline, and the outbox seam for fulfilment.
 *
 * TYPE_ORDER_PAID rows carry a unique_key so the database guarantees exactly
 * one per order — that is what M4A will consume to dispatch an SMM job, and
 * what stops a repeated payment callback from queueing a second one.
 */
class OrderEvent extends Model
{
    use HasFactory;

    public const TYPE_ORDER_CREATED = 'order_created';

    public const TYPE_ORDER_PAID = 'order_paid';

    public const TYPE_PAYMENT_FAILED = 'payment_failed';

    public const TYPE_PAYMENT_CANCELED = 'payment_canceled';

    public const TYPE_PAYMENT_EXPIRED = 'payment_expired';

    protected $guarded = [];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function label(): string
    {
        return match ($this->type) {
            self::TYPE_ORDER_CREATED => '建立訂單',
            self::TYPE_ORDER_PAID => '付款成功',
            self::TYPE_PAYMENT_FAILED => '付款失敗',
            self::TYPE_PAYMENT_CANCELED => '取消付款',
            self::TYPE_PAYMENT_EXPIRED => '付款逾期',
            default => $this->type,
        };
    }
}
