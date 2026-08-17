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

    /** ⛔ 未知 source outcome 的固定降級碼。 */
    public const SOURCE_FAILED = 'catalog_source_failed';

    /**
     * Sync-layer outcomes of its own; everything else legal comes from the
     * fetch result's closed list.
     *
     * @var list<string>
     */
    private const SYNC_CODES = [
        'blocked_catalog_sync_disabled',
        'blocked_lock_unavailable',
        'blocked_sync_in_progress',
        'catalog_rejected_by_parser',
        'catalog_stale_refused',
        'catalog_apply_failed',
        self::SOURCE_FAILED,
    ];

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

    /**
     * Any not-applied ending: blocked, transport/HTTP failure, parser
     * rejection, apply failure.
     *
     * ⛔ The last line of defence before a terminal. The CLI prints this
     * object field by field, so an outcome outside the closed set — a buggy
     * or substituted source handing over provider text — is replaced with a
     * fixed local code here, in the object, not filtered somewhere upstream.
     */
    public static function refused(string $outcome, ?int $httpStatus = null, ?int $elapsedMs = null): self
    {
        $legal = in_array($outcome, self::SYNC_CODES, true)
            || in_array($outcome, TheMostPanelCatalogFetchResult::REFUSAL_CODES, true);

        return new self($legal ? $outcome : self::SOURCE_FAILED, false, $httpStatus, $elapsedMs);
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
