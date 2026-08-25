<?php

namespace App\Filament\Resources\FulfillmentOrders\Pages;

use App\Enums\FulfillmentAttentionReason;
use App\Enums\FulfillmentEventCode;
use App\Enums\FulfillmentStatus;
use App\Filament\Resources\FulfillmentOrders\FulfillmentOrderResource;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

class ViewFulfillmentOrder extends ViewRecord
{
    protected static string $resource = FulfillmentOrderResource::class;

    /** ⛔ 沒有重送、取消或手動標記完成。 */
    protected function getHeaderActions(): array
    {
        return [];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('履約狀態')->schema([
                // ⛔ 完整 SMM 服務名稱：Owner／Editor 皆可見（服務代碼另在下方 Owner-only）。
                TextEntry::make('smm_service_name')
                    ->label('SMM 服務名稱')
                    ->state(fn ($record) => $record->displayServiceName()),

                TextEntry::make('status')
                    ->label('狀態')
                    ->badge()
                    ->formatStateUsing(fn (FulfillmentStatus $state) => $state->label())
                    ->color(fn (FulfillmentStatus $state) => $state->color()),

                TextEntry::make('attention_code')
                    ->label('待處理原因')
                    // ⛔ 本地 enum 訊息，不含 provider 任何字元。
                    ->formatStateUsing(fn (?FulfillmentAttentionReason $state) => $state?->message())
                    ->placeholder('—'),

                TextEntry::make('provider')->label('供應商'),
                TextEntry::make('provider_order_id')->label('供應商單號')->placeholder('尚未送出'),
                TextEntry::make('attempt_count')->label('送出次數'),
                TextEntry::make('submitted_at')->label('送出時間')->dateTime('Y-m-d H:i:s')->placeholder('—'),
                TextEntry::make('last_synced_at')->label('最後同步')->dateTime('Y-m-d H:i:s')->placeholder('—'),
            ])->columns(3),

            Section::make('訂單')->schema([
                TextEntry::make('orderItem.order.reference')->label('訂單編號'),
                TextEntry::make('orderItem.platform_name')->label('平台'),
                TextEntry::make('orderItem.service_name')->label('服務'),
                TextEntry::make('orderItem.variant_label')->label('款式'),
                TextEntry::make('orderItem.quantity')->label('數量'),
                TextEntry::make('orderItem.sku')->label('SKU'),
            ])->columns(3),

            /*
             * ⛔ 供應商設定快照只有 Owner 看得到。
             *
             * 這是「當時送去哪裡」的證據，對帳時必要；但客服查訂單狀態不需要
             * 知道我們的進貨來源。
             */
            Section::make('供應商設定快照')
                ->visible(fn () => Auth::user()?->isOwner() ?? false)
                ->schema([
                    TextEntry::make('provider_service_id_snapshot')->label('服務代碼')->placeholder('—'),
                    TextEntry::make('payload_type_snapshot')->label('資料型別')->placeholder('—'),
                    TextEntry::make('request_fingerprint')
                        ->label('請求指紋')
                        ->placeholder('—')
                        // ⛔ 單向雜湊，無法還原成請求內容。
                        ->helperText('單向雜湊，僅用於判斷是否為同一次請求。'),
                ])->columns(3),

            Section::make('時間線')->schema([
                RepeatableEntry::make('events')
                    ->hiddenLabel()
                    ->schema([
                        TextEntry::make('created_at')->label('時間')->dateTime('Y-m-d H:i:s'),
                        TextEntry::make('event_code')
                            ->label('事件')
                            ->formatStateUsing(fn (FulfillmentEventCode $state) => $state->label()),
                        TextEntry::make('from_status')
                            ->label('原狀態')
                            ->formatStateUsing(fn (?FulfillmentStatus $state) => $state?->label())
                            ->placeholder('—'),
                        TextEntry::make('to_status')
                            ->label('新狀態')
                            ->formatStateUsing(fn (?FulfillmentStatus $state) => $state?->label())
                            ->placeholder('—'),
                    ])->columns(4),
            ]),
        ]);
    }
}
