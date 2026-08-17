<?php

namespace App\Filament\Resources\ProviderServices\Pages;

use App\Filament\Resources\ProviderServices\ProviderServiceResource;
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
     * ⛔ 兩句警語都必須常駐：rate 是供應商原始值不是本站售價，而
     * CATALOG-A 還沒同步過真實帳戶——沒有這兩句，這頁看起來像定價表。
     */
    public function getSubheading(): ?string
    {
        return 'rate 是供應商原始值，幣別／計費單位未驗證，不是本站售價。'
            .'CATALOG-A 為本機 foundation，尚未同步真實帳戶。';
    }
}
