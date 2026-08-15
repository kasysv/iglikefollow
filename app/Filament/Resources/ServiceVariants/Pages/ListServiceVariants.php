<?php

namespace App\Filament\Resources\ServiceVariants\Pages;

use App\Filament\Resources\ServiceVariants\ServiceVariantResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListServiceVariants extends ListRecords
{
    protected static string $resource = ServiceVariantResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
