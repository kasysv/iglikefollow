/*
 * 前台試算的可執行回歸：`node resources/js/quantity-total.test.js`
 *
 * ⛔ 直接 import 正式程式碼,不複製第二套算法——複製出來的那一套一定會
 * 與正式版漂移,而漂移的那一份沒有人在測。
 *
 * ⛔ 期望值是「後端 exact 整數規則」的答案,不是「現行前端剛好算出什麼」。
 */

import { totalTwd, formatTotalTwd } from './quantity-total.js';

let failures = 0;
let checks = 0;

function is(actual, expected, label) {
    checks++;

    const a = actual === null ? 'null' : actual.toString();
    const e = expected === null ? 'null' : expected.toString();

    if (a === e) {
        console.log(`  ok   ${label}`);
    } else {
        failures++;
        console.error(`  FAIL ${label}\n       期望 ${e}\n       實際 ${a}`);
    }
}

console.log('\n1. Owner 指定案例（0.59／個 = 5900 mills）');
is(totalTwd('5900', '100'), 59n, '0.59 × 100 = 59');
is(totalTwd('5900', '101'), 60n, '0.59 × 101 = 59.59 → 60');
is(totalTwd('5900', '1000'), 590n, '0.59 × 1000 = 590');

console.log('\n2. half-up 邊界（餘數 4999 捨去 / 5000 進位）');
is(totalTwd('14999', '1'), 1n, '餘數 4999 → 捨去');
is(totalTwd('15000', '1'), 2n, '餘數 5000 → 進位');
is(totalTwd('25000', '1'), 3n, '2.5 → 3（⛔ banker’s 會給 2）');
is(totalTwd('45000', '1'), 5n, '4.5 → 5（⛔ banker’s 會給 4）');
is(totalTwd('5', '1000'), 1n, '0.0005 × 1000 = 0.5 → 1');
is(totalTwd('15', '1000'), 2n, '0.0015 × 1000 = 1.5 → 2');

console.log('\n3. GPT 的大數反例（初版 Number 版本會多算 NT$1）');
/*
 * mills 1,000,000,005 × 4,000,000,973 = 4,000,000,993,000,004,865
 * 餘數 4,865 < 5,000 → 必須捨去。乘積遠大於 Number.MAX_SAFE_INTEGER
 * (9,007,199,254,740,991)，Number 版本在這裡就失真了。
 */
is(
    totalTwd('1000000005', '4000000973'),
    400000099300000n,
    'GPT 反例：餘數 4865 必須捨去',
);

// 同一組數字往上挪一格：餘數剛好 5000 → 必須進位。
is(totalTwd('1000000005', '4000000000'), 400000002000000n, '大數：餘數 0 不進位');

// 再證一次乘積確實超過 safe integer 範圍。
is(
    BigInt('1000000005') * BigInt('4000000973') > BigInt(Number.MAX_SAFE_INTEGER),
    true,
    '反例乘積確實超過 Number.MAX_SAFE_INTEGER',
);

console.log('\n4. 與後端 64-bit 上限一致地 fail closed');
/*
 * ⛔ 這一條是交叉比對後端時抓到的：BigInt 本身算得動欄位上限的乘積
 * （999,999,999,999 mills × 4,294,967,295 = 4,294,967,294,995,705,032,705），
 * 但那個乘積超過 PHP_INT_MAX，後端 `Money::multiply()` 會拋例外拒絕交易。
 * 前端若「算得出來」就會再次與後端不一致，只是方向相反，因此必須同樣回 null。
 */
is(
    totalTwd('999999999999', '4294967295'),
    null,
    '乘積超過 PHP 64-bit 上限 → 與後端一致地拒絕',
);

// 剛好在上限之內的最大情形仍必須算得出來。
is(totalTwd('9223372036854775807', '1'), 922337203685478n, 'PHP_INT_MAX 本身仍可計算');
is(totalTwd('9223372036854775807', '2'), null, '超過一格就拒絕');

console.log('\n5. 無效輸入必須回 null（⛔ 不得變成 0，也不得丟例外）');
for (const [rate, qty, label] of [
    ['5900', '0', '數量 0'],
    ['5900', '-1', '負數量'],
    ['0', '100', '零單價'],
    ['-5900', '100', '負單價'],
    ['5900', '1.5', '非整數數量'],
    ['5900', '1e3', '指數記法'],
    ['5900', '', '空字串'],
    ['5900', 'abc', '非數字'],
    ['5900', null, 'null'],
    ['5900', undefined, 'undefined'],
    ['5900', NaN, 'NaN'],
    ['5900', Infinity, 'Infinity'],
    ['1', '1', '四捨五入後為 0（0.0001 元）'],
]) {
    is(totalTwd(rate, qty), null, `${label} → null`);
}

console.log('\n6. 顯示層');
is(formatTotalTwd('5900', '101'), '60', '格式化正常值');
is(formatTotalTwd('5900', '1000000'), '590,000', '千分位');
is(formatTotalTwd('5900', 'abc'), '—', '無效輸入顯示 fallback');
is(formatTotalTwd('5900', '0', 'N/A'), 'N/A', '自訂 fallback');

console.log(`\n${checks - failures}/${checks} 通過`);

if (failures > 0) {
    console.error(`⛔ ${failures} 個失敗`);
    process.exit(1);
}

console.log('全部通過。\n');
