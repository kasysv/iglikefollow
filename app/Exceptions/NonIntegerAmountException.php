<?php

namespace App\Exceptions;

use App\Support\Money;
use DomainException;

/**
 * A unit rate and quantity that do not multiply out to whole NT dollars.
 *
 * This is a product configuration fault, not a customer mistake: it means some
 * quantity the catalogue offers cannot be charged in TWD. It is thrown instead
 * of rounded so that the bad configuration surfaces at save time or checkout
 * rather than becoming an order for an amount nobody agreed to.
 */
class NonIntegerAmountException extends DomainException
{
    public function __construct(
        public readonly int $unitPriceMills,
        public readonly int $quantity,
        public readonly int $totalMills,
    ) {
        parent::__construct(sprintf(
            '單價 %s × 數量 %s = %s 元，不是整數新台幣，無法收款。請調整單價或數量間隔。',
            Money::format($unitPriceMills),
            number_format($quantity),
            Money::format($totalMills),
        ));
    }
}
