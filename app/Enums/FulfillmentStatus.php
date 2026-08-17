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

    /** 對方明確接受，並回了 provider order ID。 */
    case Submitted = 'submitted';

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
            self::Submitted, self::Processing => 'info',
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

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
