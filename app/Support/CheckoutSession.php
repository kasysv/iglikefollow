<?php

namespace App\Support;

use App\Models\ServiceVariant;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The product selection carried between the service page and /checkout.
 *
 * Only a variant id, a quantity and the originating service URL are stored.
 * No price, no total and no personal data: prices are re-read from the
 * database on every render and on final submission, so a stale session can
 * never charge an old amount, and there is nothing here worth leaking if the
 * session store is inspected.
 */
class CheckoutSession
{
    public const KEY = 'checkout.selection';

    /**
     * One-shot marker meaning "the next service page render may restore the
     * selection". It exists so the return link needs no query parameter: a
     * ?resume=1 URL would be a second crawlable address for the same page.
     */
    public const RESUME_KEY = 'checkout.resume_once';

    public function __construct(private readonly CatalogRepository $catalog) {}

    public function markResume(Request $request): void
    {
        $request->session()->put(self::RESUME_KEY, true);
    }

    /**
     * Consume the resume marker.
     *
     * Pull, not get: the marker must not survive into a refresh, otherwise a
     * clean URL would keep showing the previous selection instead of the
     * service's featured item.
     */
    public function pullResume(Request $request): bool
    {
        return (bool) $request->session()->pull(self::RESUME_KEY, false);
    }

    public function put(Request $request, ServiceVariant $variant, int $quantity): void
    {
        $service = $variant->service;
        $existing = $request->session()->get(self::KEY);

        $request->session()->put(self::KEY, [
            'variant_id' => $variant->id,
            'quantity' => $quantity,
            // 回到商品頁用；⛔ 存 URL 而非個資。
            'return_url' => $service->primaryUrl(),
            /*
             * 這一次選購的識別碼，用來防止重複建單。
             *
             * 改數量或換服務項目時沿用同一個 token：客人回頭修改仍是同一次結帳，
             * 不該因此產生第二張訂單。token 會寫進 orders.checkout_token，
             * ⛔ 由 DB unique constraint 做最終保障，而非只靠應用層判斷。
             */
            'token' => $existing['token'] ?? (string) Str::uuid(),
        ]);
    }

    /** 這一次結帳的識別碼；⛔ 沒有選購資料時不得憑空產生。 */
    public function token(Request $request): ?string
    {
        $stored = $request->session()->get(self::KEY);

        return is_array($stored) ? ($stored['token'] ?? null) : null;
    }

    /**
     * Start a new checkout over the same selection.
     *
     * 客人上一張訂單的付款全部收斂為失敗／取消／逾期之後，再按一次「前往付款」
     * 是**新的一次結帳**，必須有自己的 order、order reference、checkout token
     * 與 payment attempt reference；舊訂單原樣留著當歷史。token 是那個分界，
     * 因為 `StartCheckout` 就是靠它決定要沿用舊訂單還是建新的。
     *
     * ⛔ 只換 token，不動 variant／quantity／return_url：客人沒有重選商品，
     * 把選購一起清掉會把他踢回商品頁重來一次。
     *
     * ⛔ 只在這裡換。散落各處直接寫 session 陣列，等於有好幾份各自會漂移的
     * 輪替規則，而其中任何一份漏掉 token 都會靜默地退回沿用舊訂單。
     *
     * ⛔ 沒有選購資料時什麼都不做，也不回傳新 token：`token()` 的契約是
     * 「沒有選購就沒有 token」，這裡不得憑空造出一個。
     */
    public function rotateToken(Request $request): ?string
    {
        $stored = $request->session()->get(self::KEY);

        if (! is_array($stored) || ! isset($stored['variant_id'], $stored['quantity'])) {
            return null;
        }

        $stored['token'] = (string) Str::uuid();

        $request->session()->put(self::KEY, $stored);

        return $stored['token'];
    }

    /**
     * The current selection, re-validated against the live catalogue.
     *
     * Returns null when the session is missing or expired, when the variant
     * has since been unpublished, or when the stored quantity no longer fits
     * the variant's bounds — an admin may have changed them after the
     * customer left the service page. Callers redirect instead of failing.
     *
     * @return array{variant: ServiceVariant, quantity: int, amount: int, return_url: string}|null
     */
    public function resolve(Request $request): ?array
    {
        $stored = $request->session()->get(self::KEY);

        if (! is_array($stored) || ! isset($stored['variant_id'], $stored['quantity'])) {
            return null;
        }

        $variant = $this->catalog->findPurchasableVariant($stored['variant_id']);

        if ($variant === null) {
            return null;
        }

        $quantity = (int) $stored['quantity'];

        if (! $variant->quantityIsValid($quantity)) {
            return null;
        }

        return [
            'variant' => $variant,
            'quantity' => $quantity,
            // 金額每次都重算，⛔ 不從 session 讀取先前的價格。
            'amount' => $variant->amountFor($quantity),
            'return_url' => $this->returnUrl($stored, $variant),
        ];
    }

    public function forget(Request $request): void
    {
        // 選購資料清掉時，⛔ 不可留下孤兒 marker。
        $request->session()->forget([self::KEY, self::RESUME_KEY]);
    }

    /**
     * @param  array<string, mixed>  $stored
     */
    private function returnUrl(array $stored, ServiceVariant $variant): string
    {
        $stored = $stored['return_url'] ?? null;

        // ⛔ 只接受本站 route 產生的 URL，不讓 session 值變成開放轉址。
        $expected = $variant->service->primaryUrl();

        return is_string($stored) && $stored === $expected ? $stored : $expected;
    }
}
