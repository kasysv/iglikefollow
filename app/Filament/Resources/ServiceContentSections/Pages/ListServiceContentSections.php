<?php

namespace App\Filament\Resources\ServiceContentSections\Pages;

use App\Filament\Resources\ServiceContentSections\ServiceContentSectionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListServiceContentSections extends ListRecords
{
    protected static string $resource = ServiceContentSectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
