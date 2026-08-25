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
     * Domain separation for the retry token derivation.
     *
     * ⛔ 固定字串，讓這個 HMAC 的用途唯一。少了它，同一把 APP_KEY 在別處對
     * 同一份輸入做 HMAC 就會算出相同結果，兩個不相干的用途從此互相可預測。
     */
    private const ROTATION_DOMAIN = 'iglikefollow.checkout.retry.v1';

    /**
     * Start a new checkout over the same selection.
     *
     * 客人上一張訂單的付款全部收斂為失敗／取消／逾期之後，再按一次「前往付款」
     * 是**新的一次結帳**，必須有自己的 order、order reference、checkout token
     * 與 payment attempt reference；舊訂單原樣留著當歷史。token 是那個分界，
     * 因為 `StartCheckout` 就是靠它決定要沿用舊訂單還是建新的。
     *
     * ⭐ 新 token 由**前代 token** deterministic 推導，不是隨機產生。
     *
     * ⛔ R1 修正的正是這一點。初版每個 request 各自 `Str::uuid()`：兩個同時
     * 讀到相同舊 snapshot 的 request 會算出兩個**不同**的 token，於是
     * `orders.checkout_token` 的 unique constraint 完全不會衝突，客人一次雙擊
     * 就得到兩張新訂單——而 unique constraint 正是這條路徑唯一的並行防線。
     *
     * 改成 deterministic 之後，持有相同前代 token 的 request 一律算出同一個新
     * token，最終由既有的 DB unique constraint 收斂成一張新訂單。收斂條件因此
     * 只依賴「前代 token 相同」，⛔ 不依賴 session lock：lock 擋不住兩個 worker
     * 已經各自讀完 session 的情況，而那正是這個 race 的實際樣子。
     *
     * ⛔ 用 HMAC 而非裸雜湊。前代 token 就放在客人自己的 cookie 裡，若新 token
     * 只是它的公開變換（`sha256($old)` 之類），任何拿到舊 token 的人都能算出
     * 下一個 token。APP_KEY 讓推導需要 server secret 才做得出來。
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

        $previous = $stored['token'] ?? null;

        // ⛔ 沒有前代 token 就無從推導；此時不得改寫 session。
        if (! is_string($previous) || $previous === '') {
            return null;
        }

        $stored['token'] = $this->deriveToken($previous);

        $request->session()->put(self::KEY, $stored);

        return $stored['token'];
    }

    /**
     * The next checkout token in this retry chain.
     *
     * 以前代 token 為**唯一**輸入，配上 APP_KEY 做 domain-separated HMAC。
     *
     * ⛔ 「唯一輸入」是必要條件：若把時間、request id 或亂數混進來，兩個並行
     * request 就會再度算出不同的值，race 又回來了。
     *
     * ⛔ 也不能只由某個固定值（例如 order id）推導，否則第二次輪替會算回同一個
     * token，客人在第二次之後再也開不了新訂單。以前代推導形成一條每次都前進
     * 的鏈：t1 → t2 → t3，兩兩不同。
     *
     * 取十六進位前 48 字元：`orders.checkout_token` 上限 64 字，48 個 hex
     * 字元為 192 bits，遠超過不可猜測所需的安全邊界。
     */
    private function deriveToken(string $previous): string
    {
        return substr(
            hash_hmac('sha256', self::ROTATION_DOMAIN.'|'.$previous, (string) config('app.key')),
            0,
            48
        );
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
