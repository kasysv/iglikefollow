<?php

namespace App\Enums;

/**
 * The only three things this probe may ever ask the provider.
 *
 * ⛔ A closed enum, not a string parameter. The same API also exposes `add`,
 * `refill` and `cancel` — `add` places a real, paid order. If a caller could
 * pass an arbitrary action, then "discover the response format" and "spend
 * money at a supplier" would be the same code path with a different argument,
 * and the only thing standing between them would be whoever typed it.
 *
 * These three read. None of them creates, modifies or cancels anything.
 */
enum TheMostPanelReadOnlyAction: string
{
    /** 取得供應商服務清單。 */
    case Services = 'services';

    /** 取得帳戶餘額。 */
    case Balance = 'balance';

    /** 查詢一筆**已經存在**的訂單狀態。 */
    case Status = 'status';

    public function label(): string
    {
        return match ($this) {
            self::Services => '服務清單',
            self::Balance => '帳戶餘額',
            self::Status => '單筆訂單狀態',
        };
    }

    /**
     * ⛔ 只有 status 需要訂單編號，而且只能是一筆。
     *
     * 其他 action 帶上 order 參數毫無意義，只會多送出一個不必要的識別碼。
     */
    public function requiresOrderId(): bool
    {
        return $this === self::Status;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
