<?php

namespace App\Http\Controllers;

use App\Actions\Orders\FindOrdersForCustomer;
use App\Models\Order;
use App\Support\PublicOrderPresenter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The public, account-free order lookup.
 *
 * ⭐ Owner 規則：訂單編號、Email、手機**任選兩項**。三項都填時三項都要相符。
 *
 * ⭐ 獨立工具頁 `/order-check`：`GET` 顯示空表單，`POST` 在**同一個 URL**
 * 直接 render 結果。
 *
 * ⛔ 類別名稱保留 `OrderLookupController`。URL 換了，但這個類別做的事沒變，
 * 而它與 `FindOrdersForCustomer`／`PublicOrderPresenter`／HMAC domain 的
 * `order-lookup` 命名一致——為了跟 URL 對齊而連鎖改名，只會讓那個**不可改動**
 * 的密碼學 domain 常數看起來像是漏改的。
 *
 * ⛔ 結果**直接 render**，⛔ 不 redirect。
 *
 * redirect 會把查詢條件推進 URL 或 session flash——Email 與手機一旦進了 URL，
 * 就會留在瀏覽器歷史、referrer header 與沿途每一個 proxy log 裡。這是本輪
 * 最容易犯、也最難收回的錯誤。
 *
 * ⛔ 查無、任一不符與不存在使用**完全相同**的通用訊息：分開的訊息等於一個
 * oracle，可以用來確認「這個 Email 有沒有在本站下過單」。
 */
class OrderLookupController extends Controller
{
    /**
     * The empty form.
     *
     * ⭐ Owner 指定的獨立工具頁 `/order-check`。
     *
     * ⛔ GET 也必須 noindex。這一頁本身沒有客人資料，但它是一個「輸入 Email
     * 與手機」的入口——讓它進搜尋結果只會讓人以為那是官方帳號查詢頁而被釣魚
     * 模仿，對本站也沒有任何流量價值。route 的 `NeverIndex` middleware 與這裡
     * 的 header 兩層都設。
     */
    public function show(): Response
    {
        return response()
            ->view('storefront.order-check', [
                'results' => [],
                // ⛔ 初次進站不是「查無」——那會讓還沒查的人以為自己查過了。
                'notFound' => false,
                'submitted' => false,
            ])
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function lookup(Request $request, FindOrdersForCustomer $finder): Response
    {
        /*
         * ⛔ 基本形狀驗證只用來擋明顯無效的輸入,⛔ 不回報「哪一項錯」。
         *
         * 這裡刻意不使用 FormRequest 的自動錯誤回傳(那會 redirect 並把輸入
         * 放進 session flash),而是自己判斷後直接 render 通用結果。
         */
        /*
         * ⛔ R1：把**原始輸入**原樣交給 action，⛔ 不先轉成 `?string`。
         *
         * 初版在這裡把非字串（array／object）轉成 null，於是一個型別不對的
         * 第三欄會被當成「沒填」而從 AND 條件中消失——那正是 bypass 的一半。
         * action 需要看到「有提供但無效」與「沒提供」的差別。
         */
        $orders = $finder->handle(
            $request->input('reference'),
            $request->input('email'),
            $request->input('phone'),
        );

        /*
         * ⭐ allowlist presenter:公開頁只會拿到它明確列出的欄位。
         *
         * ⛔ 不把 Order model 直接丟給 view——那等於讓 Blade 有機會取用
         * 任何欄位,包括 Email、手機、交付目標與 provider 資料。
         */
        $results = $orders->map(fn (Order $order): array => PublicOrderPresenter::for($order))->all();

        return response()
            ->view('storefront.order-check', [
                'results' => $results,
                /*
                 * ⛔ 通用訊息:查無、條件不符、少於兩項——全部同一句。
                 * 分開的訊息可以被用來逐一確認某個 Email 是否存在。
                 */
                'notFound' => $results === [],
                'submitted' => true,
            ])
            /*
             * ⛔ 這一頁永遠不得被索引,也不得被任何快取層保存。
             *
             * `private` 擋掉共用快取(CDN／proxy),`no-store` 連瀏覽器本機
             * 都不寫入磁碟——結果頁含有客人的訂單內容。
             */
            ->header('Cache-Control', 'private, no-store, max-age=0, must-revalidate')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
