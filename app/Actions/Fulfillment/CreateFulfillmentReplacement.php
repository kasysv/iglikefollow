<?php

namespace App\Actions\Fulfillment;

use App\Enums\FulfillmentEventCode;
use App\Enums\FulfillmentStatus;
use App\Jobs\SubmitFulfillmentOrder;
use App\Models\AdminAuditLog;
use App\Models\FulfillmentOrder;
use App\Models\User;
use App\Services\Fulfillment\FulfillmentDispatchGate;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Owner replaces a fulfilment batch with a new link and quantity.
 *
 * ⭐ Owner 的實際流程：他**先自己**在 TheMostPanel 後台取消舊單，再回到本站
 * 輸入新連結與新數量。本站因此：
 *
 *  - ⛔ **不呼叫供應商的取消 API**——那個動作已經由人做完了；
 *  - ⛔ **不即時查 status**、不等十分鐘排程、不以 provider status 當按鈕閘門；
 *  - ⛔ **不改寫 parent 的 status／原文／Remains**：舊批次仍由既有排程繼續
 *    同步，日後真正回傳 Partial／Canceled 時照常保存。
 *
 * ⛔ 人工取消的判斷由 Owner 承擔。系統不假裝知道供應商那邊發生了什麼。
 */
class CreateFulfillmentReplacement
{
    /** ⛔ 與 checkout 的 target 欄位同一上限。 */
    public const MAX_TARGET_LENGTH = 255;

    /** ⛔ `quantity_override` 是 unsigned integer；這是它能保存的上限。 */
    public const MAX_QUANTITY = 4294967295;

