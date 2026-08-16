<?php

namespace App\Exceptions;

use DomainException;

/**
 * A price or quantity that cannot be sold at all.
 *
 * Zero and negative rates, non-positive quantities, and products large enough
 * to overflow integer arithmetic all land here. Like NonIntegerAmountException
 * this is a configuration fault rather than a customer mistake, and it is
 * raised instead of being clamped so the bad value cannot reach an order.
 *
 * ⛔ Messages carry only prices and quantities — never customer data or secrets.
 */
class UnsellablePriceException extends DomainException {}
