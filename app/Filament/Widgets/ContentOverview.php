<?php

namespace App\Filament\Widgets;

use App\Models\Faq;
use App\Models\Platform;
use App\Models\Service;
use App\Models\ServiceVariant;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** ⛔ 只顯示真實的內容計數；不使用虛構營收或成效資料。 */
class ContentOverview extends StatsOverviewWidget
{
    // ⭐ 首頁順序：訂單概況(10)→ 網站內容(20)→ 帳號(30)。
    protected static ?int $sort = 20;

    protected ?string $heading = '網站內容';

    protected function getStats(): array
    {
        return [
            Stat::make('平台', Platform::query()->where('status', 'published')->count())
                ->description(Platform::query()->where('status', 'draft')->count().' 筆草稿'),
            Stat::make('服務分類', Service::query()->where('status', 'published')->count())
                ->description(Service::query()->where('status', 'draft')->count().' 筆草稿'),
            Stat::make('服務項目', ServiceVariant::query()->where('status', 'published')->count())
                ->description(ServiceVariant::query()->where('status', 'draft')->count().' 筆草稿'),
            Stat::make('FAQ', Faq::query()->where('status', 'published')->count())
                ->description(Faq::query()->where('status', 'draft')->count().' 筆草稿'),
        ];
    }
}