    /**
     * ⛔⛔ `$target` 與 `$quantity` 刻意宣告為 `mixed`。
     *
     * ⭐ R1 修正：初版把它們宣告成 `string` 與 `int`，於是 **PHP 的型別轉換
     * 搶在我們的驗證之前發生**——`1.5` 靜默變成 `1`、`'1e3'` 變成 `1000`。
     * 那正是 Owner 明確禁止的「自動調整」，而且是最難察覺的一種：畫面顯示
     * 成功，送出去的數量卻不是他打的那個。
     *
     * ⛔ 接受未信任的原始 scalar，先封閉驗證，再轉成安全的整數。
     *
     * @param  mixed  $target  ⛔ 未信任的原始輸入
     * @param  mixed  $quantity  ⛔ 未信任的原始輸入
     *
     * @throws ValidationException 任何一項前置條件不成立
     */
    public function handle(
        User $actor,
        FulfillmentOrder $parent,
        mixed $target,
        mixed $quantity,
    ): FulfillmentOrder {
        /*
         * ⛔ 權限在 action 內再檢查一次。
         *
         * Filament 的 `visible()` 只擋畫面；一份手寫的 Livewire payload 從來
         * 不經過畫面。這與既有 `RevealIntegrationSecret` 是同一個理由。
         */
        if (! $actor->isOwner()) {
            throw ValidationException::withMessages([
                'target' => '⛔ 只有 Owner 可以建立更換履約。',
            ]);
        }

        $target = self::validatedTarget($target);
        $quantity = self::validatedQuantity($quantity);

        /*
         * ⛔ 派單總開關未成立時**不建立** child。
         *
         * 建立一筆看起來會立刻送出、實際卻卡在 queue 的 replacement，比直接
         * 拒絕更糟：Owner 會以為已經處理好了。
         */
        if (! FulfillmentDispatchGate::enabled()) {
            throw ValidationException::withMessages([
                'target' => '⛔ 自動派單目前未啟用，因此沒有建立更換履約。請先於串接設定開啟。',
            ]);
        }

        $child = DB::transaction(function () use ($actor, $parent, $target, $quantity): FulfillmentOrder {
            /*
             * ⛔ lock parent，並在鎖內**重新**驗證所有條件。
             *
             * 兩個並行請求（雙擊、重送的 Livewire request、兩個 worker）都會
             * 走到這裡；先到的那個建立 child，後到的必須看到它並拒絕。
             */
            $locked = FulfillmentOrder::query()
                ->whereKey($parent->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                throw ValidationException::withMessages([
                    'target' => '⛔ 找不到這筆履約紀錄。',
                ]);
            }

            /*
             * ⛔ 必須已經真的送到過供應商。
             *
             * 沒有 provider order ID 代表這一批從來沒有成立——那不是「更換」，
             * 而是原本那一批還沒送出去。此時該做的是讓既有流程把它送出，
             * ⛔ 不是另外建立一批。
             */
            if (blank($locked->provider_order_id)) {
                throw ValidationException::withMessages([
                    'target' => '⛔ 這一批尚未取得供應商單號，無法更換。',
                ]);
            }

            if (! $locked->orderItem?->order?->isPaid()) {
                throw ValidationException::withMessages([
                    'target' => '⛔ 只有已付款的訂單可以建立更換履約。',
                ]);
            }

            /*
             * ⛔ 一個 parent 最多一個直接 child。
             *
             * 這裡的應用層檢查與 DB 的 unique index 是兩道；DB 那道才是最終
             * 防線（兩個 transaction 可能同時通過這個檢查）。
             */
            if ($locked->replacement()->exists()) {
                throw ValidationException::withMessages([
                    'target' => '⛔ 此批次已更換，無法重複建立。',
                ]);
            }

            /*
             * ⭐ 建議數量只是**快照**，不參與任何限制。
             *
             * 有 Remains 就用它；沒有（尚未同步到）就用這一批實際送出的數量。
             */
            $suggested = $locked->provider_remains ?? $locked->effectiveQuantity();

            /*
             * ⛔⛔ 供應商服務**逐字沿用 parent 的凍結快照**。
             *
             * ⛔ 絕不重新讀目前的 mapping 或 catalog：那兩者可能在下單之後被
             * 改過。若在更換途中改讀最新設定，Owner 以為只是換個連結重送，
             * 實際上卻把訂單送去了**另一個服務**。
             */
            $child = FulfillmentOrder::create([
                'order_item_id' => $locked->order_item_id,
                'sequence_no' => $locked->sequence_no + 1,
                'replaces_fulfillment_order_id' => $locked->getKey(),

                // ⭐ 逐字複製的 provider 快照。
                'fulfillment_mapping_id' => $locked->fulfillment_mapping_id,
                'provider' => $locked->provider,
                'provider_service_id_snapshot' => $locked->provider_service_id_snapshot,
                'provider_service_name_snapshot' => $locked->provider_service_name_snapshot,
                'payload_type_snapshot' => $locked->payload_type_snapshot,

                // ⭐ 這一批自己的交付資料。
                'target_value_override' => $target,
                'quantity_override' => $quantity,
                'suggested_quantity_snapshot' => $suggested,
                'replacement_created_by_user_id' => $actor->getKey(),

                // ⛔ 全新的一批：Ready、沒有 provider ID、attempt 從 0 開始。
                'status' => FulfillmentStatus::Ready,
                'attempt_count' => 0,
            ]);

            $child->recordEvent(FulfillmentEventCode::Created, to: FulfillmentStatus::Ready);
            $child->recordEvent(
                FulfillmentEventCode::ReplacementCreated,
                to: FulfillmentStatus::Ready,
            );

            $this->recordAudit($actor, $locked, $child, $suggested, $quantity);

            return $child;
        });

        /*
         * ⛔ 排 job 在 **transaction 之外**（commit 之後）。
         *
         * 在 transaction 內 dispatch，worker 有機會在 commit 完成前就撈到這筆
         * 工作，然後找不到那一列。⛔ 恰好排一次，⛔ 0 同步 provider call、
         * ⛔ 0 自動 retry。
         */
        SubmitFulfillmentOrder::dispatch($child->getKey());

        return $child->fresh();
    }

    /**
     * The new target, exactly as the Owner submitted it.
     *
     * ⛔⛔ R1 修正：初版先 `trim()` 再保存——那是**改寫 Owner 的輸入**，
     * 而且是靜默的：他看不出我們動過。原施工單要求「保存原值，不自行補網址
     * 或改寫」。
     *
     * ⭐ 空白**可以**用來判定「全空白＝沒填」，但判定與儲存是兩件事：
     * 用 `trim()` 的結果**判斷**，用原始字串**儲存**。
     *
     * ⛔ 長度上限對**原始字串**計算。若先 trim 再量長度，一個 260 字元、
     * 首尾各有幾個空白的輸入會被誤判為合法，然後那個超長值被存進 DB。
     */
    private static function validatedTarget(mixed $target): string
    {
        if (! is_string($target)) {
            throw ValidationException::withMessages([
                'target' => '請輸入新的連結／帳號。',
            ]);
        }

        // ⛔ 只用來判定「是不是全空白」，⛔ 不拿它的結果去儲存。
        if (trim($target) === '') {
            throw ValidationException::withMessages([
                'target' => '請輸入新的連結／帳號。',
            ]);
        }

        if (mb_strlen($target) > self::MAX_TARGET_LENGTH) {
            throw ValidationException::withMessages([
                'target' => '新的連結／帳號最多 '.self::MAX_TARGET_LENGTH.' 字。',
            ]);
        }

        return $target;
    }

