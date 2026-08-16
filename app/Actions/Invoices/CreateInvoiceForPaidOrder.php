<?php

namespace App\Actions\Invoices;

use App\Enums\IntegrationEnvironment;
use App\Enums\IntegrationProvider;
use App\Enums\InvoiceStatus;
use App\Models\IntegrationSetting;
use App\Models\Invoice;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Create the single invoice record for an order that has been paid.
 *
 * Idempotent by construction: the row is claimed inside a transaction with the
 * order locked, and `invoices.order_id` is unique, so a redelivered queue job
 * or a repeated OrderPaid event finds the existing invoice instead of making a
 * second one. Both guards are deliberate — the lock avoids a confusing
 * constraint violation in the common case, and the constraint is what actually
 * makes it impossible.
 *
 * ⛔ An unpaid order never gets an invoice. Issuing a tax document for money
 * that has not arrived is worse than issuing none.
 */
class CreateInvoiceForPaidOrder
{
    public function handle(Order $order): Invoice
    {
        return DB::transaction(function () use ($order) {
            $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isPaid()) {
                throw new RuntimeException(
                    "訂單 {$locked->reference} 尚未付款，不得開立發票。"
                );
            }

            $existing = Invoice::query()->where('order_id', $locked->id)->first();

            if ($existing !== null) {
                return $existing; // ⛔ 一張訂單只開一次。
            }

            $currency = $locked->currency ?: 'TWD';

            // ⛔ 只開立台幣發票：其他幣別的稅務規則完全不同，不得猜測。
            if ($currency !== 'TWD') {
                throw new RuntimeException(
                    "訂單 {$locked->reference} 的幣別為 {$currency}，目前只支援 TWD 電子發票。"
                );
            }

            $raw = $locked->total_amount;

            // ⛔ 金額必須是正整數台幣，且就是實際收到的錢；不同金額是稅務問題。
            if (! is_int($raw) && ! ctype_digit((string) $raw)) {
                throw new RuntimeException(
                    "訂單 {$locked->reference} 的金額不是整數，無法開立發票。"
                );
            }

            $amount = (int) $raw;

            if ($amount <= 0) {
                throw new RuntimeException(
                    "訂單 {$locked->reference} 的金額為 {$amount}，不是有效的發票金額。"
                );
            }

            // 快照與訂單總額必須一致：開出的金額只能是客人真的付掉的那一筆。
            $itemTotal = (int) $locked->items()->sum('amount');

            if ($itemTotal > 0 && $itemTotal !== $amount) {
                throw new RuntimeException(
                    "訂單 {$locked->reference} 的商品金額合計 {$itemTotal} 與訂單總額 {$amount} 不符。"
                );
            }

            return Invoice::create([
                'order_id' => $locked->id,
                'provider' => IntegrationProvider::EcpayInvoice->value,
                // credential 還沒設定就停在這個狀態；⛔ 不是失敗，也不重試。
                'status' => $this->gatewayIsConfigured()
                    ? InvoiceStatus::Pending
                    : InvoiceStatus::PendingConfiguration,
                'amount' => $amount,
                'currency' => $currency,
            ]);
        });
    }

    /**
     * Is there a usable ECPay invoice credential set?
     *
     * ⛔ Checked before anything is queued, so a site with no credentials
     * records an honest "not configured yet" instead of retrying forever
     * against a provider it cannot authenticate to.
     */
    private function gatewayIsConfigured(): bool
    {
        foreach (IntegrationProvider::EcpayInvoice->environments() as $environment) {
            $setting = IntegrationSetting::query()
                ->where('provider', IntegrationProvider::EcpayInvoice)
                ->where('environment', $environment)
                ->first();

            if ($setting?->isUsable()) {
                return true;
            }
        }

        return false;
    }

    /** 測試與 adapter 需要知道目前該用哪個環境。 */
    public static function activeEnvironment(): ?IntegrationEnvironment
    {
        foreach (IntegrationProvider::EcpayInvoice->environments() as $environment) {
            $setting = IntegrationSetting::query()
                ->where('provider', IntegrationProvider::EcpayInvoice)
                ->where('environment', $environment)
                ->first();

            if ($setting?->isUsable()) {
                return $environment;
            }
        }

        return null;
    }
}
