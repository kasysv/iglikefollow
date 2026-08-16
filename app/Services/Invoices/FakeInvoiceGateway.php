<?php

namespace App\Services\Invoices;

use App\Contracts\InvoiceGateway;
use App\DTO\InvoiceIssueResult;
use App\Enums\InvoiceFailureReason;
use App\Models\Invoice;

/**
 * A local stand-in that issues nothing and calls nobody.
 *
 * It exists so the whole invoice lifecycle — including the failure and
 * ambiguous branches, which are the hard ones — can be exercised before any
 * real credentials exist.
 *
 * ⛔ The outcome is chosen by test code calling `alwaysFail()` / `alwaysBeAmbiguous()`,
 * never by anything a customer can send. Letting a request parameter pick the
 * result would mean a public input deciding whether an invoice gets issued.
 */
class FakeInvoiceGateway implements InvoiceGateway
{
    private string $outcome = 'issued';

    /** ⛔ reason 是 enum，不是字串：測試也無法藉此塞入任意文字。 */
    private InvoiceFailureReason $reason = InvoiceFailureReason::Unknown;

    /** @var list<string> */
    public array $calls = [];

    public function alwaysIssue(): void
    {
        $this->outcome = 'issued';
    }

    /**
     * ⛔ 只接受已定義的 reason。
     *
     * 舊版可以傳任意 code 與 message，等於留了一條「把任意文字送進資料庫」
     * 的公開路徑——測試方便，但那正是要防的東西。
     */
    public function alwaysFail(InvoiceFailureReason $reason = InvoiceFailureReason::InvalidBuyerDetails): void
    {
        $this->outcome = 'failed';
        $this->reason = $reason;
    }

    public function alwaysBeAmbiguous(InvoiceFailureReason $reason = InvoiceFailureReason::Timeout): void
    {
        $this->outcome = 'ambiguous';
        $this->reason = $reason;
    }

    public function issue(Invoice $invoice, string $idempotencyKey): InvoiceIssueResult
    {
        // 記錄呼叫次數，讓測試能證明重送沒有真的再打一次。
        $this->calls[] = $idempotencyKey;

        return match ($this->outcome) {
            'failed' => InvoiceIssueResult::failed($this->reason),
            'ambiguous' => InvoiceIssueResult::ambiguous($this->reason),
            default => InvoiceIssueResult::issued(
                // 固定推導，⛔ 不用亂數：同一張發票重跑要得到同一個號碼。
                invoiceNumber: sprintf('FAKE%08d', $invoice->id),
                randomCode: sprintf('%04d', $invoice->id % 10000),
                providerReference: 'fake-'.$idempotencyKey,
            ),
        };
    }
}
