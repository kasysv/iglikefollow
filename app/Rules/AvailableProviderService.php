<?php

namespace App\Rules;

use App\Enums\IntegrationProvider;
use App\Models\ProviderService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The submitted provider service ID must exist in the observed TheMostPanel
 * catalog and be available — checked at submit time, on the server.
 *
 * ⛔ The select menu is convenience, not the boundary. Between rendering the
 * options and pressing save, a newer snapshot may have marked the row
 * unavailable — or the request may not have come from the menu at all. This
 * rule re-reads the catalog row inside the submit.
 *
 * ⛔ One narrow exception keeps history intelligible: when editing a mapping
 * whose stored ID has dropped out of the available catalog, the Owner may
 * keep that exact ID while the mapping stays disabled — seeing and switching
 * off a stale pairing must not require destroying its value. Re-enabling a
 * stale ID, or switching to any other unknown ID, still fails.
 */
class AvailableProviderService implements ValidationRule
{
    public function __construct(
        /** 編輯中舊 ID;只有停用時才可保留,null = 不可保留任何 stale 值。 */
        private readonly ?string $retainableStaleId = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $value = (string) $value;

        $available = ProviderService::query()
            ->where('provider', IntegrationProvider::TheMostPanel->value)
            ->where('provider_service_id', $value)
            ->where('is_available', true)
            ->exists();

        if ($available) {
            return;
        }

        if ($this->retainableStaleId !== null && $value === $this->retainableStaleId) {
            // 停用既有 stale mapping:保留舊值,讓歷史可理解。
            return;
        }

        // ⛔ 固定安全訊息:不回display提交的值。
        $fail('必須從目前可用的供應商服務目錄中選擇;此代碼不存在或已不可用。');
    }
}
