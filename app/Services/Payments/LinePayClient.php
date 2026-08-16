<?php

namespace App\Services\Payments;

use App\DTO\LinePayResponse;
use App\Enums\IntegrationEnvironment;
use App\Enums\IntegrationProvider;
use App\Models\IntegrationSetting;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The HTTP half of LINE Pay, kept away from the domain.
 *
 * Every call returns a typed result; ⛔ nothing here throws into the caller and
 * nothing here stores anything. Timeouts are the ones the official
 * documentation specifies — 10 seconds to request, 40 to confirm, because
 * confirm can involve the customer's bank and a short timeout would abandon
 * payments that were about to succeed.
 */
class LinePayClient
{
    private const REQUEST_TIMEOUT = 10;

    private const CONFIRM_TIMEOUT = 40;

    /**
     * Ask LINE Pay to start a payment.
     *
     * @param  array<string, mixed>  $body
     */
    public function requestPayment(array $body): LinePayResponse
    {
        return $this->call('/v4/payments/request', $body, self::REQUEST_TIMEOUT);
    }

    /**
     * Confirm a payment the customer approved.
     *
     * ⛔ This, not the browser's return, is what proves a payment happened.
     *
     * @param  array<string, mixed>  $body
     */
    public function confirmPayment(string $transactionId, array $body): LinePayResponse
    {
        return $this->call("/v4/payments/{$transactionId}/confirm", $body, self::CONFIRM_TIMEOUT);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function call(string $uri, array $body, int $timeout): LinePayResponse
    {
        $setting = $this->setting();

        if ($setting === null) {
            return LinePayResponse::unavailable();
        }

        $channelId = (string) $setting->identifier;
        $secret = $setting->secret('ChannelSecret');
        $base = rtrim((string) config('integrations.endpoints.line_pay.sandbox'), '/');

        if ($secret === null || $channelId === '' || $base === '') {
            return LinePayResponse::unavailable();
        }

        // ⛔ 每次都用新的 nonce：重複使用等於允許重放同一筆簽好的請求。
        $headers = LinePaySignature::headers(
            $channelId, $secret, $uri, $body, LinePaySignature::nonce()
        );

        try {
            $response = Http::withHeaders($headers)
                ->timeout($timeout)
                ->acceptJson()
                ->asJson()
                ->post($base.$uri, $body);
        } catch (Throwable) {
            // ⛔ 逾時或連線失敗＝結果不明，不是失敗：對方可能已經處理了。
            // 也不帶出 exception 訊息，那裡面常有連線字串與 channel id。
            return LinePayResponse::timeout();
        }

        $json = null;

        try {
            $json = $response->json();
        } catch (Throwable) {
            $json = null;
        }

        if (! is_array($json) || ! isset($json['returnCode'])) {
            return LinePayResponse::unreadable();
        }

        return LinePayResponse::fromArray($json);
    }

    private function setting(): ?IntegrationSetting
    {
        $setting = IntegrationSetting::query()
            ->where('provider', IntegrationProvider::LinePay)
            ->where('environment', IntegrationEnvironment::Sandbox)
            ->first();

        return $setting?->isUsable() ? $setting : null;
    }
}
