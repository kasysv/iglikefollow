<?php

namespace App\Services\Fulfillment;

/**
 * One answer to "may we send anything to a provider at all".
 *
 * Checked in one place rather than scattered across the job, the gateway and
 * the action — a gate with several copies is a gate with several chances to be
 * forgotten.
 *
 * ⛔ Production is refused whatever the config says. M4A has no verified
 * service ids, no target transformation rules and no proven status contract, so
 * no environment variable alone may start placing real orders that cost real
 * money. That needs its own approval, at M4C.
 */
class FulfillmentDispatchGate
{
    /**
     * ⛔ 三個條件必須同時成立才可能派單。
     *
     * 少任何一個，履約列就停在 configuration_pending，而不是排進一個注定
     * 不該執行的工作。
     */
    public static function enabled(): bool
    {
        if (app()->environment('production')) {
            return false;
        }

        if (! (bool) config('fulfillment.dispatch_enabled', false)) {
            return false;
        }

        /*
         * ⛔ disabled driver 代表沒有任何可用的送出實作。
         *
         * `themostpanel` 只在 testing 環境成立:DISPATCH-ADAPTER-A 的
         * adapter 走注入式 fake transport 跑端到端,⛔ 不存在任何可由
         * `.env` 在 local／production 開啟的 live driver 路徑。
         */
        return match (config('fulfillment.driver')) {
            'fake' => true,
            'themostpanel' => app()->environment('testing'),
            default => false,
        };
    }
}
