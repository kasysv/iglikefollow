<?php

namespace App\Console\Commands;

use App\Actions\Fulfillment\SyncTheMostPanelServiceCatalog as SyncTheMostPanelServiceCatalogAction;
use App\Services\Fulfillment\TheMostPanelCatalogSyncExecutionLedger;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * The only entry point to the catalog sync — a human, at a terminal.
 *
 * ⛔ No HTTP route, no Filament button, no queue job, no scheduler. Every one
 * of those would turn "a person decided to contact the supplier right now"
 * into something a request or a clock can trigger.
 *
 * ⛔ No provider, action, endpoint, key or body arguments. The command syncs
 * services, or it does nothing. `--execution-id` is a non-secret identifier
 * of this one attempt, nothing more.
 *
 * ⛔ Before the action — therefore before the source, the credential and any
 * HTTP — a durable ledger marker is created with exclusive-create semantics.
 * B3 proved why: its ledger was written after the fact, and no artifact
 * could show the single-request budget was consumed before the command ran.
 * Now the marker's existence is a physical precondition, a crash leaves it
 * behind, and the same execution ID can never run twice.
 *
 * ⛔ Output is the safe result only: outcome code, applied flag, HTTP status,
 * elapsed, and — for a parser rejection — the parser's own allowlisted local
 * reason and documented field name. Never a service id, name, category,
 * rate, raw body or exception text: a terminal scrollback is a log like any
 * other, and the ledger keeps exactly the same safe fields.
 */
class SyncTheMostPanelServiceCatalog extends Command
{
    protected $signature = 'themostpanel:catalog-sync
        {--approved-once : 明確確認本次手動同步（缺少時不讀 credential、不送出任何請求）}
        {--execution-id= : 本次執行的非秘密識別碼（8–64 位大寫英數與連字號，首字元英數；同 ID 永不可重用）}';

    protected $description = 'TheMostPanel 服務目錄手動同步（最多一次 services 請求，無 retry）';

    public function handle(SyncTheMostPanelServiceCatalogAction $sync): int
    {
        if (! $this->option('approved-once')) {
            // ⛔ acknowledgement 在最前面：這裡失敗時 credential 與 HTTP 都是 0。
            $this->error('⛔ 缺少 --approved-once 確認；未讀取 credential、未送出任何請求。');

            return self::FAILURE;
        }

        $executionId = (string) $this->option('execution-id');

        if (! TheMostPanelCatalogSyncExecutionLedger::isValidExecutionId($executionId)) {
            // ⛔ 無效／缺少 ID 在 action 之前拒絕：credential 與 HTTP 都是 0。
            $this->error(
                '⛔ 缺少或無效的 --execution-id（格式：8–64 位大寫英數與連字號，首字元英數）；未執行任何動作。'
            );

            return self::FAILURE;
        }

        try {
            /*
             * ⛔ 路徑固定在 storage 私有目錄,由 ledger class 自行解析;
             * 這裡連指定目錄的能力都沒有,檔名只由驗證後的 ID 組成。
             */
            $ledger = TheMostPanelCatalogSyncExecutionLedger::open($executionId);
        } catch (RuntimeException $e) {
            // ledger 的兩個固定安全訊息;⛔ 此時 source／credential／HTTP 均為 0。
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        // ⛔ initial marker 已 durable 落盤,action 才被允許開始。
        $result = $sync();

        foreach ($result->toArray() as $field => $value) {
            $this->line($field.': '.(is_bool($value) ? ($value ? 'true' : 'false') : (string) $value));
        }

        // ⛔ 傳 typed result object,不傳 array:ledger 內部自行呼叫 toArray()。
        if (! $ledger->recordFinal($result)) {
            /*
             * ⛔ final append 失敗不重跑 action——action 已經發生了。initial
             * marker 永久保留,本 ID 已消耗,交人工審查。
             */
            $this->error('⛔ ledger final append 失敗；initial marker 保留、本 execution ID 已消耗，請人工審查。');

            return self::FAILURE;
        }

        return $result->applied ? self::SUCCESS : self::FAILURE;
    }
}
