<?php

namespace App\Filament\Pages;

use App\Console\Commands\StagingReadinessCommand;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * Owner-only, read-only staging readiness — the CLI report on a page.
 *
 * ⛔ Pure status. It renders the exact same report as
 * `app:staging-readiness`(single source of truth): environment facts,
 * capability gates, credential PRESENCE(never decrypted), queue/scheduler
 * state. There is no enable button, no test-connection, no resend, no
 * manual mark-paid/issued/completed, no clear-failed-jobs — reading this
 * page changes nothing.
 */
class StagingReadiness extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?string $navigationLabel = '上線準備檢查';

    protected static string|UnitEnum|null $navigationGroup = '系統管理';

    protected static ?int $navigationSort = 3;

    protected static ?string $title = 'Staging Readiness(唯讀)';

    protected string $view = 'filament.pages.staging-readiness';

    /** ⛔ 只有 active Owner;credential presence 屬商業敏感狀態。 */
    public static function canAccess(): bool
    {
        return Auth::user()?->isOwner() ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    /** @return array{strict: bool, checks: list<array<string, string>>, blockers: int, blocked: int} */
    public function getReport(): array
    {
        return StagingReadinessCommand::report();
    }
}
