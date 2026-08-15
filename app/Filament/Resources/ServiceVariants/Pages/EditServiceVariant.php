<?php

namespace App\Filament\Resources\ServiceVariants\Pages;

use App\Filament\Resources\ServiceVariants\ServiceVariantResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditServiceVariant extends EditRecord
{
    protected static string $resource = ServiceVariantResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
