<?php

namespace App\Filament\Resources\FulfillmentMappings\Pages;

use App\Filament\Resources\FulfillmentMappings\FulfillmentMappingResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFulfillmentMappings extends ListRecords
{
    protected static string $resource = FulfillmentMappingResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('新增對應')];
    }
}
