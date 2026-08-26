<?php

namespace App\Filament\Resources\Orders\RelationManagers;

use App\Actions\Fulfillment\CreateFulfillmentReplacement;
use App\Enums\FulfillmentAttentionReason;
use App\Models\FulfillmentOrder;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Where this order stands with the supplier, on the order page itself.
 *
 * ⛔ 既有列一律唯讀，且刻意沒有 retry／cancel／標記完成的按鈕。客服看得到
 * 發生了什麼，但不能主張發生了什麼。
 *
 * ⭐ 唯一的寫入動作是「更換連結」：它**不改寫任何既有列**，而是建立新的一批。
 * 舊批次的 status、provider 原文與 Remains 完全不動，仍由既有排程繼續同步。
 */
class FulfillmentOrdersRelationManager extends RelationManager
{
    protected static string $relationship = 'fulfillmentOrders';

    protected static ?string $title = '履約紀錄';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                /*
                 * ⭐ 批次：第 1 次是原始履約，第 2 次以後是 Owner 建立的更換。
                 * ⛔ 沒有這一欄，多批次的列在畫面上會看起來像重複資料。
                 */
                TextColumn::make('sequence_no')
                    ->label('批次')
                    ->state(fn (FulfillmentOrder $record): string => '第 '.$record->sequence_no.' 次'),

                // ⛔ SMM 完整服務名稱：Owner／Editor 皆可見，服務代碼才是 Owner-only。
                TextColumn::make('smm_service_name')
                    ->label('SMM 服務名稱')
                    ->state(fn ($record) => $record->displayServiceName()),
                TextColumn::make('orderItem.service_name')->label('本站分類')->wrap(),

                /*
                 * ⭐ 改為**本批次實際**的連結與數量。
                 *
                 * ⛔ 原本這裡讀 `orderItem.quantity`——那是原訂購量，在多批次
                 * 之後會讓每一列都顯示同一個數字，看起來像每批都送了原始數量。
                 * 現在讀 `effectiveQuantity()`：第 1 批仍是訂單快照，更換批次
                 * 是 Owner 實際輸入的量。
                 */
                TextColumn::make('effective_target')
                    ->label('本批次連結／帳號')
                    ->state(fn (FulfillmentOrder $record): string => $record->effectiveTarget())
                    ->wrap()
                    // ⛔ 交付目標只給 Owner 看，與服務代碼同級。
                    ->visible(fn (): bool => Auth::user()?->isOwner() ?? false),

                TextColumn::make('effective_quantity')
                    ->label('本批次數量')
                    ->state(fn (FulfillmentOrder $record): int => $record->effectiveQuantity())
                    ->numeric(),

                /*
                 * ⭐ 顯示 provider 原文；badge 顏色仍由內部 enum 決定。
                 * ⛔ 顏色不由原文推導——那等於用未經狀態機驗證的文字控制呈現。
                 */
                TextColumn::make('provider_status')
                    ->label('SMM 狀態')
                    ->badge()
                    ->state(fn ($record): string => $record->displayProviderStatus())
                    ->color(fn ($record) => $record->status->color()),

                // ⭐ 起始值：與剩餘同規則（null＝尚未取得、0＝確實是 0）。
                TextColumn::make('provider_start_count')
                    ->label('起始值')
                    ->state(fn ($record): string => $record->displayStartCount()),

                TextColumn::make('provider_remains')
                    ->label('剩餘數量（Remains）')
                    ->state(fn ($record): string => $record->displayRemains()),

                TextColumn::make('attention_code')
                    ->label('待處理原因')
                    // ⛔ 本地 enum 訊息。
                    ->formatStateUsing(fn (?FulfillmentAttentionReason $state) => $state?->message())
                    ->wrap(),

                // ⛔ 服務代碼只有 Owner 看得到。
                TextColumn::make('provider_service_id_snapshot')
                    ->label('服務代碼')
                    ->visible(fn () => Auth::user()?->isOwner() ?? false),

