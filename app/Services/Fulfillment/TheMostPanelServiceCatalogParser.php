<?php

namespace App\Services\Fulfillment;

use App\Data\Fulfillment\TheMostPanelServiceDefinition;
use App\Exceptions\TheMostPanelCatalogParseException;
use JsonException;
use stdClass;

/**
 * Strict, fail-closed reader for a `services` response body.
 *
 * The shape comes from the provider's public Docs example: a JSON list of
 * objects with nine fields. That example is a contract *sample*, not a schema
 * guarantee — so everything the sample demonstrates is enforced exactly, extra
 * fields are ignored as forward-compatibility, and anything else rejects the
 * entire response.
 *
 * ⛔ All-or-nothing on purpose. A partial import would mean the site quietly
 * acts on half a catalog while believing it has all of it; a rejected response
 * keeps the previous known-good state instead.
 *
 * ⛔ No error may carry the input. Reasons come from a local allowlist and name
 * at most a documented field — a malformed body is precisely the case where a
 * provider echoes the request back, key included.
 */
class TheMostPanelServiceCatalogParser
{
    /**
     * ⛔ Our own conservative ceiling, not a provider number. Nobody here has
     * seen a real catalog; the cap exists so a hostile or broken response
     * cannot expand into unbounded rows.
     */
    public const MAX_ITEMS = 5_000;

    public const MAX_NAME_CHARS = 255;

    public const MAX_SERVICE_TYPE_CHARS = 100;

    public const MAX_CATEGORY_CHARS = 255;

    public const MAX_RATE_CHARS = 64;

    public const MAX_QUANTITY_CHARS = 64;

    /** 文件範例的九個必要欄位。 */
    private const REQUIRED_FIELDS = [
        'service', 'name', 'type', 'category', 'rate', 'min', 'max', 'refill', 'cancel',
    ];

