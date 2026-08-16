<?php

namespace App\Enums;

/**
 * The lifecycle of an IGLIKEFOLLOW order.
 *
 * The order exists from the moment checkout validates — before any payment is
 * attempted — so a failed, abandoned or retried payment still has a local
 * record to attach itself to.
 */
enum OrderStatus: string
{
    case PendingPayment = 'pending_payment';
    case Paid = 'paid';
    case Canceled = 'canceled';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::PendingPayment => '待付款',
            self::Paid => '已付款',
            self::Canceled => '已取消',
            self::Expired => '已逾期',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::PendingPayment => 'warning',
            self::Paid => 'success',
            self::Canceled, self::Expired => 'gray',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $c) => [$c->value => $c->label()])
            ->all();
    }
}
