<?php

namespace App\Enums;

/**
 * How a mapping turns an order item into a provider request.
 *
 * ⛔ Only one case exists, deliberately. The provider's public examples also
 * mention comments, subscriptions and drip feed, but we have no verified
 * product data for any of them — adding the enum cases now would let an Owner
 * select a mode nothing implements, which reads as support that is not there.
 * They arrive when there is something real behind them.
 */
enum FulfillmentPayloadType: string
{
    /** 目標網址／帳號 ＋ 數量。 */
    case LinkQuantity = 'link_quantity';

    public function label(): string
    {
        return match ($this) {
            self::LinkQuantity => '連結＋數量',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
