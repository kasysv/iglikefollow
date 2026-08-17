<?php

namespace App\Data\Fulfillment;

/**
 * What one catalog sync attempt did — in terms safe to print anywhere.
 *
 * ⛔ Outcome, applied flag, HTTP status and elapsed time. Nothing else can be
 * put in here: no service id, name, category or rate, no raw body, no
 * exception text, no credential. The console prints this verbatim, so this
 * class is the allowlist.
 */
final class ProviderServiceCatalogSyncResult
{
    public const APPLIED = 'catalog_applied';

    private function __construct(
        /** 本地 allowlisted code；⛔ 從不是 provider 原文。 */
        public readonly string $outcome,
        public readonly bool $applied,
        public readonly ?int $httpStatus = null,
        public readonly ?int $elapsedMs = null,
    ) {}

    public static function applied(?int $httpStatus, ?int $elapsedMs): self
    {
        return new self(self::APPLIED, true, $httpStatus, $elapsedMs);
    }

    /** 任何未套用的結局：blocked、transport／HTTP 失敗、parser 拒絕、apply 失敗。 */
    public static function refused(string $outcome, ?int $httpStatus = null, ?int $elapsedMs = null): self
    {
        return new self($outcome, false, $httpStatus, $elapsedMs);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'outcome' => $this->outcome,
            'catalog_applied' => $this->applied,
            'http_status' => $this->httpStatus,
            'elapsed_ms' => $this->elapsedMs,
        ], fn ($value) => $value !== null);
    }
}
