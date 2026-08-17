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
        private readonly bool $neverSent = false,
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
            returnCode: is_scalar($json['returnCode'] ?? null) ? (string) $json['returnCode'] : '',
            // ⛔ 交易編號在 JSON 中可能是字串或數字，其他型別都代表回應
            // 不是我們認得的形狀。
            transactionId: self::identifier($info['transactionId'] ?? null),
            paymentUrl: self::text($payment['web'] ?? null),
            orderId: self::text($info['orderId'] ?? null),
            payInfoTotal: $total,
            payInfoIsValid: $valid,
        );
    }

    /**
     * An identifier from the response: a non-empty string, or an integer.
     *
     * ⛔ Anything else becomes null rather than being coerced. PHP turns an
     * array into the string "Array", which would sail through a `!== null`
     * check, be stored as the provider reference, and send the customer off to
     * pay — after which the confirm call quotes a transaction id that never
     * existed, and the payment can never be settled. The value looked present
     * at every step.
     */
    private static function identifier(mixed $value): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        return self::text($value);
    }

    /** A non-empty string, or null. ⛔ No coercion from other types. */
    private static function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
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
        // ⛔ 必須是非空的「list」：關聯陣列代表結構與官方文件不同，
        // 那就不是我們知道怎麼解讀的回應。
        if (! is_array($payInfo) || $payInfo === [] || ! array_is_list($payInfo)) {
            return [null, false];
        }

        $total = 0;

        foreach ($payInfo as $entry) {
            if (! is_array($entry) || ! array_key_exists('amount', $entry)) {
                return [null, false];
            }

            $amount = $entry['amount'];

            /*
             * ⛔ 必須是真正的 PHP int。
             *
             * 連 `590.0` 這種「剛好可以轉成整數」的 float 也拒絕：它代表對方
             * 送來的 JSON 結構跟我們以為的不一樣，而在錢的事情上，「形狀不對
             * 但湊得出數字」不能當成「數字正確」。
             */
            if (! is_int($amount) || $amount < 0) {
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
        return new self('', transportReason: PaymentFailureReason::ProviderUnavailable, neverSent: true);
    }

    /**
     * Did we fail before anything left this machine?
     *
     * ⛔ The distinction decides whether a retry is safe. If no request was
     * sent, the provider has no record and the customer may try again. If one
     * was sent and the answer was lost, they may already have a live payment,
     * and a retry risks a second one.
     */
    public function neverSent(): bool
    {
        return $this->neverSent;
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
     * ⛔ The mapping decides what the customer is told, so a merchant-side
     * mistake must never surface as "your card was declined". A shopper who
     * reads that will change cards, call their bank, and give up — while the
     * actual fault was our request header or our amount configuration.
     *
     * ⛔ Only codes with a current official meaning are classified. Anything
     * else stays Unknown and goes to reconciliation: not recognising a code is
     * not evidence that no money moved.
     *
     * Source: <https://developers-pay.line.me/online-api-v4/#result-code>
     */
    public function reason(): PaymentFailureReason
    {
        if ($this->transportReason !== null) {
            return $this->transportReason;
        }

        return match ($this->returnCode) {
            // 對方系統暫時無法服務。
            '1105' => PaymentFailureReason::ProviderUnavailable,

            /*
             * 我們這邊送錯了——header、金額或最低消費設定有問題。
             *
             * ⛔ 用 VerificationFailed 而非 AmountMismatch：後者被歸類為
             * 「結果不明」，那是為了 callback 情境（錢可能已經動了）。這裡是
             * request 被當場拒絕，確定沒有建立付款 session，屬於可安全重試的
             * 確定性失敗——留在待對帳只會把訂單鎖死。
             */
            '1104', '1106', '1124', '1183' => PaymentFailureReason::VerificationFailed,

            // 真正屬於客戶／付款方式被拒的代碼。
            '1101', '1102', '1110', '1142', '1298' => PaymentFailureReason::Declined,

            /*
             * ⛔ `1155`（invalid transaction ID）與 `1198`（API request
             * duplicated）刻意不分類。
             *
             * 先前把它們當成「金額不符」與「逾時」——那是猜的，官方定義並非
             * 如此。更重要的是，兩者都無法證明錢沒有移動：重複的請求可能是
             * 第一次就成功了。在沒有查詢 API 之前，安全的答案是「不知道」，
             * 交給人工對帳，⛔ 不自動重試。
             */
            default => PaymentFailureReason::Unknown,
        };
    }
}
