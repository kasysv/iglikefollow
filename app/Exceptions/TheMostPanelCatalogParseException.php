<?php

namespace App\Exceptions;

use Exception;
use InvalidArgumentException;

/**
 * A `services` response we refused to accept.
 *
 * ⛔ Carries a local allowlisted reason and, at most, a *documented field
 * name* — never a field value, never a fragment of the body, never a provider
 * message. An exception message reaches logs and terminals, which is the
 * widest audience of any surface here, and a provider error body is exactly
 * where our own API key is most likely to be echoed back.
 */
final class TheMostPanelCatalogParseException extends Exception
{
    public const BODY_TOO_LARGE = 'catalog_body_too_large';

    public const INVALID_UTF8 = 'catalog_invalid_utf8';

    public const MALFORMED_JSON = 'catalog_malformed_json';

    public const TOP_LEVEL_NOT_LIST = 'catalog_top_level_not_list';

    public const EMPTY_LIST = 'catalog_empty_list';

    public const TOO_MANY_ITEMS = 'catalog_too_many_items';

    public const ITEM_NOT_OBJECT = 'catalog_item_not_object';

    public const MISSING_FIELD = 'catalog_missing_field';

    public const WRONG_TYPE = 'catalog_wrong_type';

    public const INVALID_SERVICE_ID = 'catalog_invalid_service_id';

    public const DUPLICATE_SERVICE_ID = 'catalog_duplicate_service_id';

    public const INVALID_RATE = 'catalog_invalid_rate';

    public const INVALID_QUANTITY = 'catalog_invalid_quantity';

    public const QUANTITY_BOUNDS_INVERTED = 'catalog_quantity_bounds_inverted';

    public const BLANK_TEXT = 'catalog_blank_text';

    public const CONTROL_CHARACTERS = 'catalog_control_characters';

    public const TEXT_TOO_LONG = 'catalog_text_too_long';

    /** @var list<string> */
    private const REASONS = [
        self::BODY_TOO_LARGE,
        self::INVALID_UTF8,
        self::MALFORMED_JSON,
        self::TOP_LEVEL_NOT_LIST,
        self::EMPTY_LIST,
        self::TOO_MANY_ITEMS,
        self::ITEM_NOT_OBJECT,
        self::MISSING_FIELD,
        self::WRONG_TYPE,
        self::INVALID_SERVICE_ID,
        self::DUPLICATE_SERVICE_ID,
        self::INVALID_RATE,
        self::INVALID_QUANTITY,
        self::QUANTITY_BOUNDS_INVERTED,
        self::BLANK_TEXT,
        self::CONTROL_CHARACTERS,
        self::TEXT_TOO_LONG,
    ];

    /**
     * ⛔ The only names allowed in a message: the nine documented example
     * fields. They come from the public Docs, not from any response.
     *
     * @var list<string>
     */
    private const FIELDS = [
        'service', 'name', 'type', 'category', 'rate', 'min', 'max', 'refill', 'cancel',
    ];

    /**
     * ⛔ The seven JSON types a decoded value can have — the entire safe
     * vocabulary for "what the field actually was". A type code describes
     * the JSON type and nothing else: no value, no length, no class name,
     * no body fragment.
     *
     * @var list<string>
     */
    private const OBSERVED_TYPES = [
        'string', 'integer', 'float', 'boolean', 'null', 'object', 'list',
    ];

    private function __construct(
        public readonly string $reason,
        public readonly ?string $field = null,
        /** JSON type code；只有 WRONG_TYPE 可帶，⛔ 從不是 provider 值。 */
        public readonly ?string $observedType = null,
    ) {
        // ⛔ 固定安全訊息：reason、欄位名與型別碼都出自本地 allowlist，
        // 沒有任何回應內容。
        parent::__construct(
            'TheMostPanel services 回應未通過本地驗證：'.$reason
            .($field !== null
                ? '（欄位 '.$field.($observedType !== null ? '，觀察型別 '.$observedType : '').'）'
                : '')
        );
    }

    public static function because(string $reason, ?string $field = null, ?string $observedType = null): self
    {
        if (! self::isAllowlistedReason($reason)) {
            // ⛔ Programmer error, failed closed: an unlisted reason could be
            // anything, including a value someone thought was safe to pass.
            throw new InvalidArgumentException('⛔ 不在 allowlist 中的 catalog parse reason。');
        }

        if ($field !== null && ! self::isDocumentedField($field)) {
            throw new InvalidArgumentException('⛔ 不在 allowlist 中的 catalog 欄位名。');
        }

        /*
         * ⛔ Observed type 的組合規則是 constructor factory 層的硬約束,
         * 不是慣例:WRONG_TYPE 必須同時帶 documented field 與 allowlisted
         * JSON type;其他 reason 一律不得帶 type。任意字串、class name、
         * 大小寫變體、控制字元與超長輸入全部在嚴格 in_array 就失敗。
         */
        if ($reason === self::WRONG_TYPE) {
            if ($field === null || $observedType === null || ! self::isAllowlistedObservedType($observedType)) {
                throw new InvalidArgumentException('⛔ WRONG_TYPE 必須帶文件欄位名與 allowlisted JSON type。');
            }
        } elseif ($observedType !== null) {
            throw new InvalidArgumentException('⛔ 只有 WRONG_TYPE 可帶 observed type。');
        }

        return new self($reason, $field, $observedType);
    }

    /**
     * ⛔ Typed safe accessors, so a downstream consumer can re-validate what
     * it is about to print without duplicating either allowlist. These expose
     * only *our* vocabulary — never a value from any response.
     */
    public static function isAllowlistedReason(string $reason): bool
    {
        return in_array($reason, self::REASONS, true);
    }

    public static function isDocumentedField(string $field): bool
    {
        return in_array($field, self::FIELDS, true);
    }

    public static function isAllowlistedObservedType(string $observedType): bool
    {
        return in_array($observedType, self::OBSERVED_TYPES, true);
    }
}
