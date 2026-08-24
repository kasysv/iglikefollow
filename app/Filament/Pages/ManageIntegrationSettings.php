<?php

namespace App\Filament\Pages;

use App\Actions\Integrations\ToggleIntegrationChannel;
use App\Actions\Integrations\UpdateIntegrationCredentials;
use App\Enums\IntegrationEnvironment;
use App\Enums\IntegrationProvider;
use App\Models\IntegrationSetting;
use App\Services\Integrations\LiveIntegration;
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
 * ⛔ 這一頁沒有「測試連線」按鈕,也沒有端點欄位。前者會產生一次對外請求,
 * 後者會讓有人填進來的網址變成這台伺服器帶著憑證去連的地方。
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
     * 現在是否真的可用。
     *
     * @return list<array{provider: IntegrationProvider, label: string, configured: bool, missing: list<string>, enabled: bool, live: bool, togglable: bool}>
     */
    public function channelStates(): array
    {
        $states = [];

        foreach (self::providers() as $provider) {
            $setting = $this->settingFor($provider);
            $missing = LiveIntegration::missingFields($provider);

            $states[] = [
                'provider' => $provider,
                'label' => $provider->label(),
                'configured' => $missing === [],
                'missing' => $missing,
                'enabled' => (bool) ($setting?->is_enabled ?? false),
                // ⛔ 「Owner 開了」與「現在真的可以收款」是兩件事:後者還要求
                // 這個環境可以外呼。分開顯示,才不會在本機看到「正在收款」。
                'live' => LiveIntegration::availableToCustomer($provider),
                // 自動派單仍受版本控制的 allowlist 約束,不是 Owner 的開關。
                'togglable' => $provider !== IntegrationProvider::TheMostPanel,
            ];
        }

        return $states;
    }

    /** 本機／測試環境不會對外送出請求,畫面必須說清楚。 */
    public function outboundAllowed(): bool
    {
        return LiveIntegration::outboundAllowed();
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

        // ⛔ 自動派單不是 Owner 的後台開關;它的批准仍必須是一次 reviewed 的
        // code 變更。這道檢查在後端,不只在畫面上。
        abort_if($resolved === IntegrationProvider::TheMostPanel, 403);

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
