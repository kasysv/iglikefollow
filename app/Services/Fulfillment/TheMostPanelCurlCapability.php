<?php

namespace App\Services\Fulfillment;

/**
 * Can this runtime actually stop an oversized transfer?
 *
 * ⛔ R1(curl 7.68):答案改變了,因為機制改變了。傳輸中的中止現在由 bounded
 * sink 的 **short write** 完成——Guzzle 的 managed `CURLOPT_WRITEFUNCTION`
 * 把 `sink->write()` 的回傳值交還給 libcurl,回傳量小於收到量,libcurl 就以
 * write error 中止。這是 libcurl 的基本協定,任何有 ext-curl 的 runtime 都
 * 支援——staging 實測的 7.68.0 也是。(⛔ 舊版「從 write callback 拋例外
 * 不會結束請求」的觀察是對 throw 而言;short write 是 libcurl 自己的中止
 * 協定,不同機制。)
 *
 * 舊的 `>= 8.4.0` 門檻對應的是 `CURLOPT_MAXFILESIZE_LARGE` 能否套用到
 * 進行中的傳輸;那不是 TheMostPanel 的 API 要求,short write 之後它只是
 * 額外的保險層,⛔ 不得再作為把正常派單永久關閉的能力硬閘。
 *
 * ⛔ ext-curl 缺失仍然 fail closed:沒有 curl 就沒有 write callback,也就
 * 沒有任何可執行的傳輸上限。
 *
 * ⛔ Injectable on purpose. Tests must be able to describe a runtime with or
 * without ext-curl, instead of inheriting whatever the test machine has.
 */
class TheMostPanelCurlCapability
{
    public function __construct(
        private readonly bool $extensionLoaded,
        private readonly string $versionString,
    ) {}

    /** 讀取真實 runtime；⛔ production path 只用這一個。 */
    public static function fromRuntime(): self
    {
        if (! extension_loaded('curl') || ! function_exists('curl_version')) {
            return new self(false, 'unavailable');
        }

        $version = curl_version() ?: [];

        return new self(
            extensionLoaded: true,
            versionString: (string) ($version['version'] ?? 'unknown'),
        );
    }

    /** 測試用：明確描述一個具備 ext-curl 的 runtime(如 staging 的 7.68.0)。 */
    public static function supported(string $versionString = '7.68.0'): self
    {
        return new self(true, $versionString);
    }

    /**
     * 測試用：明確描述一個**沒有 ext-curl** 的 runtime。
     *
     * ⛔ R1 之後「不支援」只剩這一種:short write 不挑 libcurl 版本,
     * 唯一擋住它的是 curl 擴充根本不存在。
     */
    public static function unsupported(): self
    {
        return new self(false, 'unavailable');
    }

    /**
     * 這個 runtime 可不可以在傳輸途中截停超大 response。
     *
     * ⛔ 條件就是 ext-curl 存在:bounded sink 的 short write 走 Guzzle 管理的
     * write callback,任何 libcurl 版本都會因 short write 中止(已由
     * localhost fixture 的真實 cURL 整合測試證明)。⛔ 不再檢查版本門檻——
     * 那個門檻只對應 `CURLOPT_MAXFILESIZE_LARGE` 的額外保險層,用它擋下
     * 正常派單,是拿保險層冒充必要條件。
     */
    public function supportsOngoingTransferCap(): bool
    {
        return $this->extensionLoaded;
    }

    /** ⛔ 只回傳版本字串，不含任何 provider 或請求資料。 */
    public function versionString(): string
    {
        return $this->versionString;
    }
}
