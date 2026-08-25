<?php

namespace App\Rules;

use App\Enums\IntegrationProvider;
use App\Models\ProviderService;
use App\Support\DecorativeProviderServiceName;
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
 * ⛔ Shape before everything (R1). Livewire state is client-writable, so the
 * value may be an array, an object, null, a bool or a number — and untrusted
 * input must not produce so much as a PHP warning. Nothing is cast, trimmed
 * or auto-corrected: only a canonical positive-integer string (`[1-9][0-9]*`,
 * ≤ 64 chars, the only shape a parsed catalog ID can have) ever reaches the
 * database query. Everything else fails with the same fixed message that
 * never echoes the submitted value.
 *
 * ⛔ One narrow exception keeps history intelligible: when editing a mapping
 * whose stored ID has dropped out of the available catalog, the Owner may
 * keep that exact ID while the mapping stays disabled — seeing and switching
 * off a stale pairing must not require destroying its value, even when the
 * old value predates the canonical format. Re-enabling a stale ID, or
 * switching to any other unknown ID, still fails.
 */
class AvailableProviderService implements ValidationRule
{
    /** ⛔ 唯一的失敗訊息:固定、安全、永不回顯提交值。 */
    public const FAILED_MESSAGE = '必須從目前可用的供應商服務目錄中選擇;此代碼不存在或已不可用。';

    /** 與 DB 欄位一致的長度上限;先於 regex 檢查。 */
    private const MAX_LENGTH = 64;

    public function __construct(
        /** 編輯中舊 ID;只有停用時才可保留,null = 不可保留任何 stale 值。 */
        private readonly ?string $retainableStaleId = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // ⛔ Shape 先行:非 string 一律拒絕——不 cast、不查 DB、不產生 warning。
        if (! is_string($value)) {
            $fail(self::FAILED_MESSAGE);

            return;
        }

        // 歷史保留:與原值 exact 相等且呼叫端(僅停用時)允許,直接通過,不查 DB。
        if ($this->retainableStaleId !== null && $value === $this->retainableStaleId) {
            return;
        }

        // ⛔ 只有 canonical positive-integer string 才可觸碰資料庫。
        if (strlen($value) > self::MAX_LENGTH || preg_match('/\A[1-9][0-9]*\z/', $value) !== 1) {
            $fail(self::FAILED_MESSAGE);

            return;
        }

        $service = ProviderService::query()
            ->where('provider', IntegrationProvider::TheMostPanel->value)
            ->where('provider_service_id', $value)
            ->where('is_available', true)
            ->first();

        if ($service === null) {
            $fail(self::FAILED_MESSAGE);

            return;
        }

        /*
         * ⛔ R1:裝飾／分類列即使被觀察為 is_available,也不是真正可派單的
         * 服務——不能只靠前端隱藏,提交時同樣要用同一份判定拒絕。
         */
        if (DecorativeProviderServiceName::matches($service->name)) {
            $fail(self::FAILED_MESSAGE);
        }
    }
}
