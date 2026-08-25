<?php

namespace App\Actions\Invoices;

use App\Contracts\InvoiceGateway;
use App\Enums\InvoiceAttemptStatus;
use App\Enums\InvoiceFailureReason;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\InvoiceAttempt;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Ask the gateway to issue an invoice, once.
 *
 * The provider call is the irreversible part, so ownership of it is settled
 * before it happens: one transaction locks the invoice row, checks it is still
 * `pending`, moves it to `processing` and writes the attempt. A second worker
 * arriving at the same moment finds the row locked, then finds it no longer
 * `pending`, and returns without calling anyone.
 *
 * ⛔ This is a compare-and-set, not a read-then-write. Counting existing
 * attempts to derive the next key would let two workers each compute a
 * different key and both reach the provider — the unique index would accept
 * both, because they are not duplicates of each other.
 *
 * ⭐ D-179：結果不明時收斂為 `failed`，不再停在 `reconciliation_required`。
 *
 * 「對方可能已經開出發票」這個顧慮仍然成立，而它由**同一個 RelateNumber**
 * 處理，不是由一個永久卡住的本地狀態處理：`EcpayInvoiceGateway::issue()` 在
 * 非成功時會以同號 GetIssue 查一次，查到就收斂為 issued；查不到才是 failed。
 * Owner 之後按「手動開立發票」時送的仍是同一個號，所以綠界那邊若真的已經開
 * 過，第二次會被同號擋下並再次由同號 GetIssue 查回來——⛔ 不會變成第二張發票。
 *
 * ⛔ 舊行為讓 Owner 卡在「需人工對帳」而沒有任何出口：全站沒有後續查詢路徑，
 * `QueueInvoiceRecoveryForOrder` 又明確拒絕該狀態，於是即使綠界已經開好，本地
 * 也永遠停在那裡。收斂為 failed 讓同一個手動入口可以再走一次同號流程。
 */
class IssueInvoice
{
    public function __construct(private readonly InvoiceGateway $gateway) {}

    public function handle(Invoice $invoice): Invoice
    {
        $attempt = $this->claim($invoice);

        if ($attempt === null) {
            // 沒搶到（或狀態已不是 pending）：⛔ 絕不呼叫 gateway。
            return $invoice->fresh();
        }

        $invoice = $invoice->fresh();

        try {
            $result = $this->gateway->issue($invoice, $attempt->idempotency_key);
        } catch (Throwable $e) {
            /*
             * ⛔ 只帶 reason token，不帶 $e->getMessage()：例外訊息常含連線字串、
             * 商店代號，甚至被回音的請求內容。
             *
             * ⭐ D-179：收斂為 failed 而非 reconciliation_required。留在
             * processing／started 會讓紀錄永遠卡住；停在需人工對帳則讓 Owner
             * 沒有任何出口。防重複開立由固定的 RelateNumber 負責，不是由這個
             * 本地狀態負責。
             */
            $this->recordFailed($invoice, $attempt, InvoiceFailureReason::Unknown);

            // ⛔ 不重新丟出：丟出會讓 queue 自動重試，等於再呼叫一次 provider。
            return $invoice->fresh();
        }

        if ($result->isIssued()) {
            return $this->recordIssued($invoice, $attempt, $result);
        }

        /*
         * ⭐ 不明與確定失敗現在走同一條收斂路徑。
         *
         * gateway 在非成功時已經以同號 GetIssue 查過一次；走到這裡代表「當下
         * 查不到這張發票」。標為 failed 讓 Owner 能用手動入口再走一次同號流程，
         * ⛔ 而不是把訂單永久留在一個沒有出口的狀態。
         */
        return $this->recordFailed(
            $invoice,
            $attempt,
            $result->reason ?? InvoiceFailureReason::Unknown,
            // ⭐ 精確的階段／層級碼一路帶到落盤，Owner 才看得到失敗在哪一層。
            $result->code(),
            $result->message(),
        );
    }