    /**
     * @return list<TheMostPanelServiceDefinition>
     *
     * @throws TheMostPanelCatalogParseException
     */
    public function parse(string $rawBody): array
    {
        /*
         * ⛔ 上限沿用傳輸層的同一個數字，並在 decode 之前檢查：
         * json_decode 一個超大字串本身就是要避免的事。
         */
        if (strlen($rawBody) > TheMostPanelResponseSizeGuard::MAX_BODY_BYTES) {
            throw TheMostPanelCatalogParseException::because(
                TheMostPanelCatalogParseException::BODY_TOO_LARGE
            );
        }

        if (! mb_check_encoding($rawBody, 'UTF-8')) {
            throw TheMostPanelCatalogParseException::because(
                TheMostPanelCatalogParseException::INVALID_UTF8
            );
        }

        try {
            /*
             * ⛔ 不用 assoc：stdClass 才能嚴格區分「JSON object」與「JSON
             * list」。assoc 模式下空物件 {} 與空陣列 [] 無法分辨。
             * 深度上限 8：文件範例是 list of flat objects，更深就是別的東西。
             */
            $decoded = json_decode($rawBody, false, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            // ⛔ JsonException 的訊息可能引用輸入片段，不得外流。
            throw TheMostPanelCatalogParseException::because(
                TheMostPanelCatalogParseException::MALFORMED_JSON
            );
        }

        if (! is_array($decoded)) {
            throw TheMostPanelCatalogParseException::because(
                TheMostPanelCatalogParseException::TOP_LEVEL_NOT_LIST
            );
        }

        if ($decoded === []) {
            // ⛔ 空 list 不是「帳戶沒有服務」的證明，是拒收：接受它會讓一次
            // 壞回應把整個 catalog 標成不可用。
            throw TheMostPanelCatalogParseException::because(
                TheMostPanelCatalogParseException::EMPTY_LIST
            );
        }

        if (count($decoded) > self::MAX_ITEMS) {
            throw TheMostPanelCatalogParseException::because(
                TheMostPanelCatalogParseException::TOO_MANY_ITEMS
            );
        }

        $definitions = [];
        $seenIds = [];

        foreach ($decoded as $item) {
            $definition = $this->parseItem($item);

            if (isset($seenIds[$definition->providerServiceId])) {
                // ⛔ 同一個 ID 出現兩次，代表無法判斷哪一筆是真的：整份拒絕。
                throw TheMostPanelCatalogParseException::because(
                    TheMostPanelCatalogParseException::DUPLICATE_SERVICE_ID
                );
            }

            $seenIds[$definition->providerServiceId] = true;
            $definitions[] = $definition;
        }

        return $definitions;
    }

    private function parseItem(mixed $item): TheMostPanelServiceDefinition
    {
        if (! $item instanceof stdClass) {
            throw TheMostPanelCatalogParseException::because(
                TheMostPanelCatalogParseException::ITEM_NOT_OBJECT
            );
        }

        foreach (self::REQUIRED_FIELDS as $field) {
            if (! property_exists($item, $field)) {
                throw TheMostPanelCatalogParseException::because(
                    TheMostPanelCatalogParseException::MISSING_FIELD,
                    $field
                );
            }
        }

        // ⛔ 額外未知欄位到此為止：不驗證、不保存、不出現在任何輸出。

        $min = $this->quantity($item->min, 'min');

        return new TheMostPanelServiceDefinition(
            providerServiceId: $this->serviceId($item->service),
            name: $this->text($item->name, 'name', self::MAX_NAME_CHARS),
            serviceType: $this->text($item->type, 'type', self::MAX_SERVICE_TYPE_CHARS),
            category: $this->text($item->category, 'category', self::MAX_CATEGORY_CHARS),
            rateRaw: $this->rate($item->rate),
            minimumQuantityRaw: $min,
            maximumQuantityRaw: $this->boundedQuantity($item->max, $min),
            supportsRefill: $this->boolean($item->refill, 'refill'),
            supportsCancel: $this->boolean($item->cancel, 'cancel'),
        );
    }

    /**
     * ⛔ Only a positive-integer JSON *number*. `is_int` alone does the heavy
     * lifting: floats (including `1.0` and scientific notation) and integers
     * beyond PHP's range decode as float, strings decode as string — all
     * rejected. Zero and negatives are not identifiers.
     */
    private function serviceId(mixed $value): string
    {
        if (! is_int($value) || $value < 1) {
            throw TheMostPanelCatalogParseException::because(
                TheMostPanelCatalogParseException::INVALID_SERVICE_ID,
                'service'
            );
        }

        return (string) $value;
    }

    private function text(mixed $value, string $field, int $maxChars): string
    {
        if (! is_string($value)) {
            throw TheMostPanelCatalogParseException::because(
                TheMostPanelCatalogParseException::WRONG_TYPE,
                $field,
                $this->jsonType($value)
            );
        }

        if (trim($value) === '') {
            throw TheMostPanelCatalogParseException::because(
                TheMostPanelCatalogParseException::BLANK_TEXT,
                $field
            );
        }

        // ⛔ 超長就拒絕，不默默截斷：截斷後的名稱看起來像另一個服務。
        if (mb_strlen($value, 'UTF-8') > $maxChars) {
            throw TheMostPanelCatalogParseException::because(
                TheMostPanelCatalogParseException::TEXT_TOO_LONG,
                $field
            );
        }

        // C0 控制字元與 DEL；換行、tab 也在內——名稱裡不該有任何一個。
        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw TheMostPanelCatalogParseException::because(
                TheMostPanelCatalogParseException::CONTROL_CHARACTERS,
                $field
            );
        }

        return $value;
    }

    /**
     * Non-negative plain decimal string, kept verbatim.
     *
     * ⛔ No exponents, signs, commas, NaN/Infinity, leading zeros or trailing
     * dot — and never a cast to float. The pattern *is* the normalization:
     * anything that doesn't already read as a canonical decimal is refused
     * rather than reinterpreted.
     */
    private function rate(mixed $value): string
    {
        if (! is_string($value)) {
            throw TheMostPanelCatalogParseException::because(
                TheMostPanelCatalogParseException::WRONG_TYPE,
                'rate',
                $this->jsonType($value)
            );
        }

        if (
            strlen($value) > self::MAX_RATE_CHARS
            || preg_match('/\A(0|[1-9][0-9]*)(\.[0-9]+)?\z/', $value) !== 1
        ) {
            throw TheMostPanelCatalogParseException::because(
                TheMostPanelCatalogParseException::INVALID_RATE,
                'rate'
            );
        }

        return $value;
    }

