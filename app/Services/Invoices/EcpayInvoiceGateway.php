<?php

namespace App\Services\Invoices;

use App\Contracts\InvoiceGateway;
use App\DTO\EcpayInvoiceResponse;
use App\DTO\InvoiceFailureCode;
use App\DTO\InvoiceIssueResult;
use App\Enums\InvoiceFailureReason;
use App\Models\Invoice;
use Carbon\CarbonImmutable;
use RuntimeException;
use Throwable;

/**
 * Issue a B2C invoice through ECPay's stage environment.
 *
 * The shape of this is decided by one fact: issuing an invoice twice is much
 * worse than issuing it late. A duplicate is a real tax document the customer
 * did not expect and someone has to void, so every uncertain path here stops
 * and waits rather than trying again.
 *
 * ⛔ There is exactly one Issue call per attempt. When its outcome is unclear
 * the adapter may make a single read-only query to find out whether an invoice
 * already exists — and only positive proof converges. "Not found" leaves it
 * uncertain, because their system may simply not have caught up yet.
 */
class EcpayInvoiceGateway implements InvoiceGateway
{
    public function __construct(
        private readonly EcpayInvoiceClient $client,
        private readonly EcpayInvoicePayloadBuilder $builder,
    ) {}

    public function issue(Invoice $invoice, string $idempotencyKey): InvoiceIssueResult
    {
        $setting = InvoiceSandboxGuard::setting();

        if ($setting === null) {
            // ⛔ 開關關閉、缺設定或端點不在白名單：0 HTTP，誠實失敗。
            return InvoiceIssueResult::failed(InvoiceFailureReason::MerchantRejected);
        }

        $relateNumber = self::relateNumberFor($invoice);

        try {
            $payload = $this->builder->build($invoice, (string) $setting->identifier, $relateNumber);
        } catch (RuntimeException) {
            /*
             * ⛔ payload 組不出來就不送出。
             *
             * 缺統編、載具格式錯、金額不符——這些都是我們這邊的資料問題，
             * 對方那邊什麼都沒發生，記為確定失敗是安全的。⛔ 不帶出 exception
             * 訊息：它含有買受人資料。
             */
            return InvoiceIssueResult::failed(
                InvoiceFailureReason::InvalidBuyerDetails,
                InvoiceFailureCode::local('ISSUE', 'PAYLOAD'),
            );
        }

        $response = $this->client->issue($setting, $payload);

        if ($response->isIssued()) {
            return $this->issuedResult($response, $relateNumber);
        }

        if ($response->isRejected()) {
            // 確定沒有開出（設定問題），可安全重試。
            return InvoiceIssueResult::failed(
                InvoiceFailureReason::MerchantRejected,
                $response->failureCode,
            );
        }

        /*
         * 結果不明：做一次唯讀查詢，看看發票是不是其實已經開出來了。
         *
         * ⛔ 這是查詢，不是重送。只有查到明確存在才收斂；其餘一律維持不明，
         * 交人工對帳。
         */
        $query = $this->client->query($setting, $relateNumber);

        if ($query->isIssued()) {
            /*
             * ⭐ 查到了：發票其實早就開出來了。
             *
             * ⛔ 這條路徑必須完全收斂為 issued 並清空 failure code／message
             * ——把一張真的存在的發票留成 failed，Owner 會以為還要再開一次。
             */
            return $this->issuedResult($query, $relateNumber);
        }

        /*
         * ⭐ 兩段都保留：Owner 需要同時看到「開立時對方說什麼」與「事後查詢時
         * 對方說什麼」。只留一段會讓其中一個問題永遠看不見——這正是本輪要解決
         * 的核心問題。
         */
        return InvoiceIssueResult::ambiguous(
            InvoiceFailureReason::Unknown,
            $response->failureCode?->withQuery($query->failureCode) ?? $query->failureCode,
        );
    }

    /**
     * ⛔ Only reached when the client has already proved the date parses; the
     * client refuses to report `issued` otherwise, so this never writes an
     * invented timestamp.
     */
    private function issuedResult(EcpayInvoiceResponse $response, string $relateNumber): InvoiceIssueResult
    {
        return InvoiceIssueResult::issued(
            invoiceNumber: $response->invoiceNumber,
            randomCode: $response->randomNumber,
            // ⛔ RelateNumber 同時作為本地 provider reference：它是唯一在
            // 重送、查詢與對帳時都不變的鍵。
            providerReference: $relateNumber,
            issuedAt: self::parseInvoiceDate($response->invoiceDate),
        );
    }

    /**
     * The one RelateNumber this invoice will ever use.
     *
     * ⛔ Derived from the order reference, so a retry, a redelivered job or a
     * later query all compute the same value. A timestamp or a counter would
     * produce a new number each time — which is exactly how the same order ends
     * up with two invoices, since ECPay would see each as a fresh request.
     *
     * ECPay allows 30 alphanumeric characters; our references are `IGL-` plus
     * 12 characters, so removing the hyphen fits with room to spare and needs
     * no truncation (truncating invites collisions).
     */
    public static function relateNumberFor(Invoice $invoice): string
    {
        $reference = (string) ($invoice->order?->reference ?? '');
        $candidate = preg_replace('/[^A-Za-z0-9]/', '', $reference) ?? '';

        if ($candidate === '' || strlen($candidate) > 30) {
            throw new RuntimeException('訂單編號無法產生合法的發票關聯編號。');
        }

        return $candidate;
    }

    /**
     * ECPay's two documented date formats, read in Taipei time.
     *
     * ⛔ Parsed explicitly rather than with a permissive constructor: a date
     * misread as a different day would put this record out of step with the
     * tax authority's, and that is not something anyone would notice quickly.
     */
    public static function parseInvoiceDate(?string $value): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        foreach (['Y-m-d H:i:s', 'Y/m/d H:i:s'] as $format) {
            try {
                $parsed = CarbonImmutable::createFromFormat($format, trim($value), 'Asia/Taipei');
            } catch (Throwable) {
                continue;
            }

            if ($parsed !== false && $parsed->format($format) === trim($value)) {
                return $parsed;
            }
        }

        return null;
    }
}
