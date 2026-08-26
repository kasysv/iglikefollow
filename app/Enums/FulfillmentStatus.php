<?php

namespace App\Enums;

/**
 * Where one order item stands with the provider.
 *
 * The shape of this enum is decided by one asymmetry: sending the same order to
 * the provider twice costs real money and delivers a service the customer did
 * not buy, while sending it late costs a delay. So every state that means "we
 * do not know" is kept distinct from "it failed", and only the latter is ever
 * safe to act on.
 *
 * ⛔ There is no `waiting_for_payment`. A fulfilment row is created only after
 * a committed `OrderPaid`, so an unpaid order has no row at all — which is a
 * stronger guarantee than a row in a state we promise not to dispatch.
 */
enum FulfillmentStatus: string
{
    /** 缺 mapping、mapping 停用、派單開關關閉或 payload 不支援。 */
    case ConfigurationPending = 'configuration_pending';

    /** 設定齊全、snapshot 已凍結，等待送出。 */
    case Ready = 'ready';

    /** 已原子搶下，正在送出；⛔ 其他 worker 不得再送。 */
    case Submitting = 'submitting';

    /** 對方明確接受，並回了 provider order ID；⛔ 尚未取得第一次 status 結果。 */
    case Submitted = 'submitted';

    /**
     * ⭐ 供應商的 status API 明確回傳 exact `Pending`——仍在等待處理。
     *
     * ⛔⛔ 這是一個**獨立**狀態，⛔ 不是 `Submitted` 的別名，也不是
     * `Processing`。三者的意思各自不同：
     *
     *  - `Submitted`：我們送出成功、拿到 provider order ID，但**還沒問過**
     *    對方進度。
     *  - `Pending`：問過了，對方明說「還在排隊」。
     *  - `Processing`：問過了，對方明說「正在做」。
     *
     * ⛔ GPT 前一版曾把 provider 的 `Pending` 映射進既有的 `Submitted`，
     * 已被 Owner 否決並撤回：那會讓「還沒問過」與「問過且對方說在排隊」
     * 變成同一件事，後台再也分不出輪詢到底有沒有在動。
     */
    case Pending = 'pending';

    /** 對方回報處理中。 */
    case Processing = 'processing';

    case Completed = 'completed';

    /** 部分完成；⛔ 視為終止，補量不在 M4A。 */
    case Partial = 'partial';

    case Canceled = 'canceled';

    /** 對方明確拒絕，且確定沒有成立。 */
    case Failed = 'failed';

    /**
     * ⛔ 送出結果不明：可能已經成立。
     *
     * 這不是失敗，也不得自動重送——重送會變成第二筆訂單。只標記給 Owner
     * 人工對帳。
     */
    case SubmissionUnknown = 'submission_unknown';

    public function label(): string
    {
        return match ($this) {
            self::ConfigurationPending => '待設定',
            self::Ready => '待送出',
            self::Submitting => '送出中',
            self::Submitted => '已送出',
            // ⛔ 顯示為「等待處理中」,⛔ 不翻成已送出或處理中——那是三件不同的事。
            self::Pending => '等待處理中',
            self::Processing => '處理中',
            self::Completed => '已完成',
            self::Partial => '部分完成',
            self::Canceled => '已取消',
            self::Failed => '失敗',
            self::SubmissionUnknown => '結果不明（需人工對帳）',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Completed => 'success',
            self::Submitted, self::Pending, self::Processing => 'info',
            self::Ready, self::Submitting => 'primary',
            self::Partial, self::SubmissionUnknown => 'warning',
            self::Failed, self::Canceled => 'danger',
            self::ConfigurationPending => 'gray',
        };
    }

