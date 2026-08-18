<?php

namespace Tests\Unit\Fulfillment;

use App\Contracts\TheMostPanelDispatchCredentialSource;
use App\Data\Fulfillment\FulfillmentSubmission;
use App\Enums\FulfillmentAttentionReason;
use App\Enums\FulfillmentStatus;
use App\Services\Fulfillment\TheMostPanelCurlCapability;
use App\Services\Fulfillment\TheMostPanelFulfillmentGateway;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The adapter's strict response contract, one branch at a time.
 *
 * ⛔ Everything here is faked at the HTTP client layer; no request leaves the
 * process, and the key is a fictional marker.
 */
class TheMostPanelFulfillmentGatewayTest extends TestCase
{
    private const ENDPOINT = 'https://themostpanel.com/api/v2';

    private const KEY = 'FAKE-DISPATCH-KEY-MARKER-424242';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        // testing 環境的 endpoint 閘;⛔ production config 維持空字串。
        config()->set('integrations.endpoints.themostpanel.testing', self::ENDPOINT);
    }

    private function gateway(?string $key = self::KEY, bool $capable = true): TheMostPanelFulfillmentGateway
    {
        $source = new class($key) implements TheMostPanelDispatchCredentialSource
        {
            public function __construct(private readonly ?string $key) {}

            public function apiKey(): ?string
            {
                return $this->key;
            }
        };

        return new TheMostPanelFulfillmentGateway(
            $source,
            $capable
                ? TheMostPanelCurlCapability::supported()
                : TheMostPanelCurlCapability::unsupported(),
        );
    }

    private function submission(): FulfillmentSubmission
    {
        return new FulfillmentSubmission('4501', 'https://example.invalid/fictional-post', 1000);
    }

    // ==================================== add:成功

    public function test_an_integer_order_id_is_accepted(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['order' => 23501])]);

        $result = $this->gateway()->submit($this->submission());

        $this->assertTrue($result->isAccepted());
        $this->assertSame('23501', $result->providerOrderId);
    }

    public function test_a_canonical_digit_string_order_id_is_accepted(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['order' => '98765'])]);

        $result = $this->gateway()->submit($this->submission());

        $this->assertTrue($result->isAccepted());
        $this->assertSame('98765', $result->providerOrderId);
    }

    /** 送出的 payload 精確是文件的一般型:key + add + service + link + quantity。 */
    public function test_the_add_payload_is_exactly_the_documented_general_shape(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['order' => 1])]);

        $this->gateway()->submit($this->submission());

        Http::assertSent(function ($request) {
            $data = $request->data();
            ksort($data);

            return $request->url() === self::ENDPOINT
                && $request->method() === 'POST'
                && $data === [
                    'action' => 'add',
                    'key' => self::KEY,
                    'link' => 'https://example.invalid/fictional-post',
                    'quantity' => 1000,
                    'service' => '4501',
                ];
        });
        Http::assertSentCount(1);
    }

    // ==================================== add:嚴格拒絕成功的形狀

    /** @return array<string, array{0: mixed}> */
    public static function unusableOrderIdProvider(): array
    {
        return [
            'zero' => [0],
            'negative' => [-5],
            'float' => [1.5],
            'zero string' => ['0'],
            'leading zeros' => ['007'],
            'alpha string' => ['ORDER-1'],
            'blank string' => ['   '],
            'overlong digits' => [str_repeat('9', 65)],
            'array' => [[23501]],
            'nested object' => [['id' => 23501]],
            'null' => [null],
            'bool' => [true],
        ];
    }

    /** ⛔ 成功卻沒有可用 ID:可能已成立 → unknown,絕不 rejected、絕不重送。 */
    #[DataProvider('unusableOrderIdProvider')]
    public function test_a_success_without_a_usable_id_is_unknown(mixed $order): void
    {
        Http::fake([self::ENDPOINT => Http::response(['order' => $order])]);

        $result = $this->gateway()->submit($this->submission());

        $this->assertTrue($result->isUnknown());
        $this->assertNull($result->providerOrderId);
        Http::assertSentCount(1);
    }

    public function test_conflicting_order_and_error_fields_are_unknown(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['order' => 23501, 'error' => 'fictional'])]);

        $this->assertTrue($this->gateway()->submit($this->submission())->isUnknown());
    }

    // ==================================== add:明確拒絕與不可判定

    public function test_a_definite_error_object_maps_to_provider_rejected(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['error' => 'fictional refusal text'])]);

        $result = $this->gateway()->submit($this->submission());

        $this->assertTrue($result->isRejected());
        $this->assertSame(FulfillmentAttentionReason::ProviderRejected, $result->reason);
    }

    /** @return array<string, array{0: mixed, 1: int}> */
    public static function unreadableBodyProvider(): array
    {
        return [
            'http 500' => ['whatever', 500],
            'http 404' => ['whatever', 404],
            'http 302' => ['', 302],
            'malformed json' => ['{"order": 23', 200],
            'top-level list' => [[['order' => 1]], 200],
            'top-level string' => ['"ok"', 200],
            'empty body' => ['', 200],
            'empty object' => ['{}', 200],
            'error not a string' => [['error' => 123], 200],
            'error blank' => [['error' => '   '], 200],
        ];
    }

    /** ⛔ HTTP／shape 讀不懂:可能已成立 → unknown。 */
    #[DataProvider('unreadableBodyProvider')]
    public function test_an_unreadable_response_is_unknown(mixed $body, int $status): void
    {
        Http::fake([self::ENDPOINT => Http::response($body, $status)]);

        $result = $this->gateway()->submit($this->submission());

        $this->assertTrue($result->isUnknown());
        Http::assertSentCount(1);
    }

    public function test_a_connection_failure_is_unknown_timeout_with_no_retry(): void
    {
        Http::fake(fn () => throw new ConnectionException('fictional timeout'));

        $result = $this->gateway()->submit($this->submission());

        $this->assertTrue($result->isUnknown());
        $this->assertSame(FulfillmentAttentionReason::Timeout, $result->reason);
    }

    /** ⛔ credential echo:整個結果 fail closed,key 不離開記憶體。 */
    public function test_a_credential_echo_fails_the_whole_result(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['order' => 23501, 'note' => self::KEY])]);

        $result = $this->gateway()->submit($this->submission());

        $this->assertTrue($result->isUnknown());
        $this->assertNull($result->providerOrderId);
    }

    // ==================================== add:網路前 fail closed

    public function test_a_wrong_endpoint_config_refuses_before_any_network(): void
    {
        config()->set('integrations.endpoints.themostpanel.testing', 'https://evil.invalid/api');
        Http::fake();

        $result = $this->gateway()->submit($this->submission());

        $this->assertTrue($result->isRejected());
        $this->assertSame(FulfillmentAttentionReason::DispatchDisabled, $result->reason);
        Http::assertNothingSent();
    }

    public function test_a_runtime_without_the_transport_cap_refuses_before_any_network(): void
    {
        Http::fake();

        $result = $this->gateway(capable: false)->submit($this->submission());

        $this->assertTrue($result->isRejected());
        Http::assertNothingSent();
    }

    public function test_a_missing_credential_refuses_before_any_network(): void
    {
        Http::fake();

        $result = $this->gateway(key: null)->submit($this->submission());

        $this->assertTrue($result->isRejected());
        $this->assertSame(FulfillmentAttentionReason::DispatchDisabled, $result->reason);
        Http::assertNothingSent();
    }

    public function test_an_invalid_submission_refuses_before_any_network(): void
    {
        Http::fake();

        foreach ([
            new FulfillmentSubmission('NOT-DIGITS', 'https://example.invalid/x', 100),
            new FulfillmentSubmission('007', 'https://example.invalid/x', 100),
            new FulfillmentSubmission('4501', '   ', 100),
            new FulfillmentSubmission('4501', 'https://example.invalid/x', 0),
        ] as $bad) {
            $result = $this->gateway()->submit($bad);

            $this->assertTrue($result->isRejected());
            $this->assertSame(FulfillmentAttentionReason::UnsupportedPayload, $result->reason);
        }

        Http::assertNothingSent();
    }

    // ==================================== status

    /** @return array<string, array{0: string, 1: FulfillmentStatus}> */
    public static function exactStatusTokenProvider(): array
    {
        return [
            'In progress' => ['In progress', FulfillmentStatus::Processing],
            'Completed' => ['Completed', FulfillmentStatus::Completed],
            'Partial' => ['Partial', FulfillmentStatus::Partial],
            'Rejected' => ['Rejected', FulfillmentStatus::Failed],
        ];
    }

    #[DataProvider('exactStatusTokenProvider')]
    public function test_the_four_documented_status_tokens_map_exactly(string $token, FulfillmentStatus $expected): void
    {
        Http::fake([self::ENDPOINT => Http::response([
            'charge' => '0.27', 'start_count' => '100', 'status' => $token, 'remains' => '0', 'currency' => 'TWD',
        ])]);

        $result = $this->gateway()->sync('23501');

        $this->assertTrue($result->isRecognised());
        $this->assertSame($expected, $result->status);
    }

    /** 送出的 status payload 精確是 key + status + 單筆 order。 */
    public function test_the_status_payload_is_exactly_one_order(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['status' => 'Completed'])]);

        $this->gateway()->sync('23501');

        Http::assertSent(function ($request) {
            $data = $request->data();
            ksort($data);

            return $data === ['action' => 'status', 'key' => self::KEY, 'order' => '23501'];
        });
    }

    /** @return array<string, array{0: mixed}> */
    public static function unrecognisedStatusProvider(): array
    {
        return [
            'lowercase' => ['completed'],
            'uppercase' => ['COMPLETED'],
            'leading space' => [' In progress'],
            'trailing space' => ['Completed '],
            'title-cased variant' => ['In Progress'],
            'unknown token' => ['Processing'], // 我們的內部字彙不是他們的 token
            'canceled token undocumented' => ['Canceled'],
            'not a string' => [123],
            'null' => [null],
            'list' => [['Completed']],
        ];
    }

    /** ⛔ 讀不懂的狀態永不 round 成 completed。 */
    #[DataProvider('unrecognisedStatusProvider')]
    public function test_anything_but_the_exact_tokens_is_unrecognised(mixed $token): void
    {
        Http::fake([self::ENDPOINT => Http::response(['status' => $token])]);

        $this->assertFalse($this->gateway()->sync('23501')->isRecognised());
    }

    public function test_a_status_error_object_or_bad_shape_is_unrecognised(): void
    {
        foreach ([
            ['error' => 'fictional'],
            ['charge' => '0.27'],
            'not-json{',
            '',
        ] as $body) {
            Http::fake([self::ENDPOINT => Http::response($body)]);

            $this->assertFalse($this->gateway()->sync('23501')->isRecognised());
        }
    }

    public function test_an_invalid_order_id_never_reaches_the_network(): void
    {
        Http::fake();

        foreach (['', '0', '007', 'ORDER-1', str_repeat('9', 65)] as $bad) {
            $this->assertFalse($this->gateway()->sync($bad)->isRecognised());
        }

        Http::assertNothingSent();
    }
}
