<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * 履約狀態輪詢(M4C)。
 *
 * ⛔ interval 固定每 10 分鐘,不由後台或 env 調整——在取得 provider
 * rate-limit contract 之前不開放。command 內部先問 gate:非 staging 或
 * flag off 時排入 0,所以這個 schedule 條目在 local／production 是無害
 * 的 no-op。⛔ withoutOverlapping 防重疊;不用 onOneServer(目前為單機,
 * 且未證明 cache driver 鎖支援)。
 *
 * ⛔ 沒有、也不會有自動重送 add 的排程;submission_unknown 永遠留給人。
 */
Schedule::command('fulfillment:queue-status-sync')
    ->everyTenMinutes()
    ->withoutOverlapping();
