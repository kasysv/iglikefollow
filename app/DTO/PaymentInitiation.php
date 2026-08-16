<?php

namespace App\DTO;

use App\Enums\PaymentFailureReason;

/**
 * How to send the customer to the provider's payment page.
 *
 * Two shapes, because the two providers genuinely differ:
 *
 *  - a form POST, for ECPay, which expects browser-submitted fields plus a MAC;
 *  - a redirect, for LINE Pay, which returns a URL from a server-side call.
 *
 * Plus a third: failure, when the adapter could not start a payment at all.
 * ⛔ That carries a local reason token, never the provider's message, for the
 * same reason invoices do — a provider's error text often echoes the request,
 * and a payment request contains the merchant id and the order reference.
 */
final class PaymentInitiation
{
    /**
     * @param  array<string, string>  $fields  form fields, already including any MAC
     */
    private function __construct(
        public readonly string $kind,
        public readonly ?string $endpoint = null,
        public readonly array $fields = [],
        public readonly ?string $redirectUrl = null,
        public readonly ?PaymentFailureReason $reason = null,
    ) {}

    /**
     * A browser form POST to the provider.
     *
     * ⛔ The fields are rendered into a self-submitting form on our own page,
     * never into a query string: a signed payload in a URL ends up in browser
     * history, referrer headers and any proxy log along the way.
     *
     * @param  array<string, string>  $fields
     */
    public static function formPost(string $endpoint, array $fields): self
    {
        return new self('form_post', endpoint: $endpoint, fields: $fields);
    }

    /** A redirect to a URL the provider returned and we have already validated. */
    public static function redirect(string $url): self
    {
        return new self('redirect', redirectUrl: $url);
    }

    public static function failed(PaymentFailureReason $reason): self
    {
        return new self('failed', reason: $reason);
    }

    public function isFormPost(): bool
    {
        return $this->kind === 'form_post';
    }

    public function isRedirect(): bool
    {
        return $this->kind === 'redirect';
    }

    public function isFailed(): bool
    {
        return $this->kind === 'failed';
    }
}
