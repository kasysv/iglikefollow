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
 * ⛔ The cryptography is the official SDK's. Hand-rolling AES is how padding
 * and IV mistakes get shipped, and this is the one place where a subtle error
 * would look like a working integration until a real invoice failed.
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

        return $this->call($setting, $endpoint, $payload);
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

        return $this->call($setting, $endpoint, [
            'MerchantID' => (string) $setting->identifier,
            'RelateNumber' => $relateNumber,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function call(IntegrationSetting $setting, string $endpoint, array $payload): EcpayInvoiceResponse
    {
        $hashKey = $setting->secret('HashKey');
        $hashIv = $setting->secret('HashIV');
        $merchantId = (string) $setting->identifier;

        if ($hashKey === null || $hashIv === null || $merchantId === '') {
            return EcpayInvoiceResponse::rejected();
        }

        $aes = new AesService($hashKey, $hashIv);

        $envelope = [
            'MerchantID' => $merchantId,
            'RqHeader' => [
                // ⛔ 由可注入的 clock 產生，方便測試；⛔ 不寫入 log。
                'Timestamp' => $this->now()->getTimestamp(),
                'Revision' => self::REVISION,
            ],
            'Data' => $aes->encrypt(
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ),
        ];

        try {
            $response = Http::asJson()
                ->acceptJson()
                ->timeout(self::TIMEOUT)
                ->post($endpoint, $envelope);
        } catch (Throwable) {
            // ⛔ 逾時或連線失敗＝結果不明：對方可能已經開出發票了。
            return EcpayInvoiceResponse::uncertain();
        }

        // ⛔ 非 2xx 時 body 不可採信，即使裡面寫著成功。
        if (! $response->successful()) {
            return EcpayInvoiceResponse::uncertain();
        }

        return $this->read($response->json(), $aes, $merchantId);
    }

    /**
     * Read the outer envelope and the encrypted payload, strictly.
     *
     * ⛔ Anything that does not have exactly the documented shape is uncertain,
     * never "failed": we cannot tell an invoice that was refused from one that
     * was issued and reported in a way we could not parse.
     */
    private function read(mixed $json, AesService $aes, string $merchantId): EcpayInvoiceResponse
    {
        if (! is_array($json)) {
            return EcpayInvoiceResponse::uncertain();
        }

        // 回應必須來自我們自己的商店代號。
        if (($json['MerchantID'] ?? null) !== $merchantId) {
            return EcpayInvoiceResponse::uncertain();
        }

        // ⛔ 必須是整數 1；字串 "1"、true、1.0 都不算。
        if (($json['TransCode'] ?? null) !== 1) {
            return EcpayInvoiceResponse::uncertain();
        }

        $data = $json['Data'] ?? null;

        if (! is_string($data) || trim($data) === '') {
            return EcpayInvoiceResponse::uncertain();
        }

        try {
            $decrypted = $aes->decrypt($data);
        } catch (Throwable) {
            return EcpayInvoiceResponse::uncertain();
        }

        $inner = json_decode((string) $decrypted, true);

        if (! is_array($inner)) {
            return EcpayInvoiceResponse::uncertain();
        }

        $code = $inner['RtnCode'] ?? null;

        if ($code !== 1) {
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
