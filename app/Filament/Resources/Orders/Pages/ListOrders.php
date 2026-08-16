<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Filament\Resources\Orders\OrderResource;
use Filament\Resources\Pages\ListRecords;

class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;

    /** ⛔ 不提供任何建立訂單的入口。 */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
