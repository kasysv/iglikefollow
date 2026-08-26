<?php

namespace App\Actions\Orders;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Support\ContactLookupHash;
use Illuminate\Support\Collection;

/**
 * Find a customer's own orders from any two of three identifiers.
 *
 * ⭐ Owner 規則：訂單編號、Email、手機**任選兩項**即可查詢；三項都填時三項
 * 都必須相符。
 *
 * ⛔ 為什麼是兩項而不是一項：單靠訂單編號，任何拿到（或猜到）編號的人就能看到
 * 訂單；單靠 Email 則可以拿別人的 Email 去試。要求兩項相符大幅提高門檻，
 * 同時不強迫客人註冊帳號。
 *
 * ⭐ 只回傳**付款成功**的訂單（`Paid` ＋ `Succeeded`）。等待付款的單即使
 * 三選二完全相符也一律 no-match——這道限制在 SQL 層,見 `handle()`。
 *
 * ⛔ 這個 action 決定「哪些訂單可以被看到」,但**不決定**每張訂單要顯示什麼
 * 欄位——那是 `PublicOrderPresenter` 的 allowlist 職責。
 */
class FindOrdersForCustomer
{
    /**
     * ⛔ 上限：避免一個 Email 對應數百張訂單時做出無界查詢。
     *
     * 排序固定為最新在前，讓同一組輸入每次都得到相同結果。
     */
    private const MAX_RESULTS = 20;

    /**
     * @return Collection<int, Order> 符合**所有**已提供條件的訂單
     */
    public function handle(mixed $reference, mixed $email, mixed $phone): Collection
    {
        /*
         * ⭐ R1 修正：先依**原始輸入**判斷哪些欄位「有提供」,再驗證。
         *
         * ⛔ 初版是先正規化、再數有幾個非 null——於是一個**無效的**第三欄
         * (例如 `IGL-%`、array、超長手機)會被正規化成 null 而**靜默消失**,
         * 剩下的兩項仍然成立,查詢照樣命中。那等於「填錯的欄位不算數」,
         * 把 AND 門檻悄悄降回兩項。
         *
         * 現在的規則：**任何已提供的欄位驗證失敗,整次查詢就是 no-match。**
         */
        $suppliedReference = self::isSupplied($reference);
        $suppliedEmail = self::isSupplied($email);
        $suppliedPhone = self::isSupplied($phone);

        $suppliedCount = (int) $suppliedReference + (int) $suppliedEmail + (int) $suppliedPhone;

        // ⛔ 至少兩項,⛔ 不退化成單項查詢——那正是這道門檻存在的理由。
        if ($suppliedCount < 2) {
            return collect();
        }

        $normalizedReference = $suppliedReference ? self::normalizeReference($reference) : null;
        $emailHash = $suppliedEmail ? ContactLookupHash::forEmail(is_string($email) ? $email : null) : null;
        $phoneHash = $suppliedPhone ? ContactLookupHash::forPhone(is_string($phone) ? $phone : null) : null;

        /*
         * ⛔ 已提供但驗證失敗 → 整次 no-match。
         *
         * ⛔ 回傳空集合而不是拋錯或給不同訊息:呼叫端對所有失敗都顯示同一句
         * 通用文案,否則「格式錯誤」與「查無資料」的差異就成了一個 oracle,
         * 可以用來探測某個 Email 是否存在。
         */
        if (($suppliedReference && $normalizedReference === null)
            || ($suppliedEmail && $emailHash === null)
            || ($suppliedPhone && $phoneHash === null)
        ) {
            return collect();
        }

        $reference = $normalizedReference;

        // ⛔ eager load：避免公開頁對每個商品項目各查一次履約列。
        $query = Order::query()->with(['items.fulfillmentOrder']);

        /*
         * ⭐ Owner 要求：等待付款的訂單**完全不要**出現在查詢結果。
         *
         * ⛔⛔ 這道限制必須在**排序與 limit 之前**加進 SQL,⛔ 不能查出來
         * 之後再由 Blade 隱藏。
         *
         * 理由不是效能,是正確性：`limit(20)` 是在 DB 端套用的。若先撈 20 筆
         * 再隱藏未付款的,一個有很多待付款訂單的客人會發現他真正付過款的訂單
         * 被那些 pending row 擠出了前 20 名——畫面上什麼都沒有,而他其實有單。
         * 「先撈後隱藏」在這裡是會吃掉結果的錯,不只是多做工。
         *
         * ⛔ 兩個條件都要:`order_status` 是本站訂單生命週期,`payment_status`
         * 是金流結果。只看其中一個,都可能讓一張還沒真正收到錢的單被當成
         * 「付款成功」顯示給客人——而卡片上的藥丸是寫死的固定字串。
         */
        $query
            ->where('order_status', OrderStatus::Paid)
            ->where('payment_status', PaymentStatus::Succeeded);

        /*
         * ⛔ 每一個「有填」的條件都必須相符（AND),不是任一相符(OR)。
         *
         * 三項都填時三項都要對。OR 會讓「訂單編號對、Email 錯」也查得到,
         * 等於把兩項門檻降回一項。
         */
        if ($reference !== null) {
            $query->where('reference', $reference);
        }

        if ($emailHash !== null) {
            $query->where('customer_email_lookup_hash', $emailHash);
        }

        if ($phoneHash !== null) {
            $query->where('customer_phone_lookup_hash', $phoneHash);
        }

        return $query
            // ⛔ 確定排序＋上限：同一組輸入每次結果相同,且不做無界查詢。
            ->orderByDesc('id')
            ->limit(self::MAX_RESULTS)
            ->get();
    }

    /**
     * Did the customer actually fill this field in?
     *
     * ⛔ 「有提供」與「有效」是兩件不同的事,必須分開判斷——這正是初版
     * bypass 的來源。
     *
     * ⛔ 非字串(array／object／數字)也算「有提供」:那代表有人送了一個
     * 我們不預期的型別,必須讓它導致 no-match,而不是被當成「沒填」而忽略。
     * 空字串與純空白才算沒填。
     */
    private static function isSupplied(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        if (is_string($value)) {
            return trim($value) !== '';
        }

        // array、object、int、bool…—— 都是不預期的型別,視為有提供且無效。
        return true;
    }

    /**
     * The order reference in its canonical shape, or null.
     *
     * ⛔ 只接受本站 reference 的合法形狀(`IGL-` ＋ 12 個大寫英數)。
     *
     * ⛔ 不做模糊比對、不做前綴搜尋:那會讓人用一段前綴掃出一批訂單編號,
     * 把查詢變成枚舉工具。
     */
    public static function normalizeReference(mixed $reference): ?string
    {
        // ⛔ 非字串(array／object／數字)一律 null——由呼叫端轉成 no-match。
        if (! is_string($reference)) {
            return null;
        }

        $reference = strtoupper(trim($reference));

        return preg_match('/\AIGL-[A-Z0-9]{12}\z/', $reference) === 1 ? $reference : null;
    }
}
