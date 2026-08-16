<?php

namespace App\DTO;

use App\Enums\PaymentFailureReason;

/**
 * A LINE Pay API response, reduced to what is safe to act on.
 *
 * ⛔ The raw body never leaves this object. LINE Pay echoes back the order id
 * and amounts, and `returnMessage` is free text from their side; both the
 * message and the payload stay out of anything that gets persisted, so callers
 * see a local reason token instead.
 *
 * The fields kept are only those we independently verify against our own
 * record: transaction id, order id, and the total of `info.payInfo[]`.
 * Everything else is discarded at this boundary rather than filtered later.
 *
 * ⛔ There is no currency here, because the confirm response does not carry
 * one. Keeping a field the provider never sends would mean a check that never
 * runs — which is worse than no check, since it reads as though it does.
 */
final class LinePayResponse
{
    private function __construct(
        public readonly string $returnCode,
        public readonly ?string $transactionId = null,
        public readonly ?string $paymentUrl = null,
        public readonly ?string $orderId = null,
        public readonly ?int $payInfoTotal = null,
        public readonly bool $payInfoIsValid = false,
        public readonly ?PaymentFailureReason $transportReason = null,
    ) {}

    /** @param array<string, mixed> $json */
    public static function fromArray(array $json): self
    {
        $info = $json['info'] ?? [];
        $info = is_array($info) ? $info : [];

        $payment = $info['paymentUrl'] ?? [];
        $payment = is_array($payment) ? $payment : [];

        [$total, $valid] = self::sumPayInfo($info['payInfo'] ?? null);

        return new self(
            returnCode: (string) ($json['returnCode'] ?? ''),
            transactionId: isset($info['transactionId']) ? (string) $info['transactionId'] : null,
            paymentUrl: isset($payment['web']) ? (string) $payment['web'] : null,
            orderId: isset($info['orderId']) ? (string) $info['orderId'] : null,
            payInfoTotal: $total,
            payInfoIsValid: $valid,
        );
    }

    /**
     * Total the amounts LINE Pay reports it actually took.
     *
     * ⛔ The confirm response puts money in `info.payInfo[]`, one entry per
     * method — a payment split between LINE Pay and POINT arrives as two. There
     * is no `info.amount` to read, so a check written against one would simply
     * never run, and every confirm would pass the amount comparison by default.
     *
     * @return array{0: ?int, 1: bool} total, and whether the structure was sound
     */
    private static function sumPayInfo(mixed $payInfo): array
    {
        if (! is_array($payInfo) || $payInfo === []) {
            return [null, false];
        }

        $total = 0;

        foreach ($payInfo as $entry) {
            if (! is_array($entry) || ! array_key_exists('amount', $entry)) {
                return [null, false];
            }

            $amount = $entry['amount'];

            // ⛔ 只接受非負整數：字串、小數、null 與負數都代表結構不如預期，
            // 而「看不懂的金額」不能當成「金額正確」。
            if (! is_int($amount) && ! (is_float($amount) && floor($amount) === $amount)) {
                return [null, false];
            }

            $amount = (int) $amount;

            if ($amount < 0) {
                return [null, false];
            }

            // ⛔ 溢位前就停下，不讓總和變成 float。
            if ($amount > PHP_INT_MAX - $total) {
                return [null, false];
            }

            $total += $amount;
        }

        return [$total, true];
    }

    /** 連線失敗或逾時：⛔ 結果不明，不是失敗。 */
    public static function timeout(): self
    {
        return new self('', transportReason: PaymentFailureReason::Timeout);
    }

    public static function unreadable(): self
    {
        return new self('', transportReason: PaymentFailureReason::UnreadableResponse);
    }

    /** 沒有可用設定，根本沒有送出請求。 */
    public static function unavailable(): self
    {
        return new self('', transportReason: PaymentFailureReason::ProviderUnavailable);
    }

    /** LINE Pay 以 `0000` 表示成功。 */
    public function isSuccess(): bool
    {
        return $this->transportReason === null && $this->returnCode === '0000';
    }

    /** 沒有得到明確答案；⛔ 呼叫端必須進人工對帳。 */
    public function isUncertain(): bool
    {
        return $this->transportReason?->isUncertain() ?? false;
    }

    /**
     * Map a business return code to one of our own reasons.
     *
     * ⛔ Only a small set is classified. Anything else becomes Unknown and goes
     * to reconciliation rather than being recorded as a definite failure —
     * "we do not recognise this code" is not evidence that no money moved.
     */
    public function reason(): PaymentFailureReason
    {
        if ($this->transportReason !== null) {
            return $this->transportReason;
        }

        return match ($this->returnCode) {
            '1104', '1105' => PaymentFailureReason::ProviderUnavailable,
            '1106', '1124' => PaymentFailureReason::Declined,
            '1113', '1183' => PaymentFailureReason::Declined,
            '1155' => PaymentFailureReason::AmountMismatch,
            '1198' => PaymentFailureReason::Timeout,
            default => PaymentFailureReason::Unknown,
        };
    }
}
