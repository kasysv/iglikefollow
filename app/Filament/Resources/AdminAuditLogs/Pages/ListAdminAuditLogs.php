<?php

namespace App\Filament\Resources\AdminAuditLogs\Pages;

use App\Filament\Resources\AdminAuditLogs\AdminAuditLogResource;
use Filament\Resources\Pages\ListRecords;

class ListAdminAuditLogs extends ListRecords
{
    protected static string $resource = AdminAuditLogResource::class;

    /** ⛔ 稽核紀錄唯讀：不提供任何建立動作。 */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
