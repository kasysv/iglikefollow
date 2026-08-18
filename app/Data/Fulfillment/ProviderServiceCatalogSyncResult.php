<?php

namespace App\Data\Fulfillment;

use App\Exceptions\TheMostPanelCatalogParseException;

/**
 * What one catalog sync attempt did — in terms safe to print anywhere.
 *
 * ⛔ Outcome, applied flag, HTTP status, elapsed time — and, for a parser
 * rejection only, the parser's own allowlisted local reason and documented
 * field name. Nothing else can be put in here: no service id, name, category
 * or rate, no raw body, no exception text, no credential. The console prints
 * this verbatim, so this class is the allowlist.
 *
 * ⛔ The parser diagnostics have exactly one entrance: a typed factory that
 * accepts the parse exception itself. B3 taught why the fields must exist —
 * the orchestrator threw the safe reason away, and afterwards nobody could
 * say whether the response was a non-list, a missing field or a wrong type.
 * They still enter only through the exception, whose own constructor already
 * enforces both allowlists; this class re-validates anyway and drops rather
 * than prints anything unknown.
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
        /** parser 的本地 allowlisted reason；只可經 rejectedByParser() 進入。 */
        public readonly ?string $parserReason = null,
        /** 文件九欄位之一；⛔ 永遠不是 provider 值。 */
        public readonly ?string $parserField = null,
        /** 七碼 JSON type 之一；只有 WRONG_TYPE 可帶，⛔ 永遠不是 provider 值。 */
        public readonly ?string $parserObservedType = null,
    ) {}

    public static function applied(?int $httpStatus, ?int $elapsedMs): self
    {
        return new self(self::APPLIED, true, $httpStatus, $elapsedMs);
    }

    /**
     * A parser rejection, with the parser's own safe diagnosis attached.
     *
     * ⛔ Only this factory can set the diagnostic fields, and it accepts only
     * the typed exception — an arbitrary string has no way in. The values are
     * re-checked against the exception's own allowlists; anything unknown is
     * dropped, not printed.
     */
    public static function rejectedByParser(
        TheMostPanelCatalogParseException $exception,
        ?int $httpStatus = null,
        ?int $elapsedMs = null,
    ): self {
        $reason = TheMostPanelCatalogParseException::isAllowlistedReason($exception->reason)
            ? $exception->reason
            : null;

        $field = $exception->field !== null
            && TheMostPanelCatalogParseException::isDocumentedField($exception->field)
            ? $exception->field
            : null;

        /*
         * ⛔ Observed type 只在完整合法組合下通過:reason 恰為 WRONG_TYPE、
         * field 是文件九欄位之一、type 在七碼 allowlist 內。任何一項不符
         * 就丟棄,不印。
         */
        $observedType = $reason === TheMostPanelCatalogParseException::WRONG_TYPE
            && $field !== null
            && $exception->observedType !== null
            && TheMostPanelCatalogParseException::isAllowlistedObservedType($exception->observedType)
            ? $exception->observedType
            : null;

        return new self('catalog_rejected_by_parser', false, $httpStatus, $elapsedMs, $reason, $field, $observedType);
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
            'parser_reason' => $this->parserReason,
            'parser_field' => $this->parserField,
            'parser_observed_type' => $this->parserObservedType,
        ], fn ($value) => $value !== null);
    }
}
