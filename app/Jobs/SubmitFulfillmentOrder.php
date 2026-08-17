<?php

namespace App\Jobs;

use App\Actions\Fulfillment\SubmitFulfillment;
use App\Models\FulfillmentOrder;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Send one fulfilment row to the provider.
 *
 * ⛔ `tries = 1`. This job places an order that costs money; an automatic retry
 * after an unclear outcome would risk placing a second one. Everything that
 * could not be resolved is left in `submission_unknown` for a person, which is
 * slower and correct.
 */
class SubmitFulfillmentOrder implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** ⛔ 絕不自動重試：重試等於可能下第二筆單。 */
    public int $tries = 1;

    public int $uniqueFor = 300;

    public function __construct(public readonly int $fulfillmentOrderId) {}

    /** ⛔ 用永不改變的履約列 id。 */
    public function uniqueId(): string
    {
        return 'fulfillment-submit-'.$this->fulfillmentOrderId;
    }

    public function handle(SubmitFulfillment $submit): void
    {
        $fulfillment = FulfillmentOrder::query()->find($this->fulfillmentOrderId);

        if ($fulfillment === null) {
            return;
        }

        /*
         * ⛔ 不在這裡判斷狀態就返回。
         *
         * 判斷交給 action 內的原子 claim：在這裡先讀一次再決定，等於製造一個
         * 兩個 worker 都會通過的空窗。queue unique 只是第一層。
         */
        $submit->handle($fulfillment);
    }
}
