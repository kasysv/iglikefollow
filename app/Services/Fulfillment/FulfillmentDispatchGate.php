<?php

namespace App\Services\Fulfillment;

use App\Enums\IntegrationProvider;
use App\Services\Integrations\LiveIntegration;
use App\Services\Integrations\ProviderEndpoints;

/**
 * One answer to "may we send anything to a provider at all".
 *
 * Checked in one place rather than scattered across the job, the gateway and
 * the action — a gate with several copies is a gate with several chances to be
 * forgotten. ⛔ Container binding、後台顯示、商品方案三態、readiness 與
 * queue jobs 全部讀這一個方法;沒有第二份會漂移的判斷。
 *
 * ⛔ R1:唯一的營運開關是 Owner 後台的「自動派單總開關」——TheMostPanel
 * production `integration_settings.is_enabled`。`FULFILLMENT_DISPATCH_ENABLED`
 * 與 staging 專用旗標已 deprecated/ignored:留著一個能否決 Owner 的旗標,
 * 結果就是 Owner 按了開關卻沒有反應,然後有人回來改 `.env`。
 *
 * ⛔ 總開關與商品 mapping 的 `is_enabled` 仍是兩件事:mapping 說「這個對應
 * 正確」,總開關說「可以真的送出去」,兩者同時成立才派那一筆新訂單。
 * 開啟總開關不會改寫任何 mapping,也不會補派任何歷史訂單。
 */
class FulfillmentDispatchGate
{
    /**
     * ⛔ 三類條件必須同時成立才可能派單。
     *
     * 少任何一個,履約列就停在 configuration_pending,而不是排進一個注定
     * 不該執行的工作。
     */
    public static function enabled(): bool
    {
        /*
         * 1. Owner 的總開關(含 API Key 完整度)。
         *
         * ⛔ 這是唯一的營運開關,所有環境都先問它:本機測試也用同一條規則,
         * 因為一條只有測試走的旁路,就是一條沒人測到的正式路徑。
         */
        if (! LiveIntegration::enabledByOwner(IntegrationProvider::TheMostPanel)) {
            return false;
        }

        /*
         * 2. staging／production:live 路徑還要端點與 runtime 能力成立。
         *
         * ⛔ production 不再無條件拒絕——那讓正式站永遠不能自動派單,而 Owner
         * 已明確要求由後台開關決定。拒絕的依據換成上面的總開關與這裡的
         * 技術條件,不是環境名稱。
         */
        if (app()->environment('staging', 'production')) {
            return self::liveCapable();
        }

        /*
         * 3. local／testing:只有測試 stub 路徑,且必須與 container binding
         *    逐格一致——gate 說可以、binding 卻給 Disabled,列會在 ready 與
         *    blocked 之間空轉。
         *
         * ⛔ driver 在這裡只是「測試要走哪個 stub」的選擇器:local 只有 fake
         * gateway;testing 另可用注入式 fake transport 的 adapter e2e。
         * 這兩條路都不可能產生真實外呼——container 在 local／testing 永不
         * 交出 live-capable gateway,adapter 也把 local 擋在網路前。
         */
        return match (app()->environment()) {
            'testing' => in_array(config('fulfillment.driver'), ['fake', 'themostpanel'], true),
            'local' => config('fulfillment.driver') === 'fake',
            default => false,
        };
    }

    /**
     * 這台機器有沒有安全送出真實請求的技術條件。
     *
     * ⛔ 與 Owner 開關分開回答,因為修法完全不同:開關關著要 Owner 去開;
     * 端點不符是部署要修的問題;runtime 能力不足(libcurl < 8.4)要升級主機
     * ——後台把它顯示成「主機環境不支援」,而不是一個 Owner 按不動的按鈕。
     */
    public static function liveCapable(): bool
    {
        if (ProviderEndpoints::theMostPanelDispatch() === null) {
            return false;
        }

        /*
         * ⛔ 由 container 解析,不直接 fromRuntime():預設綁定就是真實
         * runtime,但測試必須能描述一個支援/不支援的環境,而不是被跑測試
         * 那台機器的 libcurl 版本決定測試結果。
         */
        return app(TheMostPanelCurlCapability::class)->supportsOngoingTransferCap();
    }
}
