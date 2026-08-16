<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGateway;
use Illuminate\Contracts\Container\Container;

/**
 * Which adapter handles which provider, and whether it may run at all.
 *
 * The sandbox feature flag lives here rather than in a controller so that
 * "payments are switched off" is answered in one place. ⛔ When it is off, or
 * when a provider has no usable credentials, this returns null and the caller
 * fails closed — it never falls back to the Fake gateway, because a checkout
 * that silently pretends to work is worse than one that plainly refuses.
 */
class PaymentGatewayRegistry
{
    /** @var array<string, class-string<PaymentGateway>> */
    private const ADAPTERS = [
        'ecpay' => EcpayPaymentGateway::class,
        'line-pay' => LinePayGateway::class,
    ];

    public function __construct(private readonly Container $container) {}

    /** 本輪的 sandbox 付款預設關閉，需明確開啟。 */
    public function sandboxEnabled(): bool
    {
        return SandboxGuard::enabled();
    }

    /**
     * The adapter for this provider, or null if it cannot be used.
     *
     * ⛔ This is one of several entry points, not the only one — the adapters
     * and the callback each enforce the same guard themselves, because a public
     * callback route never passes through here.
     */
    public function for(string $provider): ?PaymentGateway
    {
        if (! SandboxGuard::enabled()) {
            return null;
        }

        $class = self::ADAPTERS[$provider] ?? null;

        return $class === null ? null : $this->container->make($class);
    }
}
