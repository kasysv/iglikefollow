<?php

namespace App\Enums;

/**
 * The append-only timeline of a fulfilment row.
 *
 * ⛔ A closed set, so the timeline can never become a place where provider text
 * or customer data accumulates. Every row is one of these codes plus a
 * from/to status — enough to reconstruct what happened, and nothing that would
 * be unsafe to show in the admin.
 */
enum FulfillmentEventCode: string
{
    case Created = 'CREATED';
    case ConfigurationBlocked = 'CONFIGURATION_BLOCKED';
    case Ready = 'READY';
    case SubmissionClaimed = 'SUBMISSION_CLAIMED';
    case Submitted = 'SUBMITTED';
    case SubmissionRejected = 'SUBMISSION_REJECTED';
    case SubmissionUnknown = 'SUBMISSION_UNKNOWN';
    case StatusSynced = 'STATUS_SYNCED';
    case StatusUnrecognised = 'STATUS_UNRECOGNISED';

    public function label(): string
    {
        return match ($this) {
            self::Created => '建立履約紀錄',
            self::ConfigurationBlocked => '設定不完整，暫不派單',
            self::Ready => '設定完成，等待送出',
            self::SubmissionClaimed => '取得送出權',
            self::Submitted => '已送出並取得供應商單號',
            self::SubmissionRejected => '供應商拒絕',
            self::SubmissionUnknown => '送出結果不明，待人工對帳',
            self::StatusSynced => '同步供應商狀態',
            self::StatusUnrecognised => '供應商狀態無法辨識，維持原狀',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
