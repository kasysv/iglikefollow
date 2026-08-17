<?php

namespace App\Data\Fulfillment;

use Closure;
use RuntimeException;

/**
 * The outcome of one `services` fetch, with the raw body held privately.
 *
 * ⛔ The body exists in memory only, and can be taken exactly once. The single
 * legitimate reader is the CATALOG-A parser; a body that could be read twice
 * would eventually be read a second time by a logger, a serializer or a debug
 * dump. After `consumeBody()` the reference is dropped.
 *
 * ⛔ Held behind a Closure rather than a string property, so `print_r`,
 * `var_export` and property reflection dumps show an opaque closure instead
 * of the provider's data. `__debugInfo()` shows `[redacted]`, serialization
 * refuses outright, and `json_encode` sees only the public safe fields. There
 * is no `toArray()`, no `JsonSerializable`, no `__toString()`.
 *
 * ⛔ Failure results carry a local allowlisted outcome, HTTP status and
 * elapsed time only — never a body fragment, provider message, request
 * payload or credential.
 */
final class TheMostPanelCatalogFetchResult
{
    public const FETCHED = 'fetched';

    /** ⛔ 一次性持有 raw body 的封套；取用後立即歸 null。 */
    private ?Closure $body;

    private function __construct(
        public readonly string $outcome,
        ?string $rawBody = null,
        public readonly ?int $httpStatus = null,
        public readonly ?int $elapsedMs = null,
    ) {
        $this->body = $rawBody === null ? null : fn (): string => $rawBody;
    }

    public static function fetched(string $rawBody, int $httpStatus, int $elapsedMs): self
    {
        return new self(self::FETCHED, $rawBody, $httpStatus, $elapsedMs);
    }

    /** 在送出任何東西之前就被閘門擋下；⛔ 沒有留下任何可疑問的狀態。 */
    public static function blocked(string $reason): self
    {
        return new self($reason);
    }

    /** 已送出但不可用；`$reason` 一律是本地 allowlisted code。 */
    public static function failed(string $reason, ?int $httpStatus = null, ?int $elapsedMs = null): self
    {
        return new self($reason, null, $httpStatus, $elapsedMs);
    }

    public function wasFetched(): bool
    {
        return $this->outcome === self::FETCHED;
    }

    /**
     * The raw body, exactly once.
     *
     * ⛔ Throws rather than returning null on a second call: a caller that
     * silently got an empty body would feed it to the parser and record a
     * "malformed response" that never happened.
     */
    public function consumeBody(): string
    {
        if (! $this->wasFetched()) {
            throw new RuntimeException('⛔ 沒有成功取得的 body 可供取用。');
        }

        if ($this->body === null) {
            throw new RuntimeException('⛔ raw body 只能取用一次，已被取用並清空。');
        }

        $body = ($this->body)();
        $this->body = null;

        return $body;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'outcome' => $this->outcome,
            'http_status' => $this->httpStatus,
            'elapsed_ms' => $this->elapsedMs,
            'body' => '[redacted]',
        ];
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        // ⛔ 序列化就是落盤的第一步：queue、cache、session 全走這裡。
        throw new RuntimeException('⛔ catalog fetch result 不得序列化。');
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new RuntimeException('⛔ catalog fetch result 不得反序列化。');
    }
}