    /**
     * A canonical decimal positive integer, or a refusal.
     *
     * ⭐ 唯一的數量驗證：可由 unsigned integer 保存的正整數。
     *
     * ⛔⛔ 刻意**不套**商品或供應商的 min／max，⛔ 不與 Remains、原訂購量
     * 或前一批次數量比較。Owner 是那個知道 SMM 後台實際發生什麼的人；
     * 我們自作主張調整，等於用一個猜測覆蓋掉他的判斷。
     *
     * ⛔⛔ 但「不限制範圍」**不等於**「接受任何東西」。以下一律拒絕，
     * ⛔ 絕不截斷、四捨五入或修正：
     *
     *  - 小數（`1.5`）與指數記法（`1e3`）——PHP 的 `(int)` 會把它們變成
     *    另一個數字，而那個數字不是 Owner 打的；
     *  - 帶正負號（`+100`／`-5`）、前後空白（` 100 `）、前導零（`007`）
     *    ——它們都不是 canonical 形式，接受其中任何一種就等於預設「我們
     *    知道你的意思」；
     *  - 陣列／物件／布林／null／空字串；
     *  - `0`、負數與超過 unsigned 上限的值。
     *
     * ⛔ 用 regex 比對**原始字串**，⛔ 不用 `is_numeric()`——後者接受
     * `1.5`、`1e3` 與前後空白。
     */
    private static function validatedQuantity(mixed $quantity): int
    {
        $refuse = static fn (): never => throw ValidationException::withMessages([
            'quantity' => '實際送出數量必須是正整數（不含小數、正負號或空白）。',
        ]);

        /*
         * ⛔ 只接受 int 與 string 兩種輸入型別。
         * ⛔ float 一律拒絕——連 `2.0` 也是：它已經失去「Owner 打的是不是
         * 整數」這個資訊，而我們無法分辨它原本是 `2` 還是 `2.0`。
         */
        if (is_int($quantity)) {
            $raw = (string) $quantity;
        } elseif (is_string($quantity)) {
            $raw = $quantity;
        } else {
            $refuse();
        }

        // ⛔ canonical 十進位正整數：無符號、無空白、無前導零。
        if (preg_match('/\A[1-9][0-9]*\z/', $raw) !== 1) {
            $refuse();
        }

        /*
         * ⛔ 先比長度再轉型：一個 20 位數的字串轉成 int 會 overflow 成
         * 一個看起來合理的值，而那個值不是 Owner 打的。
         */
        if (strlen($raw) > strlen((string) self::MAX_QUANTITY)) {
            $refuse();
        }

        $value = (int) $raw;

        if ($value < 1 || $value > self::MAX_QUANTITY) {
            $refuse();
        }

        return $value;
    }

    /**
     * ⛔⛔ Audit 只記可稽核的**識別碼與數字**。
     *
     * ⛔ 絕不放：舊／新 target、provider order ID、service ID／name，或任何
     * credential。audit log 的存取控制比訂單資料寬鬆，而新 target 只該存在於
     * 那個 encrypted 欄位裡——後台要看，就透過授權的 model 讀。
     */
    private function recordAudit(
        User $actor,
        FulfillmentOrder $parent,
        FulfillmentOrder $child,
        int $suggested,
        int $actual,
    ): void {
        AdminAuditLog::create([
            'user_id' => $actor->getKey(),
            'action' => 'fulfillment_replacement_created',
            'auditable_type' => FulfillmentOrder::class,
            'auditable_id' => $child->getKey(),
            'after' => [
                'parent_fulfillment_order_id' => $parent->getKey(),
                'child_fulfillment_order_id' => $child->getKey(),
                'sequence_no' => $child->sequence_no,
                'suggested_quantity' => $suggested,
                'actual_quantity' => $actual,
            ],
        ]);
    }
}
