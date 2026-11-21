<?php

namespace App\Filament\Resources\Services\RelationManagers\Actions;

use App\Models\ServiceVariant;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * 商品頁「服務項目」表格的一鍵停售／一鍵開賣。
 *
 * ⭐ 作用在**服務項目（款式）**層級，⛔ 不動商品頁本身：商品頁維持已發布，
 * URL、canonical、sitemap 都不變；某一頁的項目全部停售時，前台顯示既有的
 * 「方案準備中」。整頁下架會讓已收錄的頁面 404，Owner 已明確選擇不那樣做。
 *
 * ⛔ 只做兩種轉換，其餘一律略過並回報：
 *
 *  - 停售：已發布 → 已下架
 *  - 開賣：已下架 → 已發布
 *
 * ⛔ 草稿**不會**被一鍵開賣。草稿可能是還沒設定完成的新項目，批次勾選時
 * 很容易一起被選到；草稿請在編輯畫面個別發布。
 *
 * ⛔ 只有 Owner 看得到、也只有 Owner 能執行。這裡的 visible() 只是介面；
 * 真正的防線是既有的 `PublishObserver`——非 Owner 的狀態變更會在 model 層被
 * 還原，所以就算有人手造 Livewire payload 也改不了。
 *
 * ⭐ 每一筆各自儲存：重新開賣會跑 `VariantIntegrityObserver` 的可售檢查，
 * 某一筆資料不完整時只有那一筆失敗，其他照常完成，失敗的項目名稱會列出來。
 * 每次變更都由既有的 `AuditObserver` 留下稽核紀錄。
 */
final class VariantSalesBulkActions
{
    public static function stop(): BulkAction
    {
        return BulkAction::make('stopSelling')
            ->label('一鍵停售')
            ->icon('heroicon-o-pause-circle')
            ->color('danger')
            ->visible(fn (): bool => self::allowed())
            ->requiresConfirmation()
            ->modalHeading('停售所選服務項目')
            ->modalDescription('已發布的項目會改為「已下架」，客人將看不到、也買不到。商品頁本身維持上架。草稿與已下架的項目不會變動。')
            ->modalSubmitActionLabel('確認停售')
            ->action(fn (Collection $records) => self::apply($records, from: 'published', to: 'archived'))
            ->deselectRecordsAfterCompletion();
    }

    public static function resume(): BulkAction
    {
        return BulkAction::make('resumeSelling')
            ->label('一鍵開賣')
            ->icon('heroicon-o-play-circle')
            ->color('success')
            ->visible(fn (): bool => self::allowed())
            ->requiresConfirmation()
            ->modalHeading('恢復開賣所選服務項目')
            ->modalDescription('已下架的項目會改回「已發布」，客人可以再次購買。草稿不會被發布，請在編輯畫面個別發布。')
            ->modalSubmitActionLabel('確認開賣')
            ->action(fn (Collection $records) => self::apply($records, from: 'archived', to: 'published'))
            ->deselectRecordsAfterCompletion();
    }

    public static function allowed(): bool
    {
        return Auth::user()?->isOwner() === true;
    }

    /**
     * @param  Collection<int, ServiceVariant>  $records
     */
    private static function apply(Collection $records, string $from, string $to): void
    {
        // ⛔ 執行時再檢查一次，⛔ 不只靠按鈕是否顯示。
        abort_unless(self::allowed(), 403);

        $changed = 0;
        $skipped = 0;
        $failed = [];

        foreach ($records as $variant) {
            if ($variant->status !== $from) {
                $skipped++;

                continue;
            }

            try {
                $variant->status = $to;
                $variant->save();
                $changed++;
            } catch (ValidationException $e) {
                // 這一筆不可售：保持原狀，⛔ 不影響其他筆。
                $variant->status = $from;
                $failed[] = $variant->label.'：'.collect($e->errors())->flatten()->first();
            }
        }

        $action = $to === 'archived' ? '停售' : '開賣';

        $lines = ["已{$action} {$changed} 筆。"];

        if ($skipped > 0) {
            $lines[] = $to === 'archived'
                ? "{$skipped} 筆不是「已發布」，未變動。"
                : "{$skipped} 筆不是「已下架」（例如草稿），未變動。";
        }

        if ($failed !== []) {
            $lines[] = count($failed).' 筆無法開賣：'.implode('；', $failed);
        }

        Notification::make()
            ->title("一鍵{$action}完成")
            ->body(implode("\n", $lines))
            ->{$failed === [] ? 'success' : 'warning'}()
            ->send();
    }
}
