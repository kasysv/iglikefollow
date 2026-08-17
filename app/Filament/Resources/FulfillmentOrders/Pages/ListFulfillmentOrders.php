<?php

namespace App\Filament\Resources\FulfillmentOrders\Pages;

use App\Filament\Resources\FulfillmentOrders\FulfillmentOrderResource;
use Filament\Resources\Pages\ListRecords;

class ListFulfillmentOrders extends ListRecords
{
    protected static string $resource = FulfillmentOrderResource::class;

    /** ⛔ 履約紀錄只能由已付款訂單自動產生，沒有手動建立的入口。 */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
