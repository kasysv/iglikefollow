<?php

namespace App\Filament\Pages;

use App\Filament\Support\ImageField;
use App\Models\SiteSetting;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * Singleton settings page (spec §3 site_settings).
 *
 * Exactly one row exists; it is created on first save and never deleted.
 */
class ManageSiteSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?string $navigationLabel = 'Site settings';

    protected static ?string $title = '網站設定';

    protected string $view = 'filament.pages.manage-site-settings';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return Auth::user()?->isOwner() ?? false;
    }

    public function mount(): void
    {
        $this->form->fill(SiteSetting::current()?->toArray() ?? []);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('company_name')->required()->maxLength(255),
                TextInput::make('home_eyebrow')->maxLength(255),
                TextInput::make('home_h1')->required()->maxLength(255),
                Textarea::make('home_intro')->rows(4)->columnSpanFull(),

                ImageField::upload('company_image_path'),
                ImageField::alt('company_image_alt'),

                TextInput::make('primary_cta_label')->maxLength(255),
                // ⛔ CTA 只能從既有內部 route 白名單選；不接受任意 URL。
                Select::make('primary_cta_route')
                    ->options(array_combine(SiteSetting::CTA_ROUTES, SiteSetting::CTA_ROUTES))
                    ->helperText('只能選擇既有內部 route，不接受外部網址。'),
            ])
            ->statePath('data');
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('save')->label('儲存')->submit('save'),
        ];
    }

    public function save(): void
    {
        $data = $this->form->getState();

        $setting = SiteSetting::current();

        if ($setting) {
            $setting->update($data);
        } else {
            SiteSetting::create($data);
        }

        $this->dispatch('saved');
    }
}
