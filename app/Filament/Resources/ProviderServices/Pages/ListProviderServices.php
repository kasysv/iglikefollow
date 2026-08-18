<?php

namespace App\Filament\Resources\ProviderServices\Pages;

use App\Filament\Resources\ProviderServices\ProviderServiceResource;
use App\Models\ProviderService;
use Filament\Resources\Pages\ListRecords;

class ListProviderServices extends ListRecords
{
    protected static string $resource = ProviderServiceResource::class;

    /** ⛔ 沒有新增、同步或連線測試按鈕：目錄只能由完整 snapshot 寫入。 */
    protected function getHeaderActions(): array
    {
        return [];
    }

    /*
     * ⛔ rate 警語必須常駐：rate 是供應商原始值不是本站售價——沒有這句，
     * 這頁看起來像定價表。第二句依事實而變：B4-C-C-B 之後本機已觀察過
     * 真實 catalog，再寫死「尚未同步」就是錯誤陳述；有資料時只陳述安全
     * 事實（row count 與最後觀察時間），不下任何商業判斷。
     */
    public function getSubheading(): ?string
    {
        $warning = 'rate 是供應商原始值，幣別／計費單位未驗證，不是本站售價。';

        $observed = ProviderService::query()->count();

        if ($observed === 0) {
            // ⛔ 沒資料的正確解讀仍是「尚未同步」，不是「帳戶沒有服務」。
            return $warning.'本機尚未同步供應商服務目錄；沒有資料代表尚未同步，不代表帳戶沒有服務。';
        }

        $lastSeen = ProviderService::query()->max('last_seen_at');

        if ($lastSeen === null) {
            /*
             * ⛔ Schema 允許 last_seen_at 為 null(R1):有 rows 但沒有觀察
             * 時間,不得宣稱「最近成功觀察」或顯示空括號——那會把來源不明
             * 的資料偽裝成同步證據。
             */
            return $warning
                .'本機已有 '.$observed.' 筆服務資料，但未記錄觀察時間；不能視為最近同步的證據。';
        }

        return $warning
            .'本機最近成功觀察 '.$observed.' 筆服務（最後觀察 '.(string) $lastSeen.'）。';
    }
}
