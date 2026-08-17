<?php

namespace App\Services\Invoices;

use App\Models\Invoice;
use RuntimeException;

/**
 * Build the ECPay B2C Issue request from the order snapshot, and nothing else.
 *
 * Every value comes from what was recorded at checkout. ⛔ Nothing is fetched
 * from another system at issuing time: the customer agreed to a specific set of
 * invoice details, and re-reading them later would risk issuing a tax document
 * that differs from what they saw.
 *
 * Two deliberate departures from the company's existing CMS module:
 *
 *  - a business invoice is `Print=0` with an email carrier, not `Print=1`.
 *    D-019 settled that this business issues no paper and posts nothing, and a
 *    printable invoice implies an address we neither collect nor have.
 *  - no address is sent at all, invented or otherwise.
 */
class EcpayInvoicePayloadBuilder
{
    /** 公司既有營運設定：一般稅額、應稅、含稅。 */
    private const INV_TYPE = '07';

    private const TAX_TYPE = '1';

    /**
     * 單一服務品項。
     *
     * ⛔ 不逐筆列出社群數量與小數單價：`0.59 元 × 1000` 這種組合會讓稅額
     * 在對方系統四捨五入後對不上總額。一項「式」的服務費用既真實，也讓
     * ItemPrice、ItemAmount 與 SalesAmount 必然一致。
     */
    private const ITEM_NAME = '行銷廣告費用';

    private const ITEM_WORD = '式';

    /**
     * @return array<string, mixed>
     */
    public function build(Invoice $invoice, string $merchantId, string $relateNumber): array
    {
        $order = $invoice->order;

        if ($order === null) {
            throw new RuntimeException('發票沒有對應的訂單，無法開立。');
        }

        $amount = $this->assertAmount($invoice, $order);
        $email = $this->assertEmail($order);

        $payload = [
            'MerchantID' => $merchantId,
            'RelateNumber' => $relateNumber,

            // ⛔ 一律無紙化：不列印、不郵寄、不收地址（D-019）。
            'Print' => '0',
            'CustomerAddr' => '',
            // checkout 已必填 Email，⛔ 手機不再傳給第三方。
            'CustomerPhone' => '',
            'CustomerEmail' => $email,

            'TaxType' => self::TAX_TYPE,
            'SalesAmount' => $amount,
            'InvType' => self::INV_TYPE,
            'vat' => '1',

            'Items' => [[
                'ItemName' => self::ITEM_NAME,
                'ItemCount' => 1,
                'ItemWord' => self::ITEM_WORD,
                'ItemPrice' => $amount,
                'ItemTaxType' => self::TAX_TYPE,
                'ItemAmount' => $amount,
            ]],
        ];

        return array_merge($payload, $this->buyerFields($order));
    }

    /**
     * Who the invoice is made out to, and how they receive it.
     *
     * @return array<string, string>
     */
    private function buyerFields($order): array
    {
        $kind = (string) $order->invoice_kind;

        if ($kind === 'business') {
            $taxId = (string) $order->buyer_tax_id;
            $name = (string) $order->buyer_name;

            // ⛔ 公司發票缺統編或抬頭就不能開：那是稅務憑證的必要內容。
            if (! preg_match('/^[0-9]{8}$/', $taxId) || trim($name) === '') {
                throw new RuntimeException('公司發票缺少統一編號或抬頭，無法開立。');
            }

            return [
                'CustomerIdentifier' => $taxId,
                'CustomerName' => $name,
                'Donation' => '0',
                'LoveCode' => '',
                // ⛔ 公司同樣走綠界 Email 載具，不印紙本。
                'CarrierType' => '1',
                'CarrierNum' => '',
            ];
        }

        if ($kind !== 'personal') {
            throw new RuntimeException("未知的發票類型「{$kind}」，無法開立。");
        }

        return $this->personalFields($order);
    }

    /**
     * @return array<string, string>
     */
    private function personalFields($order): array
    {
        $base = [
            'CustomerIdentifier' => '',
            'CustomerName' => '',
            'Donation' => '0',
            'LoveCode' => '',
            'CarrierType' => '',
            'CarrierNum' => '',
        ];

        return match ((string) $order->personal_invoice_mode) {
            // 綠界 Email 載具。
            'email' => array_merge($base, ['CarrierType' => '1']),

            'mobile_barcode' => array_merge($base, [
                'CarrierType' => '3',
                'CarrierNum' => $this->assertCarrier((string) $order->carrier_number),
            ]),

            'donation' => array_merge($base, [
                'Donation' => '1',
                'LoveCode' => $this->assertLoveCode((string) $order->love_code),
                // 捐贈的發票不需要載具。
                'CarrierType' => '',
            ]),

            default => throw new RuntimeException('個人發票的接收方式不明，無法開立。'),
        };
    }

    /** 手機條碼：`/` 加 7 碼大寫英數或 + - . */
    private function assertCarrier(string $carrier): string
    {
        if (! preg_match('/^\/[0-9A-Z+\-.]{7}$/', $carrier)) {
            throw new RuntimeException('手機條碼格式不正確，無法開立發票。');
        }

        return $carrier;
    }

    private function assertLoveCode(string $code): string
    {
        if (! preg_match('/^[0-9]{3,7}$/', $code)) {
            throw new RuntimeException('捐贈碼格式不正確，無法開立發票。');
        }

        return $code;
    }

    /**
     * The amount, cross-checked against the order.
     *
     * ⛔ A tax document for a different figure than the customer paid is a
     * problem they inherit, so the two must agree exactly and be a positive
     * whole number of NT dollars.
     */
    private function assertAmount(Invoice $invoice, $order): int
    {
        if (($invoice->currency ?: 'TWD') !== 'TWD') {
            throw new RuntimeException('目前只支援 TWD 電子發票。');
        }

        $amount = $invoice->amount;

        if (! is_int($amount) || $amount <= 0) {
            throw new RuntimeException('發票金額必須是正整數台幣。');
        }

        if ((int) $order->total_amount !== $amount) {
            throw new RuntimeException('發票金額與訂單金額不符，無法開立。');
        }

        return $amount;
    }

    /** ⛔ 沒有 Email 就沒有無紙化發票可送達的地方。 */
    private function assertEmail($order): string
    {
        $email = trim((string) $order->customer_email);

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('訂單缺少有效的 Email，無法開立無紙化發票。');
        }

        return $email;
    }
}
