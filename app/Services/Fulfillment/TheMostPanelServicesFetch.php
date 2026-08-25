<?php

namespace App\Services\Fulfillment;

use App\Data\Fulfillment\TheMostPanelCatalogFetchResult;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * Execute exactly one fixed TheMostPanel `services` request.
 *
 * This class is deliberately not an authorization boundary. Callers must
 * prove their own authority before supplying the server-side credential. Its
 * surface cannot express another endpoint, action, order id or HTTP option,
 * so sharing it never creates a generic provider-call primitive.
 */
class TheMostPanelServicesFetch
{
    private const ENDPOINT = 'https://themostpanel.com/api/v2';

    public function __construct(
        private readonly ?TheMostPanelHardenedTransport $transport = null,
    ) {}

    public function fetch(string $key): TheMostPanelCatalogFetchResult
    {
        if (trim($key) === '') {
            return TheMostPanelCatalogFetchResult::blocked('blocked_no_credential');
        }

        if ((string) config('integrations.themostpanel_read_only.endpoint') !== self::ENDPOINT) {
            return TheMostPanelCatalogFetchResult::blocked('blocked_endpoint');
        }

        $startedAt = microtime(true);
        $sink = new TheMostPanelBoundedResponseStream(TheMostPanelResponseSizeGuard::MAX_BODY_BYTES);
        $transfer = new TheMostPanelTransferState;

        try {
            $response = ($this->transport ?? new TheMostPanelHardenedTransport)->postExactlyOnce(
                self::ENDPOINT,
                ['key' => $key, 'action' => 'services'],
                $sink,
                $transfer,
            );
        } catch (Throwable $exception) {
            return TheMostPanelCatalogFetchResult::failed(
                $this->transportFailureCode($exception, $transfer, $sink),
                elapsedMs: $this->elapsed($startedAt),
            );
        }

        return $this->read($response, $key, $startedAt);
    }

    private function read(Response $response, string $key, float $startedAt): TheMostPanelCatalogFetchResult
    {
        $elapsed = $this->elapsed($startedAt);
        $status = $response->status();

        if ($failure = $this->statusFailureCode($status)) {
            return TheMostPanelCatalogFetchResult::failed($failure, $status, $elapsed);
        }

        $body = (string) $response->body();

        if ($failure = $this->bodyFailureCode($body)) {
            return TheMostPanelCatalogFetchResult::failed($failure, $status, $elapsed);
        }

        if (TheMostPanelCredentialEchoGuard::echoes($body, $key)) {
            return TheMostPanelCatalogFetchResult::failed('credential_echo_refused', $status, $elapsed);
        }

        return TheMostPanelCatalogFetchResult::fetched($body, $status, $elapsed);
    }

    private function transportFailureCode(
        Throwable $exception,
        TheMostPanelTransferState $transfer,
        TheMostPanelBoundedResponseStream $sink,
    ): string {
        if ($sink->overflowed() || $transfer->exceededMaxFileSize()) {
            return 'body_too_large';
        }

        if (TheMostPanelResponseSizeGuard::isSizeAbort($exception)) {
            return 'body_too_large';
        }

        if (TheMostPanelResponseSizeGuard::isEncodingRefusal($exception)) {
            return 'unsupported_encoding';
        }

        return 'transport_failed';
    }

    private function statusFailureCode(int $status): ?string
    {
        if ($status >= 300 && $status < 400) {
            return 'redirect_refused';
        }

        if ($status === 429) {
            return 'rate_limited';
        }

        if ($status >= 500) {
            return 'server_error';
        }

        return ($status >= 400 || $status < 200) ? 'client_error' : null;
    }

    private function bodyFailureCode(string $body): ?string
    {
        if ($body === '') {
            return 'empty_body';
        }

        if (strlen($body) > TheMostPanelResponseSizeGuard::MAX_BODY_BYTES) {
            return 'body_too_large';
        }

        return mb_check_encoding($body, 'UTF-8') ? null : 'invalid_encoding';
    }

    private function elapsed(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
