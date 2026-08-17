<?php

namespace App\Console\Commands;

use App\Actions\Fulfillment\RunTheMostPanelReadOnlyProbe;
use App\Enums\TheMostPanelReadOnlyAction;
use Illuminate\Console\Command;

/**
 * Ask TheMostPanel one read-only question, from a terminal, by hand.
 *
 * ⛔ The action is a fixed argument matched against a closed enum. There is no
 * way to reach `add`, `refill` or `cancel` from here — not with a typo, not
 * with a crafted argument.
 *
 * ⛔ An order id is asked for interactively and never accepted as an option.
 * A shell argument lands in the process list and the shell history, where a
 * customer's order id outlives the command that used it.
 */
class ProbeTheMostPanelReadOnly extends Command
{
    /** ⛔ 沒有 `--order`、沒有 `--all`、沒有批次模式。 */
    protected $signature = 'themostpanel:probe {action : services|balance|status}';

    protected $description = 'TheMostPanel 唯讀探針：只查詢 services／balance／單筆 status，永不建立訂單。';

    public function handle(RunTheMostPanelReadOnlyProbe $probe): int
    {
        $action = TheMostPanelReadOnlyAction::tryFrom((string) $this->argument('action'));

        if ($action === null) {
            $this->error('⛔ 只允許：'.implode('／', TheMostPanelReadOnlyAction::values()));
            $this->line('這個指令永遠不會執行 add／refill／cancel。');

            return self::FAILURE;
        }

        $orderId = null;

        if ($action->requiresOrderId()) {
            /*
             * ⛔ 互動輸入，只存在於本次 process 記憶體。
             *
             * 不寫 shell history、不寫 DB、不寫 cache、不寫 log，也不會出現在
             * 結果文件裡。
             */
            $orderId = trim((string) $this->secret('請輸入一筆「已經存在」的供應商訂單編號（不會被保存）'));

            if ($orderId === '') {
                $this->error('⛔ 未輸入訂單編號，已中止；沒有送出任何請求。');

                return self::FAILURE;
            }
        }

        $this->line("查詢：{$action->label()}");

        $observation = $probe->handle($action, $orderId);

        // ⛔ 立刻清除；之後的輸出路徑不再持有它。
        $orderId = null;
        unset($orderId);

        $this->render($observation);

        return $observation->isObserved() ? self::SUCCESS : self::FAILURE;
    }

    /**
     * ⛔ 只輸出 sanitized 觀察結果。
     *
     * 即使加上 -vvv 也不會有 request body、headers、API key 或 raw response：
     * 它們從來沒有進入這個物件。
     */
    private function render($observation): void
    {
        $rows = [];

        foreach ($observation->toArray() as $key => $value) {
            $rows[] = [$key, is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : (string) $value];
        }

        $this->table(['欄位', '值'], $rows);

        if (! $observation->isObserved()) {
            $this->warn('⛔ 沒有取得可解讀的回應；上方 outcome 是本地代碼，不含供應商原文。');

            return;
        }

        $this->info('已取得結構觀察；⛔ 這只是回應的形狀，不是已驗證的 contract。');
    }
}
