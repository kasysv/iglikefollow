<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Uri;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    /**
     * Keep a caller-supplied trailing slash instead of trimming it away.
     *
     * ⛔⛔ Laravel 原生的 `prepareUrlForRequest()` 做的是
     * `trim(url($uri), '/')`——它**永遠送不出尾斜線**。
     *
     * ⭐ 那一行造成兩個真實後果：
     *
     *  1. 既有 production code 曾被迫加上 `runningUnitTests()` 例外，
     *     ⛔ 讓尾斜線收斂**從來沒有被測到**；
     *  2. 47 處既有 `$this->get('/product/x/')` 實際送出的是 slashless，
     *     於是 M5A 正確的 301 被誤讀成迴歸。
     *
     * ⭐ R1 依施工單 §3.4 在**測試基礎設施**這一層修正，
     * ⛔ 而不是逐檔改 47 個呼叫、也不是把例外放回 production code。
     *
     * ⛔ 只保留呼叫者**明確寫出**的非 root 尾斜線；root（`/`）與
     * query string 的既有行為完全交回 parent 處理。
     */
    protected function prepareUrlForRequest($uri)
    {
        $raw = $uri instanceof Uri ? $uri->value() : $uri;

        $prepared = parent::prepareUrlForRequest($uri);

        if (! is_string($raw)) {
            return $prepared;
        }

        /*
         * ⛔ 只看 path 部分是否帶尾斜線：`/product/x/?a=1` 的尾斜線在
         * query 之前，⛔ 不能用整串 `str_ends_with($raw, '/')` 判斷。
         */
        $rawPath = (string) parse_url($raw, PHP_URL_PATH);

        if ($rawPath === '' || $rawPath === '/' || ! str_ends_with($rawPath, '/')) {
            return $prepared;
        }

        /*
         * ⭐ 把尾斜線加回 prepared URL 的 **path** 末端，
         * ⛔ 保留 parent 已經處理好的 query 與 fragment。
         */
        $parts = parse_url($prepared);

        if ($parts === false || ! isset($parts['path'])) {
            return $prepared;
        }

        if (str_ends_with($parts['path'], '/')) {
            return $prepared;
        }

        $rebuilt = (isset($parts['scheme']) ? $parts['scheme'].'://' : '')
            .($parts['host'] ?? '')
            .(isset($parts['port']) ? ':'.$parts['port'] : '')
            .$parts['path'].'/'
            .(isset($parts['query']) ? '?'.$parts['query'] : '')
            .(isset($parts['fragment']) ? '#'.$parts['fragment'] : '');

        return $rebuilt;
    }
}
