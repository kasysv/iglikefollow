<?php

namespace App\Filament\Resources\FulfillmentMappings\Pages;

use App\Filament\Resources\FulfillmentMappings\FulfillmentMappingResource;
use Filament\Resources\Pages\EditRecord;

class EditFulfillmentMapping extends EditRecord
{
    protected static string $resource = FulfillmentMappingResource::class;

    /** ⛔ 不提供刪除：既有履約紀錄需要這筆對應才能解釋自己送去了哪裡。 */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