    /**
     * Non-negative quantity, normalized to a canonical digit string.
     *
     * Accepted since B4-C-C-A: a canonical digit string (unchanged original
     * contract), or a non-negative JSON integer — B4-C-B's single live probe
     * proved the provider sends `min` as a JSON integer, and this validator
     * is shared by `min` and `max`, which the public Docs list as the same
     * quantity pair. The integer is canonicalized immediately: a PHP
     * non-negative int casts to exactly `0` or a no-leading-zero digit
     * string, so everything downstream — the 64-digit overflow-safe bounds
     * comparison, the DTO, the DB — still sees only strings.
     *
     * ⛔ Still rejected: negative integers (a value problem, not a type
     * problem), floats including `10.0` and scientific notation, numbers
     * beyond PHP's integer range (JSON-decoded as float), boolean, null,
     * object, list, and every non-canonical string. No other field accepts
     * an integer.
     */
    private function quantity(mixed $value, string $field): string
    {
        if (is_int($value)) {
            if ($value < 0) {
                throw TheMostPanelCatalogParseException::because(
                    TheMostPanelCatalogParseException::INVALID_QUANTITY,
                    $field
                );
            }

            return (string) $value;
        }

        if (! is_string($value)) {
            throw TheMostPanelCatalogParseException::because(
                TheMostPanelCatalogParseException::WRONG_TYPE,
                $field,
                $this->jsonType($value)
            );
        }

        if (
            strlen($value) > self::MAX_QUANTITY_CHARS
            || preg_match('/\A(0|[1-9][0-9]*)\z/', $value) !== 1
        ) {
            throw TheMostPanelCatalogParseException::because(
                TheMostPanelCatalogParseException::INVALID_QUANTITY,
                $field
            );
        }

        return $value;
    }

    private function boundedQuantity(mixed $value, string $min): string
    {
        $max = $this->quantity($value, 'max');

        if ($this->digitStringExceeds($min, $max)) {
            throw TheMostPanelCatalogParseException::because(
                TheMostPanelCatalogParseException::QUANTITY_BOUNDS_INVERTED,
                'max'
            );
        }

        return $max;
    }

    /**
     * Is $a > $b, for canonical digit strings of any length?
     *
     * ⛔ Never a cast to int: a 64-digit quantity overflows PHP integers and a
     * float comparison would silently accept an inverted pair. Both inputs are
     * already canonical (no leading zeros), so longer means larger and equal
     * length falls back to byte order.
     */
    private function digitStringExceeds(string $a, string $b): bool
    {
        if (strlen($a) !== strlen($b)) {
            return strlen($a) > strlen($b);
        }

        return strcmp($a, $b) > 0;
    }

    private function boolean(mixed $value, string $field): bool
    {
        // ⛔ 只接受 JSON boolean："true"、1、"yes" 都是 contract drift 的訊號。
        if (! is_bool($value)) {
            throw TheMostPanelCatalogParseException::because(
                TheMostPanelCatalogParseException::WRONG_TYPE,
                $field,
                $this->jsonType($value)
            );
        }

        return $value;
    }

    /**
     * The safe JSON type of a decoded value — the diagnostic added after
     * B4-B's live rejection could only say "wrong type" without saying which.
     *
     * ⛔ Local type checks only, mapping to the exception's closed seven-code
     * vocabulary. Never `get_debug_type()`, a class name or the value itself.
     * The parser decodes with assoc=false, so a JSON object is `stdClass` and
     * a PHP array can only mean a JSON list; any other internal type has no
     * safe name and fails closed with a fixed message.
     */
    private function jsonType(mixed $value): string
    {
        return match (true) {
            is_string($value) => 'string',
            is_int($value) => 'integer',
            is_float($value) => 'float',
            is_bool($value) => 'boolean',
            $value === null => 'null',
            $value instanceof stdClass => 'object',
            is_array($value) => 'list',
            default => throw new \InvalidArgumentException('⛔ 無法安全分類的內部型別。'),
        };
    }
}
