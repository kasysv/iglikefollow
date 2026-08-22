<?php

namespace App\Filament\Resources\AdminAuditLogs;

use App\Filament\Resources\AdminAuditLogs\Pages\ListAdminAuditLogs;
use App\Filament\Resources\AdminAuditLogs\Tables\AdminAuditLogsTable;
use App\Models\AdminAuditLog;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/** Owner 唯讀稽核紀錄；⛔ 後台不提供建立、編輯或刪除。 */
class AdminAuditLogResource extends Resource
{
    protected static ?string $model = AdminAuditLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $navigationLabel = '操作紀錄';

    protected static string|UnitEnum|null $navigationGroup = '系統管理';

    protected static ?string $modelLabel = '操作紀錄';

    protected static ?string $pluralModelLabel = '操作紀錄';

    protected static ?int $navigationSort = 2;

    public static function table(Table $table): Table
    {
        return AdminAuditLogsTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    /*
     * M2-E-B:只從側邊導航隱藏,⛔ route、Resource、Model、policy 與資料
     * 全部保留——這是日常畫面精簡,不是功能下架;Owner 直接輸入網址仍可
     * 進入(授權與 noindex 照舊),因此隨時可回滾。
     */
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAdminAuditLogs::route('/'),
        ];
    }
}
