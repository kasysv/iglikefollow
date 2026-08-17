<?php

namespace App\Services\Fulfillment;

/**
 * Can this runtime actually stop an oversized transfer?
 *
 * ⛔ The bounded sink limits what we *store*, not what we *receive*. On
 * libcurl 7.85.0 — the version this machine runs — throwing from the write
 * callback does not end the request: it keeps going until the 15s timeout,
 * with the remote free to send as much as it likes in the meantime. A cap that
 * only bounds memory while the connection stays open is not a transport cap.
 *
 * libcurl documents the fix precisely: `CURLOPT_MAXFILESIZE_LARGE` was not
 * applied to an ongoing transfer before 8.4.0, and is from 8.4.0 onward.
 * <https://curl.se/libcurl/c/CURLOPT_MAXFILESIZE_LARGE.html>
 *
 * So the version is a gate, not a preference. Below it the probe refuses
 * before reading a credential or building a request — ⛔ never "the sink plus
 * a timeout is close enough", because that is the arrangement already
 * disproven.
 *
 * ⛔ Injectable on purpose. Tests must be able to describe a supported or
 * unsupported runtime without altering the PHP installation they run on.
 */
class TheMostPanelCurlCapability
{
    /** libcurl 8.4.0，以 curl_version() 的 version_number 表示（0x080400）。 */
    public const MINIMUM_VERSION_NUMBER = 0x080400;

    public const MINIMUM_VERSION = '8.4.0';

    public function __construct(
        private readonly bool $extensionLoaded,
        private readonly bool $maxFileSizeOptionExists,
        private readonly int $versionNumber,
        private readonly string $versionString,
    ) {}

    /** 讀取真實 runtime；⛔ production path 只用這一個。 */
    public static function fromRuntime(): self
    {
        if (! extension_loaded('curl') || ! function_exists('curl_version')) {
            return new self(false, false, 0, 'unavailable');
        }

        $version = curl_version() ?: [];

        return new self(
            extensionLoaded: true,
            maxFileSizeOptionExists: defined('CURLOPT_MAXFILESIZE_LARGE'),
            versionNumber: (int) ($version['version_number'] ?? 0),
            versionString: (string) ($version['version'] ?? 'unknown'),
        );
    }

    /** 測試用：明確描述一個支援的 runtime。 */
    public static function supported(string $versionString = self::MINIMUM_VERSION): self
    {
        return new self(true, true, self::MINIMUM_VERSION_NUMBER, $versionString);
    }

    /** 測試用：明確描述一個不支援的 runtime。 */
    public static function unsupported(
        string $versionString = '7.85.0',
        int $versionNumber = 0x075500,
        bool $extensionLoaded = true,
        bool $optionExists = true,
    ): self {
        return new self($extensionLoaded, $optionExists, $versionNumber, $versionString);
    }

    /**
     * ⛔ 三個條件必須同時成立。
     *
     * 常數存在不代表它會生效——7.85.0 也定義了 `CURLOPT_MAXFILESIZE_LARGE`，
     * 只是不會套用到進行中的傳輸。所以版本必須另外檢查。
     */
    public function supportsOngoingTransferCap(): bool
    {
        return $this->extensionLoaded
            && $this->maxFileSizeOptionExists
            && $this->versionNumber >= self::MINIMUM_VERSION_NUMBER;
    }

    /** ⛔ 只回傳版本字串，不含任何 provider 或請求資料。 */
    public function versionString(): string
    {
        return $this->versionString;
    }
}
