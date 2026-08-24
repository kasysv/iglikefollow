<?php

namespace App\Services\Integrations;

use App\Enums\IntegrationEnvironment;
use App\Enums\IntegrationProvider;
use App\Models\IntegrationSetting;

/**
 * One answer to "is this provider live right now", for every caller.
 *
 * Owner 的決定改變了這個系統的形狀：實際網站只有一套正式營運設定，而「開或
 * 關」是 Owner 在後台的事，不是改 `.env`、改 code 或發一次版。所以營運事實
 * 的唯一來源就是 production `integration_settings.is_enabled`。
 *
 * ⛔ 這個類別刻意同時回答兩個問題，因為把它們分開就是讓其中一個被忘記：
 *
 *   - `setting()` —— 「可以真的送出請求嗎」。要求 row 存在、`is_enabled`、
 *     identifier 與所有 secret 齊全，而且環境允許外呼。
 *   - `availableToCustomer()` —— 「結帳頁可以顯示這個付款方式嗎」。
 *
 * 兩者用同一份判斷，⛔ 不容許前端顯示一個後端會拒絕的選項——那是客人填完
 * 整張表單、按下付款、才看到「無法使用」的來源。
 *
 * ⛔ 環境邊界仍然存在,而且與 Owner 開關是不同的東西:
 *   - `local`／`testing` 永遠不得外呼,只能用既有 fake seam／`Http::fake()`;
 *   - `staging` 與 `production` 都可以使用正式 adapter。
 *
 * 這不是營運開關,是「這台機器可不可以連外網」的技術邊界。把它交給後台
 * 開關,等於讓本機測試有機會真的送出一筆付款。
 */
class LiveIntegration
{
    /**
     * 可以送出真實請求的環境。
     *
     * ⛔ `local`／`testing` 不在其中,而且不可由設定加入:測試套件必須永遠
     * 走 fake seam。⛔ `staging` 在其中是 Owner 的明確要求——目前的 HTTPS
     * 網站就是 staging,而它必須能實際收款。
     */
    private const OUTBOUND_ENVIRONMENTS = ['staging', 'production'];

    /**
     * 這台機器可不可以對外送出交易請求。
     *
     * ⛔ 與 Owner 的後台開關完全分開:開關管「要不要收款」,這裡管「這個
     * 環境有沒有資格連外」。兩者都必須成立。
     */
    public static function outboundAllowed(): bool
    {
        return app()->environment(self::OUTBOUND_ENVIRONMENTS);
    }

    /**
     * The one credential row this site uses for a provider.
     *
     * ⛔ 固定 production,不看 `app()->environment()`。sandbox row 就算存在
     * 也絕不會被讀到:Owner 不再區分測試／正式,所以「跟著環境選 row」只會
     * 在某天變成用測試金鑰去收真錢,或反過來。
     */
    public static function row(IntegrationProvider $provider): ?IntegrationSetting
    {
        return IntegrationSetting::query()
            ->where('provider', $provider)
            ->where('environment', IntegrationEnvironment::Production)
            ->first();
    }

    /**
     * Owner 是否已開啟這個通道,且 credential 齊全。
     *
     * ⛔ 不看 `PAYMENTS_SANDBOX_ENABLED`、`INVOICE_SANDBOX_ENABLED`、
     * `INVOICE_GATEWAY` 或 `integrations.enablable.*`。那些是舊 sandbox 時代
     * 的旗標,現在一律 deprecated／ignored:留著它們能阻擋 Owner 的 DB 開關,
     * 就等於 Owner 在後台按了開關卻沒有反應,然後有人回來改 `.env`。
     */
    public static function enabledByOwner(IntegrationProvider $provider): bool
    {
        return self::row($provider)?->isUsable() ?? false;
    }

    /**
     * 現在可以真的對這個 provider 送出請求的設定;否則 null。
     *
     * ⛔ fail closed:回 null 的呼叫端必須誠實拒絕,不得退回 Fake、不得假裝
     * 成功、不得先建單再回錯誤。
     */
    public static function setting(IntegrationProvider $provider): ?IntegrationSetting
    {
        if (! self::outboundAllowed()) {
            return null;
        }

        $row = self::row($provider);

        return $row?->isUsable() ? $row : null;
    }

    /**
     * 結帳頁是否可以顯示這個付款方式。
     *
     * ⛔ 與 `setting()` 同一份判斷,不是近似判斷。前端與後端用不同條件,就會
     * 出現「畫面上可以選、送出後被拒」——客人已經填完整張表單了。
     */
    public static function availableToCustomer(IntegrationProvider $provider): bool
    {
        return self::setting($provider) !== null;
    }

    /**
     * 缺少哪些必填欄位,供後台在拒絕開啟時明確說明。
     *
     * ⛔ 只回欄位名稱,永遠不回值。
     *
     * @return list<string>
     */
    public static function missingFields(IntegrationProvider $provider): array
    {
        $row = self::row($provider);
        $missing = [];

        if ($provider->identifierLabel() !== null && blank($row?->identifier)) {
            $missing[] = $provider->identifierLabel();
        }

        foreach ($provider->secretKeys() as $key) {
            if (! ($row?->hasSecret($key) ?? false)) {
                $missing[] = $provider->secretLabel($key);
            }
        }

        return $missing;
    }
}
