<?php

namespace App\Actions\Orders;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\UnsellablePriceException;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\PaymentAttempt;
use App\Models\ServiceVariant;
use App\Support\ContactLookupHash;
use Illuminate\Support\Facades\DB;

/**
 * Create the local order the moment checkout validates.
 *
 * The order and its first payment attempt are written together in one
 * transaction, before any payment provider is contacted, so an abandoned or
 * failed payment still leaves a record that support can look up.
 *
 * Money is recomputed here from the published variant. Nothing the browser
 * submitted about price or amount is read.
 */
class CreatePendingOrder
{
    /**
     * @param  array<string, mixed>  $contact  validated contact and invoice fields
     */
    public function handle(
        ServiceVariant $variant,
        int $quantity,
        array $contact,
        string $checkoutToken,
        string $provider,
    ): Order {
        // ⛔ 最後一道防線：即使有人直接改 DB 或繞過後台驗證寫入不可販售的設定，
        // 也不得在這裡建立訂單。amountFor() 本身會擋下負數、零與 overflow，
        // 這裡再確認數量真的買得到，避免任何一條路徑漏掉。
        if (! $variant->quantityIsValid($quantity)) {
            throw new UnsellablePriceException(
                "服務項目 #{$variant->id} 目前無法以數量 {$quantity} 建立訂單。"
            );
        }

        // 金額一律在這裡依「當下」單價重算，⛔ 不讀前端送來的 price／amount。
        $amount = $variant->amountFor($quantity);

        if ($amount <= 0) {
            throw new UnsellablePriceException("應付金額必須大於 0，計算結果為 {$amount}。");
        }

        return DB::transaction(function () use ($variant, $quantity, $contact, $checkoutToken, $provider, $amount) {
            $order = Order::create([
                'reference' => Order::newReference(),
                // ⛔ DB unique constraint 是防重複建單的最終保障。
                'checkout_token' => $checkoutToken,
                'order_status' => OrderStatus::PendingPayment,
                'payment_status' => PaymentStatus::Initiated,
                'total_amount' => $amount,
                'currency' => $variant->currency ?: 'TWD',
                'customer_email' => $contact['customer_email'],
                'customer_phone' => $contact['customer_phone'] ?? null,
                /*
                 * ⭐ 免會員訂單查詢用的 keyed lookup hash。
                 *
                 * ⛔ 與訂單在**同一個 transaction** 內寫入：分開寫會出現一段
                 * 「訂單已存在但查不到」的時間窗，而客人付完款的第一件事往往
                 * 就是去查訂單。
                 *
                 * ⛔ 沒有手機時 phone hash 為 null（手機是選填欄位）。
                 */
                'customer_email_lookup_hash' => ContactLookupHash::forEmail($contact['customer_email']),
                'customer_phone_lookup_hash' => ContactLookupHash::forPhone($contact['customer_phone'] ?? null),
                'invoice_kind' => $contact['invoice_kind'],
                'personal_invoice_mode' => $contact['personal_invoice_mode'] ?? null,
                'carrier_number' => $contact['carrier_number'] ?? null,
                'love_code' => $contact['love_code'] ?? null,
                'buyer_tax_id' => $contact['buyer_tax_id'] ?? null,
                'buyer_name' => $contact['buyer_name'] ?? null,
            ]);

            $service = $variant->service;

            // 商品快照：⛔ 全部複製，日後改價／改名／下架都不影響這張訂單。
            $order->items()->create([
                'service_variant_id' => $variant->id,
                'platform_name' => $service->platform->name,
                'service_name' => $service->name,
                'variant_label' => $variant->label,
                'sku' => $variant->sku,
                'external_sku' => $variant->external_sku,
                // ⛔ 保存完整四位小數精度；分為單位會把 0.1234 截成 0.12。
                'unit_price_mills' => $variant->unitPriceMills(),
                // legacy 欄位在 rollback window 內同步維護，⛔ 讓回退後的舊程式仍讀得到值。
                'unit_price_cents' => intdiv($variant->unitPriceMills(), 100),
                'quantity' => $quantity,
                'quantity_unit' => $variant->quantity_unit,
                'amount' => $amount,
                'target_kind' => $service->input_kind,
                'target_value' => $contact['target'],
            ]);

            $order->paymentAttempts()->create([
                'provider' => $provider,
                'reference' => PaymentAttempt::newReference(),
                'status' => PaymentStatus::Initiated,
                'amount' => $amount,
                'currency' => $order->currency,
            ]);

            $order->events()->create([
                'type' => OrderEvent::TYPE_ORDER_CREATED,
                'summary' => '結帳驗證通過，訂單建立為待付款。',
            ]);

            return $order->fresh(['items', 'paymentAttempts', 'events']);
        });
    }
}
