<?php

namespace App\Services\Invoices;

use App\Contracts\InvoiceGateway;
use App\DTO\InvoiceIssueResult;
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

    private string $code = 'FAKE_OK';

    private string $message = '';

    /** @var list<string> */
    public array $calls = [];

    public function alwaysIssue(): void
    {
        $this->outcome = 'issued';
    }

    public function alwaysFail(string $code = 'FAKE_REJECTED', string $message = '測試用的確定性失敗。'): void
    {
        $this->outcome = 'failed';
        $this->code = $code;
        $this->message = $message;
    }

    public function alwaysBeAmbiguous(string $code = 'FAKE_TIMEOUT', string $message = '測試用的逾時，結果不明。'): void
    {
        $this->outcome = 'ambiguous';
        $this->code = $code;
        $this->message = $message;
    }

    public function issue(Invoice $invoice, string $idempotencyKey): InvoiceIssueResult
    {
        // 記錄呼叫次數，讓測試能證明重送沒有真的再打一次。
        $this->calls[] = $idempotencyKey;

        return match ($this->outcome) {
            'failed' => InvoiceIssueResult::failed($this->code, $this->message),
            'ambiguous' => InvoiceIssueResult::ambiguous($this->code, $this->message),
            default => InvoiceIssueResult::issued(
                // 固定推導，⛔ 不用亂數：同一張發票重跑要得到同一個號碼。
                invoiceNumber: sprintf('FAKE%08d', $invoice->id),
                randomCode: sprintf('%04d', $invoice->id % 10000),
                providerReference: 'fake-'.$idempotencyKey,
            ),
        };
    }
}
