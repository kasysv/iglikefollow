<?php

namespace App\Console\Commands;

use App\Actions\Fulfillment\SyncTheMostPanelServiceCatalog as SyncTheMostPanelServiceCatalogAction;
use Illuminate\Console\Command;

/**
 * The only entry point to the catalog sync — a human, at a terminal.
 *
 * ⛔ No HTTP route, no Filament button, no queue job, no scheduler. Every one
 * of those would turn "a person decided to contact the supplier right now"
 * into something a request or a clock can trigger.
 *
 * ⛔ No provider, action, endpoint, key or body arguments. The command syncs
 * services, or it does nothing.
 *
 * ⛔ Output is the safe result only: outcome code, applied flag, HTTP status,
 * elapsed. Never a service id, name, category, rate or body — a terminal
 * scrollback is a log like any other.
 */
class SyncTheMostPanelServiceCatalog extends Command
{
    protected $signature = 'themostpanel:catalog-sync
        {--approved-once : 明確確認本次手動同步（缺少時不讀 credential、不送出任何請求）}';

    protected $description = 'TheMostPanel 服務目錄手動同步（最多一次 services 請求，無 retry）';

    public function handle(SyncTheMostPanelServiceCatalogAction $sync): int
    {
        if (! $this->option('approved-once')) {
            // ⛔ acknowledgement 在最前面：這裡失敗時 credential 與 HTTP 都是 0。
            $this->error('⛔ 缺少 --approved-once 確認；未讀取 credential、未送出任何請求。');

            return self::FAILURE;
        }

        $result = $sync();

        foreach ($result->toArray() as $field => $value) {
            $this->line($field.': '.(is_bool($value) ? ($value ? 'true' : 'false') : (string) $value));
        }

        return $result->applied ? self::SUCCESS : self::FAILURE;
    }
}
