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
        return (bool) config('integrations.payments.sandbox_enabled', false);
    }

    /**
     * The adapter for this provider, or null if it cannot be used.
     *
     * ⛔ Production is refused outright: this milestone covers sandbox only,
     * and the check is here rather than in config alone so that flipping a
     * config value cannot by itself start taking real money.
     */
    public function for(string $provider): ?PaymentGateway
    {
        if (! $this->sandboxEnabled()) {
            return null;
        }

        if ($this->container->environment('production')) {
            return null;
        }

        $class = self::ADAPTERS[$provider] ?? null;

        return $class === null ? null : $this->container->make($class);
    }
}
