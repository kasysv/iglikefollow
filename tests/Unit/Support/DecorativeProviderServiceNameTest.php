<?php

namespace Tests\Unit\Support;

use App\Support\DecorativeProviderServiceName;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Pure string → bool. No DB, no HTTP — the single source of truth both
 * mapping dropdowns and the submit-time rule call to agree on what counts as
 * a decorative/category-header catalog row rather than a real service.
 */
class DecorativeProviderServiceNameTest extends TestCase
{
    /** @return array<string, array{0: string, 1: bool}> */
    public static function nameProvider(): array
    {
        return [
            // ⛔ 施工單指定的最低反例。
            '純長橫線列' => ['————————————', true],
            '頭尾裝飾包標題' => ['—————————— 頂級系列 ——————————', true],
            '正常服務名稱' => ['Instagram 台灣頂級粉絲（男性） - 30天補粉', false],

            // 其他裝飾字元變體。
            '等號列' => ['===========', true],
            '底線列' => ['___________', true],
            '波浪號列' => ['~~~~~~~~~~~', true],
            '半形連字號列' => ['-----------', true],
            '中間點列' => ['···········', true],
            '純空白' => ['   ', false],
            '空字串' => ['', false],
            'null' => [null, false],

            // 正常名稱不得誤判：單一連字號／破折號是合法內容的一部分。
            '單一連字號在中間' => ['30天補粉 - 加購', false],
            '括號說明' => ['IG 讚 (自然流量)', false],
            '一般虛構服務名稱' => ['虛構測試服務 123', false],
            '短橫線但不成列' => ['A-B', false],
            '純字母' => ['A', false],
            '純數字' => ['12345', false],
        ];
    }

    #[DataProvider('nameProvider')]
    public function test_matches_correctly_classifies_decorative_names(?string $name, bool $expected): void
    {
        $this->assertSame($expected, DecorativeProviderServiceName::matches($name));
    }
}
