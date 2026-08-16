<?php

namespace App\Services\Payments;

/**
 * One answer to "may sandbox payment behaviour run at all".
 *
 * The registry asks this before handing out an adapter, but the registry is not
 * the only way in: the ECPay callback is a public route, and both gateways and
 * the LINE client can be resolved from the container directly. A guard that
 * only covers the ordinary controller path is a guard around the front door of
 * a building with several.
 *
 * ⛔ Production is refused regardless of the flag. This milestone covers
 * sandbox only, so flipping one config value must not be enough to start
 * settling real money — that needs a separate, deliberate approval.
 */
class SandboxGuard
{
    public static function enabled(): bool
    {
        // ⛔ production 硬性拒絕，不看 feature flag。
        if (app()->environment('production')) {
            return false;
        }

        return (bool) config('integrations.payments.sandbox_enabled', false);
    }
}
