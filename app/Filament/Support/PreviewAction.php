<?php

namespace App\Filament\Support;

use App\Models\Platform;
use App\Models\Service;
use Filament\Actions\Action;
use Illuminate\Database\Eloquent\Model;

/**
 * "在前台預覽" header action for Platform and Service edit screens.
 *
 * Opens the record's real front-end URL with ?preview=1 in a new tab, so an
 * editor can see a draft exactly where it will live once published. The
 * preview itself is gated in StorefrontController — guests get a 404 — and
 * always carries noindex, so this action only builds the link.
 */
class PreviewAction
{
    public static function make(): Action
    {
        return Action::make('preview')
            ->label('在前台預覽')
            ->icon('heroicon-o-eye')
            ->color('gray')
            ->url(fn (Model $record): string => self::url($record).'?preview=1')
            ->openUrlInNewTab()
            // 沒有網址代碼（或平台被刪掉）時無法組出網址，⛔ 不顯示會壞掉的連結。
            ->visible(fn (Model $record): bool => self::url($record) !== '');
    }

    private static function url(Model $record): string
    {
        if ($record instanceof Platform) {
            return filled($record->slug) ? route('platform', $record->slug) : '';
        }

        if ($record instanceof Service) {
            return filled($record->slug) && filled($record->platform?->slug)
                ? route('service', [$record->platform->slug, $record->slug])
                : '';
        }

        return '';
    }
}
