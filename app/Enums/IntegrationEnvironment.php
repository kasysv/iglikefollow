<?php

namespace App\Enums;

/**
 * Which of a provider's environments a credential set belongs to.
 *
 * Sandbox and production are different accounts with different keys, and
 * confusing them is how test runs end up billing real customers. They are
 * therefore separate rows, never two columns on one row.
 */
enum IntegrationEnvironment: string
{
    case Sandbox = 'sandbox';
    case Production = 'production';

    public function label(): string
    {
        return match ($this) {
            self::Sandbox => '測試環境（sandbox）',
            self::Production => '正式環境（production）',
        };
    }

    public function isProduction(): bool
    {
        return $this === self::Production;
    }
}