    /**
     * ⛔ 終止狀態不得再送出、不得再被 sync 改寫。
     *
     * `submission_unknown` 也算終止：它需要的是人，不是另一次自動嘗試。
     */
    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Completed,
            self::Partial,
            self::Canceled,
            self::Failed,
            self::SubmissionUnknown,
        ], true);
    }

    /**
     * Has this row already been handed to the provider?
     *
     * ⛔ From here on, going back to a pre-submit state would make the row
     * eligible for submission again — which is how one paid item becomes two
     * supplier orders.
     */
    public function isPostSubmit(): bool
    {
        return $this !== self::ConfigurationPending
            && $this !== self::Ready
            && $this !== self::Submitting;
    }

    /**
     * May this row move from here to there?
     *
     * The single rule every path shares — the action layer, the observer and
     * the database guard all read this, so they cannot drift apart.
     *
     * ⛔ Terminal states never move. `completed` walking back to `ready` is not
     * a display bug: the row becomes submittable again and the customer's item
     * is ordered a second time.
     *
     * Staying put is always allowed, so an idempotent re-sync of the same
     * status is not an error.
     */
    public function canTransitionTo(self $target): bool
    {
        if ($this === $target) {
            return true;
        }

        if ($this->isTerminal()) {
            return false;
        }

        // ⛔ 已交付給對方之後不得回到送出前的任何狀態。
        if ($this->isPostSubmit() && ! $target->isPostSubmit()) {
            return false;
        }

        return match ($this) {
            // 設定修好就能進 ready，或原地等待。
            self::ConfigurationPending => $target === self::Ready,

            // 搶到送出權；或送出前發現開關關閉而退回。
            self::Ready => in_array($target, [self::Submitting, self::ConfigurationPending], true),

            /*
             * 送出中可以走向任何結果——包含退回 configuration_pending：
             * 那是「什麼都還沒送出」時才會發生的收斂，安全且必要。
             */
            self::Submitting => in_array($target, [
                self::Submitted,
                self::Failed,
                self::SubmissionUnknown,
                self::ConfigurationPending,
            ], true),

            /*
             * 對方接受後，只能依對方回報前進。
             *
             * `submission_unknown` 也在其中：已送出的單如果後來連查都查不到、
             * 或本地回寫失敗，把它標成需要人工對帳是誠實且必要的。⛔ 它同樣
             * 是終止狀態，不會因此變得可以重送。
             *
             * ⭐ `Pending` 與 `Processing` 之間**雙向**允許：
             *
             * 供應商在 `Processing` 之後又明確回 exact `Pending` 是真實會發生
             * 的（重新排隊）。⛔ 那種情況不得寫成 unrecognised，也不得改叫
             * `Submitted`——兩者都已有 provider order ID，都是 post-submit 的
             * 可輪詢狀態，⛔ 絕不會因此重新派單（`isPostSubmit()` 為 true，
             * 上面的守衛已擋掉回到送出前狀態）。
             */
            self::Submitted, self::Pending, self::Processing => in_array($target, [
                self::Pending,
                self::Processing,
                self::Completed,
                self::Partial,
                self::Canceled,
                self::Failed,
                self::SubmissionUnknown,
            ], true) && $target !== $this
                /*
                 * ⛔ `Submitted` **不在**上面的清單裡，這是刻意的。
                 *
                 * `Submitted` 的意思是「還沒問過對方」——那是一件一旦發生就
                 * 回不去的事實。從 `Pending`／`Processing` 退回 `Submitted`
                 * 等於宣稱我們從來沒問過，會讓後台以為輪詢還沒開始。
                 * ⛔ 而且 `Submitted` 也不是 provider 會回的 token（見
                 * `syncableTargets()`）。
                 */
                && $target !== self::Submitted,

            default => false,
        };
    }

    /**
     * 可以拿來同步的來源狀態；⛔ 其餘一律不查詢也不改寫。
     *
     * @return list<self>
     */
    public static function syncableSources(): array
    {
        // ⭐ `Pending` 也必須可再輪詢：它是「對方說還在排隊」,不是終點。
        return [self::Submitted, self::Pending, self::Processing];
    }

    /**
     * A provider may only ever tell us one of these.
     *
     * ⛔ `ready`, `submitting`, `configuration_pending` and
     * `submission_unknown` are *our* words for *our* situation — no provider
     * can report them. Accepting one would let a malformed response rewind the
     * row into a submittable state.
     *
     * @return list<self>
     */
    public static function syncableTargets(): array
    {
        return [
            self::Submitted,
            self::Pending,
            self::Processing,
            self::Completed,
            self::Partial,
            self::Canceled,
            self::Failed,
        ];
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
