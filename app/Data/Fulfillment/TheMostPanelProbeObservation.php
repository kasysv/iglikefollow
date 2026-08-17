<?php

namespace App\Data\Fulfillment;

use App\Enums\TheMostPanelReadOnlyAction;

/**
 * What we are willing to learn from a provider response.
 *
 * ⛔ Shape, never content. The point of this milestone is to discover what the
 * response *looks like* — which fields exist, what types they hold — without
 * copying the provider's data into our systems. A service catalogue is
 * commercial information, a balance is financial, an order id identifies a
 * customer's purchase, and none of it is needed to write a parser.
 *
 * ⛔ There is no way to put a raw body into this object. Not a truncated one,
 * not an "only on error" one: the error cases are exactly where providers echo
 * back the request, and the request carries our API key.
 */
final class TheMostPanelProbeObservation
{
    /**
     * @param  string  $outcome  local allowlisted result code
     * @param  array<string, string>  $fieldTypes  field name => PHP type
     */
    private function __construct(
        public readonly TheMostPanelReadOnlyAction $action,
        public readonly string $outcome,
        public readonly ?int $httpStatus = null,
        public readonly ?string $topLevelType = null,
        public readonly array $fieldTypes = [],
        public readonly ?int $itemCount = null,
        /**
         * ⛔ A keyed HMAC, never a plain digest.
         *
         * The first version stored `hash('sha256', $body)` and the result
         * document called it irreversible. It is not: a `balance` response is
         * short and predictably formatted, so candidate values can be hashed
         * until one matches — a reviewer recovered one in about 1,200 guesses.
         * The name says `hmac` so nobody has to infer the difference.
         */
        public readonly ?string $bodyFingerprint = null,
        public readonly ?int $elapsedMs = null,
    ) {}

    /**
     * A response we could read.
     *
     * @param  array<string, string>  $fieldTypes
     */
    public static function observed(
        TheMostPanelReadOnlyAction $action,
        int $httpStatus,
        string $topLevelType,
        array $fieldTypes,
        ?int $itemCount,
        string $bodyFingerprint,
        int $elapsedMs,
    ): self {
        return new self(
            $action,
            'observed',
            $httpStatus,
            $topLevelType,
            $fieldTypes,
            $itemCount,
            $bodyFingerprint,
            $elapsedMs,
        );
    }

    /**
     * We refused before sending anything.
     *
     * ⛔ Distinct from a failure: nothing left this machine, so there is no
     * question about what the provider did or did not receive.
     */
    public static function blocked(TheMostPanelReadOnlyAction $action, string $reason): self
    {
        return new self($action, $reason);
    }

    /**
     * The request went out and did not come back usable.
     *
     * ⛔ `$outcome` comes from our own allowlist. A provider's error text is the
     * single most likely place to find our own key echoed back, so it is never
     * stored, never logged and never shown.
     */
    public static function failed(
        TheMostPanelReadOnlyAction $action,
        string $reason,
        ?int $httpStatus = null,
        ?int $elapsedMs = null,
    ): self {
        return new self($action, $reason, $httpStatus, elapsedMs: $elapsedMs);
    }

    public function isObserved(): bool
    {
        return $this->outcome === 'observed';
    }

    /**
     * A form safe to print or paste into an evidence document.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'action' => $this->action->value,
            'outcome' => $this->outcome,
            'http_status' => $this->httpStatus,
            'top_level_type' => $this->topLevelType,
            'field_types' => $this->fieldTypes ?: null,
            'item_count' => $this->itemCount,
            // ⛔ 名稱明說是 HMAC：普通 SHA-256 對短回應可被枚舉還原。
            'body_hmac_sha256' => $this->bodyFingerprint,
            'elapsed_ms' => $this->elapsedMs,
        ], fn ($value) => $value !== null);
    }
}
