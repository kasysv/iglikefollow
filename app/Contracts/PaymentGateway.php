<?php

namespace App\Contracts;

use App\DTO\PaymentInitiation;
use App\Models\PaymentAttempt;

/**
 * Whatever takes the customer from "order created" to "paying".
 *
 * Provider-neutral on purpose: the controller decides *that* a payment starts,
 * never *how* one is signed. ECPay wants a browser form POST with a MAC, LINE
 * Pay wants a signed JSON call returning a redirect URL — both reduce to "send
 * the customer here", and the difference stays inside the adapter.
 *
 * ⛔ An implementation receives the attempt, not a request. Everything it needs
 * — amount, currency, reference — is already server-side and verified; nothing
 * a browser submitted reaches this layer.
 */
interface PaymentGateway
{
    /** The provider key this adapter serves, e.g. `ecpay`. */
    public function provider(): string;

    /**
     * Begin a payment for this attempt.
     *
     * ⛔ Must not mark anything paid. Starting a payment and completing one are
     * different events, and only a verified server-to-server result may do the
     * second.
     */
    public function initiate(PaymentAttempt $attempt): PaymentInitiation;
}