                TextColumn::make('provider_order_id')->label('供應商單號')->placeholder('—'),
                TextColumn::make('submitted_at')->label('送出時間')->dateTime('Y-m-d H:i')->placeholder('—'),
            ])
            // ⛔ 依批次排序，讓鏈的順序在畫面上一目了然。
            ->defaultSort('sequence_no')
            // ⛔ 仍然沒有新增、編輯、刪除、重送或批次動作。
            ->headerActions([])
            ->recordActions([$this->replaceAction()])
            ->toolbarActions([]);
    }

    /**
     * Owner-only：建立一筆更換履約。
     *
     * ⛔ `visible()` 只是畫面上的收斂；真正的守衛在 policy 與 action 內，
     * 因為一份手寫的 Livewire payload 從來不經過畫面。
     */
    private function replaceAction(): Action
    {
        return Action::make('replaceFulfillment')
            ->label('更換連結')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('warning')
            ->visible(fn (FulfillmentOrder $record): bool => $this->canReplace($record))
            ->modalHeading('建立更換履約')
            ->modalSubmitActionLabel('確認建立')
            ->schema(fn (FulfillmentOrder $record): array => [
                /*
                 * ⛔ 唯讀欄位：只是讓 Owner 對照，⛔ 不參與任何限制。
                 */
                Placeholder::make('current_target')
                    ->label('目前連結／帳號')
                    ->content($record->effectiveTarget()),

                Placeholder::make('current_quantity')
                    ->label('目前批次數量')
                    ->content((string) $record->effectiveQuantity()),

                Placeholder::make('current_status')
                    ->label('最後保存的 SMM 狀態')
                    ->content($record->displayProviderStatus().'／剩餘：'.$record->displayRemains()),

                Placeholder::make('suggested_quantity')
                    ->label('建議數量')
                    ->content((string) $this->suggestedQuantity($record))
                    ->helperText('有 Remains 就用 Remains，否則用本批次送出的數量。這只是建議，可自行調整。'),

                TextInput::make('target')
                    ->label('新連結／帳號')
                    ->required()
                    ->maxLength(CreateFulfillmentReplacement::MAX_TARGET_LENGTH),

                /*
                 * ⛔ 只驗證正整數。
                 *
                 * ⛔ 刻意**不設** Remains、原訂購量、商品或 provider 的上下限
                 * ——Owner 是那個知道 SMM 後台實際發生什麼的人。
                 */
                TextInput::make('quantity')
                    ->label('實際送出數量')
                    ->required()
                    ->numeric()
                    ->minValue(1)
                    ->default(fn (): int => $this->suggestedQuantity($record))
                    ->helperText('可大於或小於建議數量；本站不套用商品或供應商的上下限。'),

                Placeholder::make('confirmation')
                    ->label('')
                    ->content('我已在 SMM PANEL 處理舊單，確認建立新的履約單。'),
            ])
            ->action(function (FulfillmentOrder $record, array $data): void {
                $user = Auth::user();

                // ⛔ 再檢查一次：偽造的 Livewire 請求不經過 `visible()`。
                abort_unless($user instanceof User && $user->can('replace', $record), 403);

                try {
                    $child = app(CreateFulfillmentReplacement::class)->handle(
                        $user,
                        $record,
                        (string) ($data['target'] ?? ''),
                        (int) ($data['quantity'] ?? 0),
                    );
                } catch (ValidationException $e) {
                    /*
                     * ⛔ 失敗就明說失敗，⛔ 不假裝成功。
                     * 訊息來自 action 的固定本地字串，⛔ 不含 provider 原文。
                     */
                    Notification::make()
                        ->title('未建立更換履約')
                        ->body($e->validator->errors()->first())
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('已建立第 '.$child->sequence_no.' 次履約')
                    ->body('已排入派單佇列，稍後會送出。')
                    ->success()
                    ->send();
            });
    }

    /**
     * ⛔ 只是畫面上的收斂條件；真正的驗證在 action 的 transaction 內。
     *
     * ⛔ 刻意**不看 provider status**：Owner 可以在 Pending／In progress 等
     * 任何狀態下依人工判斷更換（施工單第 2 條）。
     */
    private function canReplace(FulfillmentOrder $record): bool
    {
        $user = Auth::user();

        if (! $user instanceof User || ! $user->can('replace', $record)) {
            return false;
        }

        // ⛔ 必須真的送到過供應商，且尚未被更換過。
        return filled($record->provider_order_id) && ! $record->replacement()->exists();
    }

    /** ⭐ 建議值：有 Remains 用 Remains，否則用本批次實際送出的數量。 */
    private function suggestedQuantity(FulfillmentOrder $record): int
    {
        return $record->provider_remains ?? $record->effectiveQuantity();
    }

    /**
     * ⛔ 仍然是唯讀的 relation manager。
     *
     * ⭐ Filament 的 `isReadOnly()` 會把**所有**變更性 affordance 一併收掉，
     * 包含我們刻意提供的「更換連結」。因此這裡回 false，
     * ⛔ 而由 policy（`create`／`update`／`delete` 全部 false）與每個 action
     * 自己的授權檢查來保證：除了建立新批次之外，沒有任何寫入路徑。
     */
    public function isReadOnly(): bool
    {
        return false;
    }
}
