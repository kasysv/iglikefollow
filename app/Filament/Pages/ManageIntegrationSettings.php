<?php

namespace App\Filament\Pages;

use App\Actions\Integrations\UpdateIntegrationCredentials;
use App\Enums\IntegrationEnvironment;
use App\Enums\IntegrationProvider;
use App\Models\IntegrationSetting;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * Owner-only credential entry for the payment, invoice and fulfilment providers.
 *
 * Two rules shape this page.
 *
 * Secret inputs are never hydrated. The stored value is not sent to the
 * browser, put into Livewire state, or rendered — the page shows only whether
 * each field is configured. That means a blank box means "unchanged", which is
 * why saving cannot wipe a key the admin did not retype.
 *
 * There is no test-connection button, no enable switch and no endpoint field.
 * Each would either make an external call this milestone forbids, or let
 * someone type a hostname this server would then authenticate to.
 */
class ManageIntegrationSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static ?string $navigationLabel = '串接設定';

    protected static ?int $navigationSort = 8;

    protected static ?string $title = '串接設定';

    protected string $view = 'filament.pages.manage-integration-settings';

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

        foreach ($this->rows() as $key => [$provider, $environment]) {
            $setting = $this->settingFor($provider, $environment);
            $state[$key.'_identifier'] = $setting?->identifier ?? '';
        }

        $this->form->fill($state);
    }

    /**
     * Every provider and environment combination this page edits.
     *
     * @return array<string, array{IntegrationProvider, IntegrationEnvironment}>
     */
    private function rows(): array
    {
        $rows = [];

        foreach (IntegrationProvider::cases() as $provider) {
            foreach ($provider->environments() as $environment) {
                $rows[$provider->value.'__'.$environment->value] = [$provider, $environment];
            }
        }

        return $rows;
    }

    private function settingFor(IntegrationProvider $provider, IntegrationEnvironment $environment): ?IntegrationSetting
    {
        return IntegrationSetting::query()
            ->where('provider', $provider)
            ->where('environment', $environment)
            ->first();
    }

    public function form(Schema $schema): Schema
    {
        $tabs = [];

        foreach (IntegrationProvider::cases() as $provider) {
            $tabs[] = Tab::make($provider->label())
                ->schema($this->providerSections($provider));
        }

        return $schema->components([Tabs::make('providers')->tabs($tabs)])
            ->statePath('data');
    }

    /** @return list<Section> */
    private function providerSections(IntegrationProvider $provider): array
    {
        $sections = [];

        foreach ($provider->environments() as $environment) {
            $key = $provider->value.'__'.$environment->value;
            $setting = $this->settingFor($provider, $environment);

            $fields = [];

            if ($provider->identifierLabel() !== null) {
                $fields[] = TextInput::make($key.'_identifier')
                    ->label($provider->identifierLabel())
                    ->helperText('這是公開的識別碼，不是密鑰。')
                    ->maxLength(255);
            }

            foreach ($provider->secretKeys() as $secretKey) {
                $configured = $setting?->hasSecret($secretKey) ?? false;

                $fields[] = TextInput::make($key.'_secret_'.$secretKey)
                    ->label($provider->secretLabel($secretKey))
                    ->password()
                    ->revealable(false)
                    // ⛔ 永遠留空：不把已存的密鑰送到瀏覽器。
                    ->default('')
                    ->dehydrated()
                    ->helperText($configured
                        ? '目前狀態：已設定。留空表示保留現有金鑰，填入新值才會覆寫。'
                        : '目前狀態：未設定。')
                    ->maxLength(255);
            }

            $description = $environment->label();

            if ($note = $provider->restrictionNote()) {
                $description .= '　⚠️ '.$note;
            }

            $sections[] = Section::make($provider->label().'　'.$environment->label())
                ->description($description)
                ->schema($fields)
                ->columns(2);
        }

        return $sections;
    }

    protected function getFormActions(): array
    {
        return [Action::make('save')->label('儲存')->submit('save')];
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

        foreach ($this->rows() as $key => [$provider, $environment]) {
            $secrets = [];

            foreach ($provider->secretKeys() as $secretKey) {
                $secrets[$secretKey] = $data[$key.'_secret_'.$secretKey] ?? null;
            }

            $identifier = $provider->identifierLabel() === null
                ? null
                : ($data[$key.'_identifier'] ?? null);

            $changed = $update->handle($provider, $environment, $identifier, $secrets);

            if ($changed !== []) {
                $touched[] = $provider->label();
            }

            // ⛔ 儲存後立即清掉輸入框，密鑰不留在 Livewire state。
            foreach ($provider->secretKeys() as $secretKey) {
                $this->data[$key.'_secret_'.$secretKey] = '';
            }
        }

        Notification::make()
            ->title($touched === [] ? '沒有變更' : '已更新：'.implode('、', array_unique($touched)))
            ->success()
            ->send();

        $this->dispatch('saved');
    }
}
