<?php

namespace App\Filament\Widgets;

use Filament\Widgets\AccountWidget;

/**
 * 首頁的帳號小卡（登入身分＋登出）。
 *
 * ⭐ 2026-11-21 Owner 要求重整首頁排序：帳號資訊不是日常要看的數字，
 * 移到最下方，並佔滿整列——⛔ 原本只佔半列，右邊留下一塊空白。
 */
class AccountOverview extends AccountWidget
{
    protected static ?int $sort = 30;

    protected int|string|array $columnSpan = 'full';
}
