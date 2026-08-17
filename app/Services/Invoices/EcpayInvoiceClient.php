<?php

namespace App\Services\Invoices;

use App\DTO\EcpayInvoiceResponse;
use App\Models\IntegrationSetting;
use Carbon\CarbonImmutable;
use Ecpay\Sdk\Services\AesService;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The HTTP half of ECPay invoicing: envelope, AES, and strict reading.
 *
 * ⛔ Nothing here is stored and nothing here throws into the caller. Every
 * outcome is one of three typed answers, because the caller's decision — issue,
 * refuse, or wait for a human — depends entirely on whether an invoice might
 * exist at ECPay, and an exception carries that information badly.
 *
 * ⛔ The cryptography is the official SDK's, and it is fed the way the SDK
 * documents it: `encrypt()` takes an **array** and does its own
 * `json_encode → urlencode → AES` internally, and `decrypt()` returns an
 * **array** having already undone all three. An earlier version JSON-encoded
 * the payload before handing it over and `json_decode`d the result afterwards.
 * That produced a JSON string wrapped in JSON — a different ciphertext from the
 * one ECPay expects — and would have cast their real array reply to the string
 * "Array". The fixtures used the same wrong flow on both sides, so the tests
 * passed while the integration could never have worked against the real
 * provider.
 *
 * Issue and GetIssue share the outer envelope and the cipher, and nothing else:
 * their success payloads use entirely different field names, so they get
 * entirely separate parsers.
 */
class EcpayInvoiceClient
{
    private const TIMEOUT = 20;

    private const REVISION = '3.0.0';

    /**
     * Ask ECPay to issue an invoice.
     *
     * @param  array<string, mixed>  $payload  built from the order snapshot
     */
    public function issue(IntegrationSetting $setting, array $payload): EcpayInvoiceResponse
    {
        $endpoint = InvoiceSandboxGuard::issueEndpoint();

        if ($endpoint === null) {
            return EcpayInvoiceResponse::rejected();
        }

        $inner = $this->call($setting, $endpoint, $payload);

        return $inner === null
            ? EcpayInvoiceResponse::uncertain()
            : $this->readIssue($inner);
    }

    /**
     * Read-only: has an invoice already been issued for this RelateNumber?
     *
     * ⛔ Used once after an unclear Issue, and only to find positive proof. A
     * "not found" answer is not permission to issue again — their system may
     * simply not have caught up, and a second issue is a second tax document.
     */
    public function query(IntegrationSetting $setting, string $relateNumber): EcpayInvoiceResponse
    {
        $endpoint = InvoiceSandboxGuard::queryEndpoint();

        if ($endpoint === null) {
            return EcpayInvoiceResponse::uncertain();
        }

        $merchantId = (string) $setting->identifier;

        $inner = $this->call($setting, $endpoint, [
            'MerchantID' => $merchantId,
            'RelateNumber' => $relateNumber,
        ]);

        return $inner === null
            ? EcpayInvoiceResponse::uncertain()
            : $this->readQuery($inner, $merchantId, $relateNumber);
    }

