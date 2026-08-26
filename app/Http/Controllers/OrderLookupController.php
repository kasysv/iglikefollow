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
 * ⛔ POST only，結果**直接 render**，⛔ 不 redirect。
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
    public function __invoke(Request $request, FindOrdersForCustomer $finder): Response
    {
        /*
         * ⛔ 基本形狀驗證只用來擋明顯無效的輸入,⛔ 不回報「哪一項錯」。
         *
         * 這裡刻意不使用 FormRequest 的自動錯誤回傳(那會 redirect 並把輸入
         * 放進 session flash),而是自己判斷後直接 render 通用結果。
         */
        $reference = $request->input('reference');
        $email = $request->input('email');
        $phone = $request->input('phone');

        $orders = $finder->handle(
            is_string($reference) ? $reference : null,
            is_string($email) ? $email : null,
            is_string($phone) ? $phone : null,
        );

        /*
         * ⭐ allowlist presenter:公開頁只會拿到它明確列出的欄位。
         *
         * ⛔ 不把 Order model 直接丟給 view——那等於讓 Blade 有機會取用
         * 任何欄位,包括 Email、手機、交付目標與 provider 資料。
         */
        $results = $orders->map(fn (Order $order): array => PublicOrderPresenter::for($order))->all();

        return response()
            ->view('storefront.order-lookup', [
                'results' => $results,
                /*
                 * ⛔ 通用訊息:查無、條件不符、少於兩項——全部同一句。
                 * 分開的訊息可以被用來逐一確認某個 Email 是否存在。
                 */
                'notFound' => $results === [],
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
