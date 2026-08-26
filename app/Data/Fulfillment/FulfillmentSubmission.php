<?php

namespace App\Data\Fulfillment;

/**
 * Everything a provider needs to place one order, and nothing else.
 *
 * ⛔ `$target` is the customer's account or post URL. It is read from the
 * encrypted order snapshot at the moment of submission and passed straight
 * through — it is never stored again, never logged, never put in a queue
 * payload, and never written to the fulfilment tables.
 *
 * ⛔ This object is deliberately not serializable into a job. Jobs carry
 * integer ids; a queue payload is written to storage, retried and often logged,
 * so a customer's account must not be able to reach one.
 */
final class FulfillmentSubmission
{
    public function __construct(
        public readonly string $providerServiceId,
        public readonly string $target,
        public readonly int $quantity,
        /**
         * ⭐ 這是第幾批（1 = 原始，2 以後 = Owner 建立的更換履約）。
         *
         * ⛔ 只用於 fingerprint 的區辨，⛔ **不送給供應商**——它是本站的
         * 內部概念，對方的 payload 裡沒有這個欄位。
         */
        public readonly int $sequenceNo = 1,
    ) {}

    /**
     * The inputs the fingerprint is computed from.
     *
     * ⛔ Excludes the target. The fingerprint is stored, and a keyed hash of a
     * short account name is still a hash of a customer's account — the service
     * id and quantity are enough to answer "was this the same call".
     *
     * ⭐ 加入 `sequence_no`：更換履約很可能用**同一個服務、同樣的數量**再送
     * 一次（例如整批重送）。少了這個區辨，第 2 批會算出與第 1 批**完全相同**
     * 的 fingerprint——任何把 fingerprint 當「這是不是重複請求」的判斷，
     * 都會把一次合法的更換誤判成重送。
     *
     * ⛔ 第 1 批的 `sequence_no` 恆為 1，因此既有列的 fingerprint 語意不變
     * （值本身會變，但那只影響「與過去的 hash 比對」，而系統只在同一列內
     * 前後比較）。
     */
    public function fingerprintInputs(): array
    {
        return [
            'provider_service_id' => $this->providerServiceId,
            'quantity' => $this->quantity,
            'sequence_no' => $this->sequenceNo,
        ];
    }
}