    /**
     * Take exclusive ownership of the one issuing attempt, atomically.
     *
     * Returns null when this worker did not win, which includes the case where
     * the invoice was never `pending` in the first place.
     */
    private function claim(Invoice $invoice): ?InvoiceAttempt
    {
        return DB::transaction(function () use ($invoice) {
            $locked = Invoice::query()
                ->whereKey($invoice->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null || $locked->status !== InvoiceStatus::Pending) {
                return null;
            }

            $locked->forceFill(['status' => InvoiceStatus::Processing])->save();

            /*
             * ⛔ 冪等鍵在 row lock 內、依「這張發票已落盤的 attempt 筆數」推導。
             *
             * 首筆維持既有的 initial key，後續手動嘗試取得 manual-2、manual-3……
             * 這個計數是在 lock 內讀的，所以兩個 worker 不可能算出同一個序號；
             * ⛔ 不用時間或隨機值——那會讓同一次重送的兩個 worker 各拿到一個
             * 不同的鍵，unique index 兩個都收，於是兩者都真的呼叫綠界。
             *
             * ⛔ 這個鍵只影響「本地允不允許再送一次」；送往綠界的 RelateNumber
             * 永遠是同一個（由 order reference 推導），那才是防重複開立的線。
             */
            return InvoiceAttempt::create([
                'invoice_id' => $locked->id,
                'idempotency_key' => $locked->idempotencyKeyForNextAttempt(),
                'status' => InvoiceAttemptStatus::Started,
                // ⛔ 只存單向雜湊，不存可重播的內容。
                'request_fingerprint' => InvoiceAttempt::fingerprint([
                    'invoice_id' => $locked->id,
                    'order_id' => $locked->order_id,
                    'amount' => $locked->amount,
                    'currency' => $locked->currency,
                ]),
                'started_at' => now(),
            ]);
        });
    }

    private function recordIssued(Invoice $invoice, InvoiceAttempt $attempt, $result): Invoice
    {
        return DB::transaction(function () use ($invoice, $attempt, $result) {
            $attempt->forceFill([
                'status' => InvoiceAttemptStatus::Succeeded,
                'provider_reference' => $result->providerReference,
                // ⛔ 這一次成功了：不得留著上一輪的失敗代碼讓後台自相矛盾。
                'failure_code' => null,
                'failure_message' => null,
                'completed_at' => now(),
            ])->save();

            $invoice->forceFill([
                'status' => InvoiceStatus::Issued,
                'invoice_number' => $result->invoiceNumber,
                'random_code' => $result->randomCode,
                'provider_reference' => $result->providerReference,
                'failure_code' => null,
                'failure_message' => null,
                // ⛔ 優先採用 provider 自己的開立時間：國稅局那邊的紀錄用的是
                // 他們的時間，用我們的時鐘會差上整個 queue 的延遲。
                'issued_at' => $result->issuedAt ?? now(),
            ])->save();

            return $invoice->fresh();
        });
    }

    /**
     * Record a non-success outcome.
     *
     * ⛔ Runs in its own transaction so the state is recorded even when the
     * caller is unwinding from an exception; leaving the row in `processing`
     * would make it invisible to both retry and review.
     *
     * ⛔ `$reason` 的型別就是 allowlist；`$code` 與 `$message` 也只可能來自
     * `InvoiceIssueResult`，而那個物件沒有任何路徑可以承載 provider 的文字
     * ——`InvoiceFailureCode` 由本站固定 token 加通過整數驗證的數字組成，
     * 說明文字則來自本地固定中文。因此 raw response、`RtnMsg`、`TransMsg` 與
     * ciphertext 都不可能經由這裡落盤。
     *
     * ⭐ `$code`／`$message` 為 null 時退回 enum 值，⛔ 保持既有行為不變
     * （例如 gateway 之外的例外路徑）。
     *
     * ⛔ 清掉 `reconciliation_required_at`：一筆從舊資料轉過來的紀錄若留著那個
     * 時間戳，後台看起來仍像卡在人工對帳。
     */
    private function recordFailed(
        Invoice $invoice,
        InvoiceAttempt $attempt,
        InvoiceFailureReason $reason,
        ?string $code = null,
        ?string $message = null,
    ): Invoice {
        $code ??= $reason->value;
        $message ??= $reason->message();

        return DB::transaction(function () use ($invoice, $attempt, $code, $message) {
            $attempt->forceFill([
                'status' => InvoiceAttemptStatus::Failed,
                'failure_code' => $code,
                'failure_message' => $message,
                'completed_at' => now(),
            ])->save();

            $invoice->forceFill([
                'status' => InvoiceStatus::Failed,
                'failure_code' => $code,
                'failure_message' => $message,
                'reconciliation_required_at' => null,
            ])->save();

            return $invoice->fresh();
        });
    }
}