    /**
     * Send one request and return the decrypted inner payload, or null.
     *
     * Null means "we cannot read an answer" for any reason at all — a timeout, a
     * non-2xx status, a wrong merchant, a cipher we cannot decrypt. ⛔ It never
     * distinguishes those, because the caller must treat all of them the same
     * way: as not knowing, rather than as a failure.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function call(IntegrationSetting $setting, string $endpoint, array $payload): ?array
    {
        $hashKey = $setting->secret('HashKey');
        $hashIv = $setting->secret('HashIV');
        $merchantId = (string) $setting->identifier;

        if ($hashKey === null || $hashIv === null || $merchantId === '') {
            return null;
        }

        $aes = new AesService($hashKey, $hashIv);

        $envelope = [
            'MerchantID' => $merchantId,
            'RqHeader' => [
                // ⛔ 由可覆寫的 clock 產生，方便測試；⛔ 不寫入 log。
                'Timestamp' => $this->now()->getTimestamp(),
                'Revision' => self::REVISION,
            ],
            // ⛔ 直接傳 array：SDK 自己做 json_encode → urlencode → AES。
            'Data' => $aes->encrypt($payload),
        ];

        try {
            $response = Http::asJson()
                ->acceptJson()
                ->timeout(self::TIMEOUT)
                ->post($endpoint, $envelope);
        } catch (Throwable) {
            // ⛔ 逾時或連線失敗＝結果不明：對方可能已經開出發票了。
            return null;
        }

        // ⛔ 非 2xx 時 body 不可採信，即使裡面寫著成功。
        if (! $response->successful()) {
            return null;
        }

        $json = $response->json();

        if (! is_array($json)) {
            return null;
        }

        // 回應必須來自我們自己的商店代號。
        if (($json['MerchantID'] ?? null) !== $merchantId) {
            return null;
        }

        // ⛔ 必須是整數 1；字串 "1"、true、1.0 都不算。
        if (($json['TransCode'] ?? null) !== 1) {
            return null;
        }

        $data = $json['Data'] ?? null;

        if (! is_string($data) || trim($data) === '') {
            return null;
        }

        try {
            // ⛔ SDK 直接回 array，已完成 AES → urldecode → json_decode。
            $inner = $aes->decrypt($data);
        } catch (Throwable) {
            return null;
        }

        return is_array($inner) ? $inner : null;
    }

    /**
     * The Issue success payload.
     *
     * ⛔ A success code with a field we cannot read is not a success. The date
     * in particular must parse in ECPay's own documented format: a record whose
     * issue date we had to invent would be out of step with the tax authority's
     * copy, and nobody would notice for months.
     *
     * @param  array<string, mixed>  $inner
     */
    private function readIssue(array $inner): EcpayInvoiceResponse
    {
        if (($inner['RtnCode'] ?? null) !== 1) {
            /*
             * ⛔ 非成功碼一律不猜。
             *
             * 綠界的錯誤碼會持續新增，而「我們看不懂這個碼」不等於「發票沒有
             * 開出來」。在沒有官方穩定 allowlist 之前，安全的答案是不確定。
             */
            return EcpayInvoiceResponse::uncertain();
        }

        $number = $this->text($inner['InvoiceNo'] ?? null);
        $random = $this->text($inner['RandomNumber'] ?? null);
        $date = $this->text($inner['InvoiceDate'] ?? null);

        // 成功碼卻缺必要欄位：⛔ 不能當成開立成功。
        if ($number === null || $random === null || $date === null) {
            return EcpayInvoiceResponse::uncertain();
        }

        // ⛔ 日期必須符合官方格式才算開立成功；解析不出來就不是可信的成功。
        if (EcpayInvoiceGateway::parseInvoiceDate($date) === null) {
            return EcpayInvoiceResponse::uncertain();
        }

        return EcpayInvoiceResponse::issued($number, $random, $date);
    }

    /**
     * The GetIssue success payload — a different schema entirely.
     *
     * ECPay documents this one with `IIS_*` field names, not the Issue reply's
     * `InvoiceNo`/`InvoiceDate`/`RandomNumber`. Reusing the Issue parser here
     * meant every real query would have come back unreadable, so the one path
     * that exists to resolve an uncertain issue could never have resolved
     * anything.
     *
     * ⛔ Converging requires *positive proof that this invoice, ours, is live*:
     * their merchant id, our RelateNumber, a readable number and date, issued,
     * and not voided. Anything else — a mismatch, a void, a status we do not
     * recognise — stays uncertain. Uncertain costs a human five minutes;
     * guessing wrong costs a duplicate tax document.
     *
     * @param  array<string, mixed>  $inner
     */
    private function readQuery(array $inner, string $merchantId, string $relateNumber): EcpayInvoiceResponse
    {
        if (($inner['RtnCode'] ?? null) !== 1) {
            return EcpayInvoiceResponse::uncertain();
        }

        // ⛔ 必須是我們的商店、我們這次查的那一筆。
        if ($this->text($inner['IIS_Mer_ID'] ?? null) !== $merchantId) {
            return EcpayInvoiceResponse::uncertain();
        }

        if ($this->text($inner['IIS_Relate_Number'] ?? null) !== $relateNumber) {
            return EcpayInvoiceResponse::uncertain();
        }

        // ⛔ 已開立且未作廢；狀態不是這兩個確切值就不收斂。
        if ($this->text($inner['IIS_Issue_Status'] ?? null) !== '1') {
            return EcpayInvoiceResponse::uncertain();
        }

        if ($this->text($inner['IIS_Invalid_Status'] ?? null) !== '0') {
            return EcpayInvoiceResponse::uncertain();
        }

        $number = $this->text($inner['IIS_Number'] ?? null);
        $random = $this->text($inner['IIS_Random_Number'] ?? null);
        $date = $this->text($inner['IIS_Create_Date'] ?? null);

        if ($number === null || $random === null || $date === null) {
            return EcpayInvoiceResponse::uncertain();
        }

        if (EcpayInvoiceGateway::parseInvoiceDate($date) === null) {
            return EcpayInvoiceResponse::uncertain();
        }

        return EcpayInvoiceResponse::issued($number, $random, $date);
    }

    /** ⛔ 只接受非空字串；array／bool／float／object 一律 null，不寬鬆 cast。 */
    private function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /** 可覆寫的時間來源，讓 envelope timestamp 在測試中可預期。 */
    protected function now(): CarbonImmutable
    {
        return CarbonImmutable::now();
    }
}
