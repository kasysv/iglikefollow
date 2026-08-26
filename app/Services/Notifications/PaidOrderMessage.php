<?php

namespace App\Services\Notifications;

use App\Enums\PaymentStatus;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Models\Order;
use App\Models\OrderItem;

/**
 * The LINE message for one newly-paid order.
 *
 * ⛔ 這則訊息是給 **Owner** 看的，不是給客人——所以它含有 Email 與電話。
 * 但它仍然**不含**任何供應商資訊：TheMostPanel 名稱、SMM service ID、
 * 成本、API 細節都不進去。Owner 手機上的 LINE 訊息會停留在通知列、
 * 訊息備份與（若是群組）每一位成員的裝置上。
 */
final class PaidOrderMessage
{
    /**
     * ⛔ 付款方式是**封閉映射**，⛔ 不顯示 provider 原文。
     *
     * 原文是我們與金流商之間的識別字串；把它直接印出來，等於讓一個內部值
     * 的任何改動都直接出現在 Owner 的訊息裡。
     */
    private const PAYMENT_LABELS = [
        // ⛔ 這兩個 key 是 gateway 的 `key()` 實際回傳值，已逐一核對過：
        // `EcpayPaymentGateway::key()` → 'ecpay'
        // `LinePayGateway::key()`      → 'line-pay'（連字號，不是底線）
        'ecpay' => '綠界付款',
        'line-pay' => 'LINE Pay',
    ];

    public static function for(Order $order): string
    {
        $lines = [];

        $lines[] = '【IGNF 訂單通知】新訂單';
        $lines[] = '----------------------------';
        $lines[] = '訂購時間: '.self::placedAt($order);
        $lines[] = '訂購電郵: '.(string) $order->customer_email;
        $lines[] = '訂單電話: '.self::phone($order);
        $lines[] = '付款方式: '.self::paymentLabel($order);
        $lines[] = '訂單金額: '.number_format((int) $order->total_amount).' 元';
        $lines[] = '';
        $lines[] = '----------------------------';
        $lines[] = '訂單編號: '.(string) $order->reference;
        $lines[] = '訂購項目:';

        foreach ($order->items as $item) {
            $lines[] = self::itemLine($item);
        }

        $lines[] = '';
        $lines[] = '訂單連結: '.self::adminUrl($order);

        return implode("\n", $lines);
    }

    /**
     * ⛔ 固定轉 `Asia/Taipei`。
     *
     * Owner 會拿這個時間跟後台、跟客人的說法對照。差 8 小時的時間會讓他
     * 以為看到的是另一張訂單。
     */
    private static function placedAt(Order $order): string
    {
        $createdAt = $order->created_at;

        if ($createdAt === null) {
            return '未知';
        }

        return $createdAt->copy()->setTimezone('Asia/Taipei')->format('Y-m-d H:i:s');
    }

    /** ⛔ 沒填就明說「未填寫」，⛔ 不留空白——空白看起來像資料掉了。 */
    private static function phone(Order $order): string
    {
        $phone = (string) $order->customer_phone;

        return trim($phone) === '' ? '未填寫' : $phone;
    }

    /**
     * The payment method, from the successful attempt.
     *
     * ⛔ 未知 provider 顯示固定的「其他」，⛔ 不顯示原文、⛔ 也不猜。
     */
    private static function paymentLabel(Order $order): string
    {
        $provider = $order->paymentAttempts()
            ->where('status', PaymentStatus::Succeeded)
            ->orderByDesc('id')
            ->value('provider');

        if (! is_string($provider)) {
            return '其他';
        }

        return self::PAYMENT_LABELS[strtolower($provider)] ?? '其他';
    }

    /** `- 平台｜服務｜方案 × 數量` */
    private static function itemLine(OrderItem $item): string
    {
        return sprintf(
            '- %s｜%s｜%s × %s',
            (string) $item->platform_name,
            (string) $item->service_name,
            (string) $item->variant_label,
            number_format((int) $item->quantity),
        );
    }

    /**
     * The absolute admin URL for this order.
     *
     * ⛔⛔ 由版本控制內的 Filament route 產生，⛔ 絕不從 request 的 Host
     * header 或任何客戶輸入拼接。
     *
     * queue job 執行時根本沒有 HTTP request——Host header 在那裡不存在；
     * 而若真的去讀它，一個偽造 Host 的請求就能讓 Owner 收到一個指向攻擊者
     * 網站的「訂單連結」，而他有充分理由相信那是自己的後台。
     * ⛔ `route(..., absolute: true)` 用的是 `config('app.url')`。
     */
    private static function adminUrl(Order $order): string
    {
        return ViewOrder::getUrl(['record' => $order->reference], isAbsolute: true);
    }
}
