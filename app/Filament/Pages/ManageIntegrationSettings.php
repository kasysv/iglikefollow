<?php

namespace App\Filament\Pages;

use App\Actions\Fulfillment\SyncTheMostPanelServiceCatalogFromOwner;
use App\Actions\Integrations\ToggleIntegrationChannel;
use App\Actions\Integrations\UpdateIntegrationCredentials;
use App\Enums\IntegrationEnvironment;
use App\Enums\IntegrationProvider;
use App\Filament\Resources\ProviderServices\ProviderServiceResource;
use App\Models\IntegrationSetting;
use App\Models\ProviderService;
use App\Services\Fulfillment\FulfillmentDispatchGate;
use App\Services\Fulfillment\TheMostPanelCurlCapability;
use App\Services\Integrations\LiveIntegration;
use App\Services\Integrations\ProviderEndpoints;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use UnitEnum;

/**
 * Owner-only: the one set of live credentials, and the switches that use them.
 *
 * Owner 於 2026-08-24 明確改變方向:實際網站只管理一套正式營運設定,後台不再
 * 區分「測試環境／正式環境」。所以這一頁每個 provider 只有一個區塊,而
 * runtime 只讀 production 那一列。
 *
 * 三條規則決定了這一頁的形狀。
 *
 * **密鑰輸入永遠不回灌。** 已存的值不會被送到瀏覽器、不進 Livewire state、
 * 不出現在 DOM。畫面只顯示固定的 `********` 或「尚未設定」——⛔ 不顯示末四碼,
 * 也不讓星號數量隨真實長度改變:兩者都在洩漏這個值有多長。
 *
 * **空白代表「保留原值」。** 所以為了改 MerchantID 而重存這一頁,不會把 Owner
 * 沒有重打的 HashKey 清掉。
 *
 * **開關是 Owner 的,而且後端擋。** 把按鈕變灰只是提示;真正的規則在
 * `ToggleIntegrationChannel` 與 model observer 上,因為一份手寫的 Livewire
 * payload 從來不經過畫面。
 *
 * ⛔ 這一頁沒有任意「測試連線」或端點欄位。服務目錄同步是另一個封閉
 * action：只能送一次固定 services 查詢，不能輸入 endpoint 或 action。
 */
class ManageIntegrationSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static ?string $navigationLabel = '串接設定';

    protected static string|UnitEnum|null $navigationGroup = '系統設定';

    protected static ?int $navigationSort = 1;

    protected static ?string $title = '串接設定';

    protected string $view = 'filament.pages.manage-integration-settings';

    /**
     * 固定遮罩字串。
     *
     * ⛔ 長度固定,與真實金鑰長度無關。星號數量會隨真值變化的遮罩,等於一個
     * 慢一點的洩漏。
     *
     * ⛔ 定義只有一份,在 `UpdateIntegrationCredentials`:寫入層必須認得同一個
     * 字串才能拒絕把它存成密鑰。兩處各寫一份,某天只改一處就出現一個顯示成
     * 星號、卻真的被存下來的值。
     */
    public const MASK = UpdateIntegrationCredentials::MASK;

    public ?array $data = [];

    /** ⛔ 只有 active Owner；isOwner() 已包含 is_active 檢查。 */
    public static function canAccess(): bool
    {
        return Auth::user()?->isOwner() ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        // ⛔ 只填非機密欄位；secret 一律留空，不從資料庫回灌。
        $state = [];

        foreach (self::providers() as $provider) {
            $state[$provider->value.'_identifier'] = $this->settingFor($provider)?->identifier ?? '';
        }

        $this->form->fill($state);
    }

    /**
     * 這一頁管理的 provider。
     *
     * ⛔ 全部只有正式那一套。sandbox 列即使存在也不呈現:Owner 不再區分兩者,
     * 而顯示一列 runtime 永遠不會讀的設定,只會讓人以為它有作用。
     *
     * @return list<IntegrationProvider>
     */
    public static function providers(): array
    {
        return IntegrationProvider::cases();
    }

    private function settingFor(IntegrationProvider $provider): ?IntegrationSetting
    {
        return LiveIntegration::row($provider);
    }

    public function form(Schema $schema): Schema
    {
        $sections = [];

        foreach (self::providers() as $provider) {
            $sections[] = $this->providerSection($provider);
        }

        return $schema->components($sections)->statePath('data');
    }

    private function providerSection(IntegrationProvider $provider): Section
    {
        $setting = $this->settingFor($provider);
        $fields = [];

        if ($provider->identifierLabel() !== null) {
            $fields[] = TextInput::make($provider->value.'_identifier')
                ->label($provider->identifierLabel())
                ->helperText('這是公開的識別碼，不是密鑰，可以直接看到以便核對。')
                ->maxLength(255);
        }

        foreach ($provider->secretKeys() as $secretKey) {
            $configured = $setting?->hasSecret($secretKey) ?? false;

            $fields[] = TextInput::make($provider->value.'_secret_'.$secretKey)
                ->label($provider->secretLabel($secretKey))
                ->password()
                ->revealable(false)
                // ⛔ 永遠留空：不把已存的密鑰送到瀏覽器。
                ->default('')
                ->dehydrated()
                ->helperText($configured
                    ? '目前狀態：'.self::MASK.'（已設定）。留空保留；輸入新值才覆寫。'
                    : '目前狀態：尚未設定。')
                ->maxLength(255);
        }

        $description = $provider->identifierLabel() === null
            ? '正式營運設定'
            : '正式營運設定；'.$provider->identifierLabel().'為公開識別碼。';

        if ($note = $provider->restrictionNote()) {
            $description .= '　⚠️ '.$note;
        }

        return Section::make($provider->label())
            ->description($description)
            ->schema($fields)
            ->columns(2);
    }

    /**
     * 每個通道目前的狀態,供 view 呈現。
     *
     * ⛔ 只有狀態,沒有任何值:是否已設定、缺哪些欄位名稱、Owner 開關、
     * 現在是否真的可用、以及(TheMostPanel)技術條件的白話說明。
     *
     * @return list<array{provider: IntegrationProvider, label: string, configured: bool, missing: list<string>, enabled: bool, live: bool, togglable: bool, blockers: list<string>}>
     */
    public function channelStates(): array
    {
        $states = [];

        foreach (self::providers() as $provider) {
            $setting = $this->settingFor($provider);
            $missing = LiveIntegration::missingFields($provider);
            $isDispatch = $provider === IntegrationProvider::TheMostPanel;

            $states[] = [
                'provider' => $provider,
                'label' => $provider->label(),
                'configured' => $missing === [],
                'missing' => $missing,
                'enabled' => (bool) ($setting?->is_enabled ?? false),
                /*
                 * ⛔ 「Owner 開了」與「現在真的會動」是兩件事,分開顯示才不會
                 * 在本機看到「正在收款/派單」。
                 *
                 * ⛔ TheMostPanel 的 live 讀 FulfillmentDispatchGate——與
                 * container binding、商品三態、queue re-check 完全同一份判斷,
                 * 這裡不自己算一套。
                 */
                'live' => $isDispatch
                    ? FulfillmentDispatchGate::enabled()
                    : LiveIntegration::availableToCustomer($provider),
                // ⛔ R1:自動派單總開關也交給 Owner;四個通道全部可切換。
                'togglable' => true,
                // ⛔ 白話的技術條件說明;不含 config 值、exception 或 ID。
                'blockers' => $isDispatch ? $this->dispatchBlockers() : [],
            ];
        }

        return $states;
    }

    /**
     * TheMostPanel 目前擋在哪些技術條件上;⛔ 一般管理者看得懂的字句。
     *
     * @return list<string>
     */
    private function dispatchBlockers(): array
    {
        $blockers = [];

        if (ProviderEndpoints::theMostPanelDispatch() === null) {
            $blockers[] = '派單端點設定與白名單不符';
        }

        if (! app(TheMostPanelCurlCapability::class)->supportsOngoingTransferCap()) {
            // ⛔ R1(curl 7.68):唯一的 runtime 硬條件是 ext-curl 存在。
            $blockers[] = '主機環境不支援（缺少 PHP cURL 擴充）';
        }

        return $blockers;
    }

    /** 本機／測試環境不會對外送出請求,畫面必須說清楚。 */
    public function outboundAllowed(): bool
    {
        return LiveIntegration::outboundAllowed();
    }

    /** @return array{count: int, last_synced_at: ?string, index_url: string} */
    public function theMostPanelCatalogState(): array
    {
        $query = ProviderService::query()
            ->where('provider', IntegrationProvider::TheMostPanel);

        $lastSeenAt = (clone $query)->max('last_seen_at');

        return [
            'count' => (clone $query)->count(),
            'last_synced_at' => $lastSeenAt === null
                ? null
                : date('Y-m-d H:i:s', strtotime((string) $lastSeenAt)),
            'index_url' => ProviderServiceResource::getUrl('index'),
        ];
    }

    public function syncTheMostPanelCatalogAction(): Action
    {
        return Action::make('syncTheMostPanelCatalog')
            ->label('同步 SMM 服務')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('primary')
            ->requiresConfirmation()
            ->modalHeading('同步 SMM 服務清單？')
            ->modalDescription('這會向 TheMostPanel 發送一次唯讀 services 查詢，不會建立訂單。成功後會以完整快照更新本機服務清單。')
            ->modalSubmitActionLabel('確認同步')
            ->action(function (SyncTheMostPanelServiceCatalogFromOwner $sync): void {
                abort_unless(static::canAccess(), 403);

                $result = $sync->handle();

                if ($result->applied) {
                    $state = $this->theMostPanelCatalogState();

                    Notification::make()
                        ->title('已同步 '.$state['count'].' 筆 SMM 服務')
                        ->body('完成時間：'.($state['last_synced_at'] ?? '剛剛'))
                        ->success()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('SMM 服務同步未完成')
                    ->body($this->catalogSyncFailureMessage($result->outcome))
                    ->danger()
                    ->send();
            });
    }

    private function catalogSyncFailureMessage(string $outcome): string
    {
        return match ($outcome) {
            'blocked_no_credential', 'blocked_credential_unreadable', 'blocked_no_app_key' => '請先儲存有效的 TheMostPanel API Key，再重新同步。',
            'blocked_unsupported_transport_cap' => '主機缺少 PHP cURL 擴充，尚未送出同步請求。',
            'blocked_environment', 'blocked_endpoint' => '目前環境不允許同步，尚未送出請求。',
            'blocked_sync_in_progress' => '另一個同步正在進行，請稍後再試。',
            'blocked_lock_unavailable' => '目前無法取得同步鎖，舊服務清單未變更。',
            'blocked_audit_unavailable' => '目前無法建立稽核紀錄，因此沒有執行同步。',
            'catalog_rejected_by_parser', 'catalog_apply_failed' => '回傳格式未通過安全檢查，舊服務清單已保留。',
            'catalog_stale_refused' => '這次資料未比現有服務清單更新，因此沒有覆寫。',
            'body_too_large', 'unsupported_encoding', 'invalid_encoding', 'credential_echo_refused' => '回傳內容未通過安全檢查，舊服務清單已保留。',
            'transport_failed', 'redirect_refused', 'rate_limited', 'server_error',
            'client_error', 'empty_body' => 'SMM 平台目前無法完成查詢，請稍後再試。',
            default => '同步未完成，舊服務清單未變更。',
        };
    }

    protected function getFormActions(): array
    {
        return [Action::make('save')->label('儲存')->submit('save')];
    }

    /**
     * Owner 切換一個通道。
     *
     * ⛔ 後端再檢查一次權限:`canAccess()` 只擋畫面,擋不住偽造的 Livewire 請求。
     * 實際規則在 action 與 observer 上,這裡只負責把結果告訴 Owner。
     */
    public function toggleChannel(string $provider, bool $enable, ToggleIntegrationChannel $toggle): void
    {
        abort_unless(static::canAccess(), 403);

        $resolved = IntegrationProvider::tryFrom($provider);

        // ⛔ fail closed：不認識的 provider 一律拒絕，不是忽略。
        abort_if($resolved === null, 404);

        /*
         * ⛔ R1:TheMostPanel 的 403 已移除——Owner 明確要求自動派單總開關
         * 也放進同一個後台。技術前提(端點、runtime 能力)由 action 在啟用前
         * 檢查並以白話拒絕;credential 完整度由 observer 把關。
         */

        try {
            $now = $toggle->handle($resolved, $enable);
        } catch (ValidationException $e) {
            Notification::make()
                ->title($e->validator->errors()->first())
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title($resolved->label().'已'.($now ? '啟用' : '停用'))
            ->success()
            ->send();
    }

    /**
     * ⛔ 稽核不在這裡呼叫：它與寫入同屬一個 transaction，由
     * UpdateIntegrationCredentials 內部完成。頁面若自己補一次稽核，就會出現
     * 「憑證已 commit、稽核才失敗」的空窗。
     */
    public function save(UpdateIntegrationCredentials $update): void
    {
        // ⛔ 後端再檢查一次：canAccess() 只擋畫面，擋不住偽造的 Livewire 請求。
        abort_unless(static::canAccess(), 403);

        $data = $this->form->getState();
        $touched = [];

        foreach (self::providers() as $provider) {
            $secrets = [];

            foreach ($provider->secretKeys() as $secretKey) {
                $secrets[$secretKey] = $data[$provider->value.'_secret_'.$secretKey] ?? null;
            }

            $identifier = $provider->identifierLabel() === null
                ? null
                : ($data[$provider->value.'_identifier'] ?? null);

            $changed = $update->handle(
                $provider,
                IntegrationEnvironment::Production,
                $identifier,
                $secrets,
            );

            if ($changed !== []) {
                $touched[] = $provider->label();
            }

            // ⛔ 儲存後立即清掉輸入框，密鑰不留在 Livewire state。
            foreach ($provider->secretKeys() as $secretKey) {
                $this->data[$provider->value.'_secret_'.$secretKey] = '';
            }
        }

        Notification::make()
            ->title($touched === [] ? '沒有變更' : '已更新：'.implode('、', array_unique($touched)))
            ->success()
            ->send();

        $this->dispatch('saved');
    }
}
