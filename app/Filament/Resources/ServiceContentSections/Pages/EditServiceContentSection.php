<?php

namespace App\Filament\Resources\ServiceContentSections\Pages;

use App\Filament\Resources\ServiceContentSections\ServiceContentSectionResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditServiceContentSection extends EditRecord
{
    protected static string $resource = ServiceContentSectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
