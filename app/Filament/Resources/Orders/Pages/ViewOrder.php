<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Actions\Invoices\QueueInvoiceRecoveryForOrder;
use App\Enums\InvoiceStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use App\Support\Money;
use App\Support\OrderActivityTimeline;
use App\Support\OrderOperationsSummary;
use Filament\Actions\Action;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    /**
     * ⛔ 沒有編輯、刪除或「標記為已付款」的動作。「補開發票」是唯一例外
     * ——它不直接改寫任何付款/發票狀態機的判定結果,只在安全前提成立時
     * 排入既有的 Queue job,由既有的 compare-and-set 決定真正的結果。
     */
    protected function getHeaderActions(): array
    {
        return [$this->recoverInvoiceAction()];
    }

    /**
     * 「補開發票」:Owner-only,經確認視窗,只排 Queue,不在 Livewire
     * request 內直接呼叫綠界。
     *
     * ⛔ 前端 `visible()` 只是提示;真正的邊界在
     * `QueueInvoiceRecoveryForOrder` 內以 DB transaction／row lock 重新
     * 驗證,不信任這裡算出來的可見性。
     */
    private function recoverInvoiceAction(): Action
    {
        return Action::make('recoverInvoice')
            ->label('手動開立發票')
            ->color('warning')
            ->visible(fn (Order $record): bool => Auth::user()?->isOwner() ?? false)
            ->disabled(fn (Order $record): bool => ! $this->invoiceLooksRecoverable($record))
            ->requiresConfirmation()
            ->modalHeading('手動開立發票？')
            ->modalDescription('這會排入一次開立發票的背景工作，不會立即呼叫綠界。這張訂單的每一次嘗試都使用同一個發票關聯編號，所以若先前其實已經開立成功，系統會查回那張發票，不會重複開立。')
            ->modalSubmitActionLabel('確認開立')
            ->action(function (Order $record): void {
                abort_unless(Auth::user()?->isOwner() ?? false, 403);

                $outcome = app(QueueInvoiceRecoveryForOrder::class)->handle(Auth::user(), $record);

                if ($outcome === 'queued') {
                    Notification::make()
                        ->title('已排入開立發票，請稍後重新整理查看結果')
                        ->success()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('無法開立發票')
                    ->body($this->recoveryFailureMessage($outcome))
                    ->danger()
                    ->send();
            });
    }

    /** ⛔ 只用來決定按鈕是否 disabled;不是真正的授權邊界。 */
    private function invoiceLooksRecoverable(Order $record): bool
    {
        $invoice = $record->invoice;

        if ($invoice === null) {
            return $record->isPaid();
        }

        // ⛔ 與 QueueInvoiceRecoveryForOrder::isRecoverable() 對齊；那裡才是邊界。
        return match ($invoice->status) {
            InvoiceStatus::PendingConfiguration,
            InvoiceStatus::Pending => ! $invoice->attempts()->exists(),
            InvoiceStatus::Failed,
            InvoiceStatus::ReconciliationRequired => true,
            default => false,
        };
    }

    private function recoveryFailureMessage(string $outcome): string
    {
        return match ($outcome) {
            'blocked_not_owner' => '只有 Owner 可以開立發票。',
            'blocked_unpaid' => '這筆訂單尚未付款，不能開立發票。',
            'blocked_not_twd' => '目前只支援台幣訂單的電子發票。',
            'blocked_gateway_not_ready' => '綠界電子發票尚未設定完整或尚未啟用。',
            'blocked_not_eligible' => '目前的發票狀態不允許再次開立（已開立、已作廢或正在處理中）。',
            'blocked_audit_unavailable' => '目前無法建立稽核紀錄，因此沒有排入開立。',
            default => '開立發票未完成。',
        };
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            /*
             * M4C:四條線的 read-only 摘要——訂單、付款、發票、履約各自獨立。
             * ⛔ 「尚未建立」就是不存在,不推論成敗;多筆規則見
             * OrderOperationsSummary;沒有任何重送/標記/測試按鈕。
             */
            Section::make('交易流程')
                ->description('四條線各自獨立;「尚未建立」代表該紀錄不存在,不代表成功或失敗。')
                ->schema([
                    TextEntry::make('ops_order')->label('訂單')
                        ->state(fn (Order $record): string => OrderOperationsSummary::for($record)['order']),
                    TextEntry::make('ops_payment')->label('付款')
                        ->state(fn (Order $record): string => OrderOperationsSummary::for($record)['payment']),
                    TextEntry::make('ops_invoice')->label('發票')
                        ->state(fn (Order $record): string => OrderOperationsSummary::for($record)['invoice']),
                    TextEntry::make('ops_fulfillment')->label('履約')
                        ->state(fn (Order $record): string => OrderOperationsSummary::for($record)['fulfillment']),
                ])->columns(2),

            Section::make('訂單')
                ->schema([
                    TextEntry::make('reference')->label('訂單編號')->copyable()->weight('bold'),
                    TextEntry::make('created_at')->label('建立時間')->dateTime('Y-m-d H:i:s'),
                    TextEntry::make('order_status')->label('訂單狀態')->badge()
                        ->formatStateUsing(fn (OrderStatus $state) => $state->label())
                        ->color(fn (OrderStatus $state) => $state->color()),
                    TextEntry::make('payment_status')->label('付款狀態')->badge()
                        ->formatStateUsing(fn (PaymentStatus $state) => $state->label())
                        ->color(fn (PaymentStatus $state) => $state->color()),
                    TextEntry::make('total_amount')->label('應付金額')
                        ->formatStateUsing(fn ($state) => 'NT$'.number_format($state)),
                    TextEntry::make('paid_at')->label('付款完成時間')->dateTime('Y-m-d H:i:s')
                        ->placeholder('尚未付款'),
                ])->columns(3),

            Section::make('商品快照')
                ->description('下單當下的內容。⛔ 之後在後台改價、改名或下架都不會改變這裡。')
                ->schema([
                    TextEntry::make('items.platform_name')->label('平台'),
                    TextEntry::make('items.service_name')->label('服務分類'),
                    TextEntry::make('items.variant_label')->label('服務項目'),
                    TextEntry::make('items.sku')->label('商品編號')->placeholder('—'),
                    TextEntry::make('items.quantity')->label('數量')
                        ->formatStateUsing(fn ($state) => number_format((int) $state)),
                    // 完整四位小數快照，⛔ 不四捨五入成兩位顯示。
                    // ⛔ 標示為「計價率」：這不是客人付的錢，實際收款是下方的整數金額。
                    TextEntry::make('items.unit_price_mills')->label('單價（計價率）')
                        ->helperText('每一單位的計價率，不是實際收款金額。')
                        ->formatStateUsing(fn ($state) => 'NT$'.Money::format((int) $state).' / 單位'),
                    TextEntry::make('items.amount')->label('應付金額（整數台幣）')
                        ->formatStateUsing(fn ($state) => 'NT$'.number_format((int) $state)),
                    TextEntry::make('items.target_value')->label('交付對象')->copyable(),
                ])->columns(3),

            /*
             * ⛔ M4C-ORDER-OPERATIONS-A:直接把履約進度放在訂單主畫面,
             * 客服不必再切到另一頁。SMM 完整服務名稱經
             * `FulfillmentOrder::displayServiceName()` 三層 fallback
             * (凍結快照 → 即時目錄查詢 → 本站分類名稱＋未找到標記),
             * Owner／Editor 皆可見;不顯示 API key、request body 或
             * provider raw message。
             */
            Section::make('SMM 履約進度')
                ->description('每個商品項目一列;「尚未建立」代表這個項目還沒有履約紀錄。')
                ->schema([
                    /*
                     * ⛔ 不用 RepeatableEntry 對 `fulfillmentOrders` 的自動
                     * relationship 解析:`Order::fulfillmentOrders()` 是
                     * `hasManyThrough`,Filament 內部對它跑 exists()／count()
                     * 時,SQLite 對 join 後裸 `id` 會回報 ambiguous column——
                     * order_items 與 fulfillment_orders 剛好都有 id。改用
                     * 明確的 `->state()` 直接回傳已載入的 collection,完全
                     * 繞開那段自動解析。
                     */
                    RepeatableEntry::make('fulfillment_progress')
                        ->hiddenLabel()
                        ->state(fn (Order $record) => $record->fulfillmentOrders()->with('orderItem')->get())
                        /*
                         * ⭐ 欄序由 Owner 指定，⛔ 逐項固定，不得重排：
                         *
                         *   已送出時間 → SMM 訂單編號 → SMM 服務名稱 → 起始值
                         *   → 數量 → 狀態 → 剩餘 → 最後同步時間
                         *
                         * 這個順序是客服排查時的閱讀動線：先確認「送出了沒、
                         * 對方單號多少」，再看「買的是什麼、從多少開始、買多少」，
                         * 最後才是「現在如何、還剩多少、什麼時候問的」。
                         */
                        ->schema([
                            TextEntry::make('submitted_at')->label('已送出時間')->dateTime('Y-m-d H:i:s')
                                ->placeholder('—'),
                            TextEntry::make('provider_order_id')->label('SMM 訂單編號')->placeholder('尚未送出'),
                            TextEntry::make('smm_service_name')->label('SMM 服務名稱')
                                ->state(fn ($record) => $record->displayServiceName()),
                            TextEntry::make('provider_start_count')->label('起始值')
                                ->state(fn ($record): string => $record->displayStartCount()),
                            TextEntry::make('orderItem.quantity')->label('數量')
                                ->formatStateUsing(fn ($state) => number_format((int) $state)),
                            /*
                             * ⭐ 顯示 provider 原文，不再顯示本站翻譯。
                             *
                             * badge 顏色仍由**內部** enum 決定：顏色是本站對
                             * 「這算好消息還是壞消息」的判讀，而 provider 只給
                             * 一個字串。⛔ 顏色不改由原文推導——那等於用未經
                             * 狀態機驗證的文字控制呈現。
                             */
                            TextEntry::make('provider_status')->label('狀態')->badge()
                                ->state(fn ($record): string => $record->displayProviderStatus())
                                ->color(fn ($record) => $record->status->color()),
                            TextEntry::make('provider_remains')->label('剩餘')
                                ->state(fn ($record): string => $record->displayRemains()),
                            TextEntry::make('last_synced_at')->label('最後同步時間')->dateTime('Y-m-d H:i:s')
                                ->placeholder('—'),
                        ])->columns(4),
                ])
                ->visible(fn (Order $record): bool => $record->fulfillmentOrders()->with('orderItem')->get()->isNotEmpty()),

            /*
             * ⛔ Owner 與 Editor 都能看到完整聯絡與交付資料——客服需要這些
             * 才能真的聯絡客人、確認交付對象。OrderPolicy 本身已把兩者都
             * 擋在頁面外的角色排除掉，所以進得了這一頁就可以看到這裡。
             */
            Section::make('客戶聯絡與交付資料')
                ->description('完整資料，可直接複製聯絡客人。')
                ->schema([
                    TextEntry::make('customer_email')->label('通知 Email')->copyable(),
                    TextEntry::make('customer_phone')->label('聯絡手機')
                        ->placeholder('未提供')->copyable(),
                ])->columns(3),

            /*
             * ⛔ 發票是稅務資料，只有 Owner 看得到完整值；Editor 進得了這一頁
             * 但這個 section 對其不可見，比對照 InvoicePolicy(Owner-only)。
             * 每種發票輸入模式只顯示與其相關的欄位，不相關的 null 不堆版面。
             */
            Section::make('客戶要求的發票資料')
                ->visible(fn (): bool => Auth::user()?->isOwner() ?? false)
                ->schema([
                    /*
                     * ⛔ 明確的類型標籤,不沿用 invoiceSummary()——那個方法是
                     * 給遮罩畫面用的摘要,公司模式會把統編後 3 碼再摘要一次,
                     * 與下方完整統編欄位重複且語意是「遮罩」不是「類型」。
                     */
                    TextEntry::make('invoice_kind')->label('發票類型')
                        ->state(fn (Order $record): string => match (true) {
                            $record->invoice_kind === 'business' => '公司電子發票',
                            $record->personal_invoice_mode === 'mobile_barcode' => '個人電子發票（手機條碼載具）',
                            $record->personal_invoice_mode === 'donation' => '個人電子發票（捐贈）',
                            default => '個人電子發票（寄送至 Email）',
                        }),
                    // ⛔ 只在 personal_invoice_mode 明確為 email 時顯示,不是「非公司」就顯示。
                    TextEntry::make('invoice_email')->label('寄送 Email')
                        ->visible(fn (Order $record): bool => $record->invoice_kind !== 'business'
                            && $record->personal_invoice_mode === 'email')
                        ->state(fn (Order $record): string => (string) $record->customer_email)
                        ->copyable(),
                    TextEntry::make('carrier_number')->label('手機條碼載具')
                        ->visible(fn (Order $record): bool => $record->invoice_kind !== 'business'
                            && $record->personal_invoice_mode === 'mobile_barcode')
                        ->copyable(),
                    TextEntry::make('love_code')->label('捐贈碼')
                        ->visible(fn (Order $record): bool => $record->invoice_kind !== 'business'
                            && $record->personal_invoice_mode === 'donation')
                        ->copyable(),
                    TextEntry::make('buyer_tax_id')->label('統一編號')
                        ->visible(fn (Order $record): bool => $record->invoice_kind === 'business')
                        ->copyable(),
                    TextEntry::make('buyer_name')->label('公司抬頭')
                        ->visible(fn (Order $record): bool => $record->invoice_kind === 'business')
                        ->copyable(),
                ])->columns(3),

            /*
             * M2-E-B:發票收進訂單頁,客服不必再切到獨立發票清單。
             * ⛔ 全部唯讀:沒有開立、重送、作廢或「標記已開立」按鈕——
             * 那些只屬於發票狀態機。⛔ Owner-only:比對 InvoicePolicy,一張
             * 發票的完整號碼／隨機碼／provider 參考碼是稅務對帳資料。
             * 沒有發票時每一欄都顯示「尚未開立」,不推論成功或失敗。
             */
            Section::make('實際開立結果')
                ->description('「尚未開立」代表沒有這筆紀錄，不代表開立失敗。')
                ->visible(fn (): bool => Auth::user()?->isOwner() ?? false)
                ->schema([
                    TextEntry::make('invoice_status')->label('發票狀態')
                        ->state(fn (Order $record): string => $record->invoice?->status->label() ?? '尚未開立'),
                    TextEntry::make('invoice_amount')->label('金額（整數台幣）')
                        ->state(fn (Order $record): string => $record->invoice === null
                            ? '尚未開立'
                            : 'NT$'.number_format($record->invoice->amount)),
                    TextEntry::make('invoice_number_full')->label('發票號碼')
                        ->state(fn (Order $record): string => $record->invoice?->invoice_number ?? '尚未開立')
                        ->copyable(),
                    TextEntry::make('invoice_random_code')->label('隨機碼')
                        ->state(fn (Order $record): string => $record->invoice?->random_code ?? '尚未開立')
                        ->copyable(),
                    TextEntry::make('invoice_reference_full')->label('供應商參考碼')
                        ->state(fn (Order $record): string => $record->invoice?->provider_reference ?? '尚未開立')
                        ->copyable(),
                    TextEntry::make('invoice_issued_at')->label('開立時間')
                        ->state(fn (Order $record): string => $record->invoice?->issued_at?->format('Y-m-d H:i:s') ?? '尚未開立'),
                    TextEntry::make('invoice_voided_at')->label('作廢時間')
                        ->state(fn (Order $record): string => $record->invoice?->voided_at?->format('Y-m-d H:i:s') ?? '未作廢'),
                    TextEntry::make('invoice_allowance_at')->label('折讓時間')
                        ->state(fn (Order $record): string => $record->invoice?->allowance_at?->format('Y-m-d H:i:s') ?? '無折讓'),
                    TextEntry::make('invoice_reconciliation_required_at')->label('需人工對帳時間')
                        ->state(fn (Order $record): string => $record->invoice?->reconciliation_required_at?->format('Y-m-d H:i:s') ?? '—'),
                    TextEntry::make('invoice_attempts')->label('開立嘗試')
                        ->state(fn (Order $record): string => $record->invoice === null
                            ? '尚未開立'
                            : $record->invoice->attempts()->count().' 次'),
                    /*
                     * ⭐ 失敗代碼與本地說明。
                     *
                     * ⛔ Owner 先前只看得到 `UNKNOWN`，無從分辨是憑證、傳輸、
                     * 開立欄位還是查詢解析問題，只能靠再送一次真實 Issue 去猜
                     * ——而每一次盲測都可能開出一張真的發票。
                     *
                     * 代碼形如 `ISSUE_RTN=10000001|QUERY_RTN=10000050`：
                     * 階段＋層級＋綠界自己的數字碼。⛔ 說明文字全部來自本地
                     * allowlist，不含 `RtnMsg`、raw response、credential 或
                     * 買受人資料。
                     */
                    TextEntry::make('invoice_failure_code')->label('失敗代碼')
                        ->state(fn (Order $record): string => $record->invoice?->failure_code ?? '—')
                        ->copyable(),
                    TextEntry::make('invoice_failure_message')->label('失敗說明')
                        ->columnSpanFull()
                        ->state(fn (Order $record): string => $record->invoice?->failure_message ?? '—'),
                    TextEntry::make('invoice_note')->label('狀態說明')
                        ->columnSpanFull()
                        ->state(fn (Order $record): string => OrderOperationsSummary::for($record)['invoice']),
                ])->columns(3),

            /*
             * ⛔ 合併訂單事件與履約事件的唯讀時間表。`OrderActivityTimeline`
             * 只讀 `order_events`／`fulfillment_events`,不另外寫入任何一筆
             * DB event——這裡是呈現層,不是第三個 event 來源。
             */
            /*
             * ⭐ 唯一的「訂單時間線」——訂單事件與履約事件合併在這一個區塊。
             *
             * ⛔ 舊版是「主畫面『訂單時間表』（已合併）」＋「下方『訂單時間線』
             * relation manager（只有 order_events）」兩份並存。同一頁兩條時間
             * 線，客服會不確定哪一個才是完整的，而兩處各自演進就會開始不一致。
             * Owner 要求併成一個，因此 relation manager 已不再掛載
             * （見 `OrderResource::getRelations()`），這裡是唯一呈現位置。
             *
             * ⛔ 唯讀 presenter：`OrderActivityTimeline` 只讀 `order_events`
             * 與 `fulfillment_events` 兩張 append-only 表，⛔ 不新增第三個事件
             * 來源、不在開頁時寫入、不呼叫任何 provider。
             *
             * ⛔ 每列帶穩定唯一 key（`order:{id}`／`fulfillment:{id}`），
             * 排序固定為 `created_at → id → source`。
             */
            Section::make('訂單時間線')
                ->description('依時間合併顯示訂單事件與履約進度。')
                ->schema([
                    RepeatableEntry::make('activity_timeline')
                        ->hiddenLabel()
                        ->state(fn (Order $record): array => OrderActivityTimeline::for($record))
                        ->schema([
                            TextEntry::make('created_at')->label('時間')->dateTime('Y-m-d H:i:s'),
                            TextEntry::make('label')->label('事件')->weight('bold'),
                            TextEntry::make('smm_service_name')->label('SMM 服務')->placeholder('—'),
                        ])->columns(3),
                ]),
        ]);
    }
}
