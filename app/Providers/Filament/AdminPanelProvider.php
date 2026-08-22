<?php

namespace App\Providers\Filament;

use App\Http\Middleware\ForceNoindex;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->colors([
                'primary' => Color::Amber,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            /*
             * M2-E-A:左側導航分成五個可收合群組;⛔ 順序即此陣列順序。
             * 「儀表板」不屬於任何群組,維持在最上方。
             *
             * 初始狀態:日常營運的前兩組展開,設定類三組收合;Filament 之後
             * 若記住使用者的展開狀態,以使用者為準。
             * ⛔ 群組本身不設 icon:Filament v5 規定群組與其項目不得同時有
             * icon,而本輪要求每個「項目」都要有可區分的語意圖示。
             * ⛔ 這裡只宣告顯示用 metadata——不改任何 route、resource model、
             * query 或授權判斷。
             */
            ->navigationGroups([
                NavigationGroup::make('訂單管理')
                    ->collapsible(),
                NavigationGroup::make('商品管理')
                    ->collapsible(),
                NavigationGroup::make('網站內容')
                    ->collapsible(),
                NavigationGroup::make('系統設定')
                    ->collapsible()
                    ->collapsed(),
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            /*
             * M2-E-B:只保留帳號 widget。⛔ 移除 FilamentInfoWidget——那是框架
             * 版本宣傳,對日常客服作業沒有意義,也不新增花俏的 Dashboard。
             */
            ->widgets([
                AccountWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
                // ⛔ /admin 全路徑強制 noindex，即使日後正式前台開放索引。
                ForceNoindex::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
