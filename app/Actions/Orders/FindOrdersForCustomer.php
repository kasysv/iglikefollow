<?php

namespace App\Actions\Orders;

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
 * ⛔ 這個 action 只負責「找出符合的訂單」。它**不決定**要顯示什麼——那是
 * `PublicOrderPresenter` 的 allowlist 職責。
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
    public function handle(?string $reference, ?string $email, ?string $phone): Collection
    {
        $reference = self::normalizeReference($reference);
        $emailHash = ContactLookupHash::forEmail($email);
        $phoneHash = ContactLookupHash::forPhone($phone);

        /*
         * ⛔ 至少兩項。少於兩項一律回空集合,⛔ 不退化成單項查詢——那正是
         * 這道門檻存在的理由。
         */
        $provided = count(array_filter([$reference, $emailHash, $phoneHash], fn ($v) => $v !== null));

        if ($provided < 2) {
            return collect();
        }

        // ⛔ eager load：避免公開頁對每個商品項目各查一次履約列。
        $query = Order::query()->with(['items.fulfillmentOrder']);

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
     * The order reference in its canonical shape, or null.
     *
     * ⛔ 只接受本站 reference 的合法形狀(`IGL-` ＋ 12 個大寫英數)。
     *
     * ⛔ 不做模糊比對、不做前綴搜尋:那會讓人用一段前綴掃出一批訂單編號,
     * 把查詢變成枚舉工具。
     */
    public static function normalizeReference(?string $reference): ?string
    {
        if (! is_string($reference)) {
            return null;
        }

        $reference = strtoupper(trim($reference));

        return preg_match('/\AIGL-[A-Z0-9]{12}\z/', $reference) === 1 ? $reference : null;
    }
}
