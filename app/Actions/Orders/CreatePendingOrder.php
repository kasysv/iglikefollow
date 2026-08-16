<?php

namespace App\Actions\Orders;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\PaymentAttempt;
use App\Models\ServiceVariant;
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
        // 金額一律在這裡依「當下」單價重算，⛔ 不讀前端送來的 price／amount。
        $amount = $variant->amountFor($quantity);

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
