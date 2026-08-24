/*
 * 前台試算：單價（mills）× 數量 → 整數台幣，half-up。
 *
 * ⛔ 這份必須與 PHP `Money::total()` 逐位一致。GPT 在 R1 複審找到的反例
 * 證明 Number 版本做不到：mills 1,000,000,005 × 數量 4,000,000,973 的精確
 * 乘積是 4,000,000,993,000,004,865，餘數 4,865 應該捨去，但 Number 只有
 * 53 bit 尾數，乘積早已超過 Number.MAX_SAFE_INTEGER，算出來反而進位，
 * 試算因此比實際付款多 NT$1。
 *
 * 所以這裡：
 *   - 單價一律以「十進位字串」從後端傳來，⛔ 不經 PHP float、不 parseFloat；
 *   - 乘法與取餘全部用 BigInt，⛔ 不用 Number 乘法，也不用 Math.round()
 *     決定金額（Math.round 吃 Number，正是精度會消失的地方）；
 *   - half-up 用 `remainder * 2n >= 10000n`，與後端同一條式子。
 *
 * ⛔ 這只是試算。實際付款金額永遠由伺服器重算，前端送來的任何金額一律忽略。
 *
 * 沒有任何 DOM 依賴，Node 可直接載入同一份程式碼跑回歸，⛔ 避免測試複製
 * 另一套算法而與正式程式碼漂移。
 */

/** 1 TWD = 10,000 mills，與 PHP `Money::SCALE` 相同。 */
export const SCALE = 10000n;

/**
 * PHP 的 64-bit 整數上限。
 *
 * ⛔ BigInt 沒有上限，但後端有：`Money::multiply()` 在乘積超出 PHP 整數範圍
 * 時會拋 `UnsellablePriceException` 而拒絕交易。若前端「算得出來」而後端拒絕，
 * 前後端就再次不一致——只是方向相反。所以這裡用同一條界線 fail closed，
 * ⛔ 不因為 BigInt 算得動，就多回答一個伺服器根本不接受的金額。
 */
const PHP_INT_MAX = 9223372036854775807n;

/**
 * 把後端傳來的值轉成 BigInt，無法安全轉換就回 null。
 *
 * ⛔ 只接受由十進位數字組成的字串（或已經是 BigInt / 安全整數 Number）。
 * 空字串、小數點、指數記法、Infinity、NaN 一律拒絕——把 "1e3" 或 "1.5"
 * 猜成某個整數，就是在金額上替使用者做決定。
 */
function toBigInt(value) {
    if (typeof value === 'bigint') {
        return value;
    }

    if (typeof value === 'number') {
        return Number.isSafeInteger(value) ? BigInt(value) : null;
    }

    if (typeof value !== 'string') {
        return null;
    }

    const trimmed = value.trim();

    if (!/^-?\d+$/.test(trimmed)) {
        return null;
    }

    return BigInt(trimmed);
}

/**
 * 應付金額（整數台幣），half-up；無法計算時回 null。
 *
 * 回 null 的情況與後端拒絕的情況一致：單價或數量不是正整數，或四捨五入後
 * 不足 1 元。⛔ 呼叫端必須把 null 當成「無法試算」顯示，不得代換成 0。
 *
 * @param {string|bigint|number} unitPriceMills 單價，單位 mills
 * @param {string|bigint|number} quantity 數量
 * @returns {bigint|null}
 */
export function totalTwd(unitPriceMills, quantity) {
    const rate = toBigInt(unitPriceMills);
    const qty = toBigInt(quantity);

    if (rate === null || qty === null) {
        return null;
    }

    // ⛔ 與後端相同：免費、負價、零或負數量都不是可付款的組合。
    if (rate <= 0n || qty <= 0n) {
        return null;
    }

    const mills = rate * qty;

    // ⛔ 後端會在這裡溢位並拒絕交易；前端必須跟著拒絕，不得多算一個答案。
    if (mills > PHP_INT_MAX) {
        return null;
    }

    const whole = mills / SCALE;
    const remainder = mills % SCALE;

    // ⛔ half-up，不是 banker's rounding：剛好 .5 一律進位。
    const dollars = remainder * 2n >= SCALE ? whole + 1n : whole;

    // ⛔ 四捨五入後不足 1 元：後端會拒絕，前端也不得顯示成 0 元。
    return dollars < 1n ? null : dollars;
}

/**
 * 給畫面用的字串；無法計算時回 fallback。
 *
 * ⛔ 用 `toLocaleString('en-US')` 明確指定 locale：預設 locale 會隨瀏覽器
 * 改變千分位樣式，讓同一筆金額在不同裝置上長得不一樣。
 */
export function formatTotalTwd(unitPriceMills, quantity, fallback = '—') {
    const total = totalTwd(unitPriceMills, quantity);

    return total === null ? fallback : total.toLocaleString('en-US');
}
