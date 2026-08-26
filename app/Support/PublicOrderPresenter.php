<?php

namespace App\Support;

use App\Enums\FulfillmentStatus;
use App\Enums\OrderStatus;
use App\Models\FulfillmentOrder;
use App\Models\Order;
use App\Models\OrderItem;

/**
 * What a customer may see about their own order — an allowlist, not a filter.
 *
 * ⭐ 這是本輪最重要的安全邊界。公開查詢結果只准出現這些欄位：
 *
 *  - 本站訂單編號
 *  - 平台、服務、方案、購買數量
 *  - 客戶看得懂的狀態
 *  - 剩餘數量
 *
 * ⛔ **絕不輸出**：SMM／TheMostPanel 字樣、provider order ID、provider service
 * name／ID、raw status token、credential、API 資料、交付帳號／URL、Email、
 * 手機、付款技術細節、履約事件、內部 attention code。
 *
 * ⛔ 這裡刻意用「明確列出要輸出什麼」而不是「把不要的濾掉」。過濾式的作法
 * 在新增欄位時預設是**洩漏**：日後有人在 `OrderItem` 加一個欄位，過濾清單
 * 沒更新就直接出現在公開頁。allowlist 的預設是不輸出。
 *
 * ⛔ 也刻意不重用後台的 presenter：後台可以顯示 provider 原文，公開端不行。
 * 共用一份就等於一次改動同時影響兩邊，而其中一邊是給陌生人看的。
 */
final class PublicOrderPresenter
{
    /**
     * ⭐ Owner 於 2026-08-26 明確批准新增 `placed_at` 與 `payment_label`。
     *
     * ⛔ 前一輪曾把 `placed_at` 移除,理由是它「不在批准清單內」。那個理由
     * 當時成立;現在 Owner 已明確要求顯示訂單時間,所以它進來了。
     * ⭐ 判準始終是「Owner 批准了什麼」,不是「這個欄位看起來危不危險」——
     * 兩次相反的決定用的是同一條規則。
     *
     * ⛔ `payment_label` 是**固定字串**,不是從資料推導出來的。這一頁只會拿到
     * 付款成功的訂單(`FindOrdersForCustomer` 已在 SQL 層限定),所以標籤沒有
     * 第二種可能。⛔ 不從 `payment_status` 映射:那會讓一個未來的新狀態悄悄
     * 產生一個沒人設計過的標籤。
     *
     * @return array{reference: string, placed_at: string, payment_label: string, items: list<array<string, mixed>>}
     */
    public static function for(Order $order): array
    {
        return [
            'reference' => (string) $order->reference,
            'placed_at' => self::placedAt($order),
            'payment_label' => '付款成功',
            'items' => $order->items
                ->map(fn (OrderItem $item): array => self::item($order, $item))
                ->all(),
        ];
    }

    /**
     * The order time in the site's own timezone.
     *
     * ⭐ 明確轉成 `config('app.timezone')` 再格式化（施工單指定）。
     *
     * ⛔ 誠實說明現況：本站 `app.timezone` 是 `Asia/Taipei`，且**沒有**把
     * datetime 以 UTC 落盤，因此 `created_at` 取出來已經是台北時間——
     * 這行 `setTimezone()` 目前是 no-op，我實測確認過（拿掉它輸出完全相同）。
     *
     * ⛔ 那為什麼還留著？因為它讓「以本站時區顯示」成為這段程式**明講的
     * 保證**，而不是一個依賴全域設定碰巧相符的巧合。若日後有人把 `Order`
     * 的 `created_at` 改成 UTC cast（Laravel 11+ 的常見作法），沒有這行的
     * 版本會靜默開始顯示差 8 小時的時間，而客人只會覺得「這不是我的訂單」。
     *
     * ⛔ 我沒有為它寫一條「反證能失敗」的測試——在目前設定下它不可觀測，
     * 硬寫只會得到一條假裝有測到東西的測試。這一點已寫進結果文件。
     */
    private static function placedAt(Order $order): string
    {
        $createdAt = $order->created_at;

        // ⛔ 理論上 `created_at` 一定有值;仍不假設,避免公開頁因 null 而 500。
        if ($createdAt === null) {
            return '';
        }

        return $createdAt
            ->copy()
            ->setTimezone((string) config('app.timezone'))
            ->format('Y-m-d H:i');
    }

    /**
     * @return array<string, mixed>
     */
    private static function item(Order $order, OrderItem $item): array
    {
        /*
         * ⛔ 履約列可能不存在（尚未付款、或付款後還沒建立）。
         *
         * `OrderItem::fulfillmentOrder()` 是 HasOne——一個商品項目至多一筆
         * 履約列。⛔ 不存在時是 null，呼叫端必須處理，不得假設有值。
         */
        $fulfillment = $item->fulfillmentOrder;

        $target = self::target($item);
        $status = self::status($order, $fulfillment);

        return [
            // ⛔ 全部來自本站訂單快照，不是 provider 資料。
            'platform' => (string) $item->platform_name,
            'service' => (string) $item->service_name,
            'variant' => (string) $item->variant_label,
            'quantity' => (int) $item->quantity,
            'status' => $status,
            'status_tone' => self::tone($status),
            'remains' => self::remains($fulfillment, $status),

            /*
             * ⭐ Owner 批准：顯示客人**自己下單時提交**的帳號／網址。
             *
             * ⛔ 這是客人自己填的值,不是 provider 資料——顯示它不會洩漏我們
             * 用哪一家供應商。它只在三選二驗證通過後才會被輸出。
             */
            'target' => $target,
            'target_url' => self::targetUrl($target),
        ];
    }

    /**
     * The colour tone for a public status — a closed allowlist.
     *
     * ⛔⛔ 回傳的是**本站自己的語意 token**（`success`／`info`／`warning`／
     * `danger`），⛔ 不是 CSS class、⛔ 更不是任何 DB 值或供應商原文。
     *
     * ⭐ 為什麼多一層 token 而不是讓 Blade 直接 `match` 狀態文字：
     * Blade 拿到什麼就會印什麼。若讓它自己決定 class，任何日後從 DB 流進
     * `status` 的字串都有機會變成 class 屬性的一部分——那是一條把資料庫內容
     * 送進 HTML 屬性的路。這裡先收斂成四個固定 token，Blade 只能在自己的
     * 封閉 `match` 裡把 token 換成 class。
     *
     * ⛔ `status` 只可能是 `self::status()` 的四種輸出，因此這裡不需要
     * `default`——但仍然寫了一個，且它回傳最保守的 `warning`：若日後有人在
     * `status()` 新增第五種文字卻忘了這裡，畫面會是一個中性的琥珀色藥丸，
     * ⛔ 不會是「綠色的已完成」那種會誤導客人的預設。
     */
    private static function tone(string $status): string
    {
        return match ($status) {
            '已完成' => 'success',
            '進行中' => 'info',
            '準備中' => 'warning',
            '請聯絡客服' => 'danger',
            default => 'warning',
        };
    }

    /**
     * The delivery target exactly as the customer typed it.
     *
     * ⛔ 原樣輸出,⛔ 不修剪、不補 `https://`、不「修正」看起來像網址的東西。
     * 客人要能認出這就是他當初填的那一行;我們自作主張改過的版本會讓他懷疑
     * 自己填錯了。
     *
     * ⛔ `target_value` 是 encrypted cast,讀取即解密。解密失敗會拋出——
     * 那代表 `APP_KEY` 有問題,不該靜默變成空字串讓客人以為自己沒填。
     */
    private static function target(OrderItem $item): string
    {
        return (string) $item->target_value;
    }

    /**
     * The target as a safe, linkable URL — or null.
     *
     * ⛔⛔ 這個回傳值會直接變成 `href`,所以它是本輪的注入邊界。
     *
     * ⛔ **只接受 `http` 與 `https`**,⛔ 用 allowlist 而不是「把 javascript:
     * 擋掉」的 denylist。denylist 永遠少一個:`javascript:`、`JavaScript:`、
     * `data:`、`vbscript:`、`file:`、含跳脫字元的變形……列不完。allowlist
     * 的預設是「不可點」。
     *
     * ⛔ 另外要求 host 存在:`http:///path` 這種 scheme 對但沒有主機的值,
     * 不是一個能連到任何地方的連結。
     *
     * ⛔ 純帳號(`my_account`)本來就不是 URL,回 null——它會被當成文字顯示。
     */
    private static function targetUrl(string $target): ?string
    {
        $target = trim($target);

        if ($target === '') {
            return null;
        }

        /*
         * ⛔ 先用 `FILTER_VALIDATE_URL` 擋掉結構不合法的值,再自己檢查 scheme。
         * ⛔ 只做其中一項都不夠:`FILTER_VALIDATE_URL` 會**接受**
         * `javascript://comment%0aalert(1)` 這類值(它結構上是合法 URL),
         * 而只看 scheme 則會放行結構破碎的字串。
         */
        if (filter_var($target, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $scheme = parse_url($target, PHP_URL_SCHEME);
        $host = parse_url($target, PHP_URL_HOST);

        if (! is_string($scheme) || ! is_string($host) || $host === '') {
            return null;
        }

        // ⛔ 小寫比對:`HTTPS://` 與 `https://` 是同一個 scheme。
        if (! in_array(strtolower($scheme), ['http', 'https'], true)) {
            return null;
        }

        return $target;
    }

    /**
     * The customer-facing status.
     *
     * ⛔ **只由本站 enum 推導**，⛔ 絕不顯示 `provider_status_code`。原文是
     * 給客服對照 SMM 後台用的；對客人來說 `In progress` 既看不懂，也洩漏了
     * 我們用哪一家供應商。
     *
     * ⛔ 例外狀態一律顯示「請聯絡客服」，⛔ 不冒充「進行中」：
     *
     *  - `Partial`：部分完成，客人可能少拿了數量，需要人處理。
     *  - `Canceled`／`Failed`：這張單不會自己好起來。
     *  - `SubmissionUnknown`：結果不明，需要人工對帳。
     *
     * 把這四種顯示成「進行中」會讓客人一直等一個永遠不會到的結果——那比說
     * 「請聯絡客服」糟糕得多。
     */
    private static function status(Order $order, ?FulfillmentOrder $fulfillment): string
    {
        // ⛔ 還沒付款：顯示本站真實狀態，不假裝已經在跑。
        if (! $order->isPaid()) {
            return match ($order->order_status) {
                OrderStatus::PendingPayment => '等待付款',
                OrderStatus::Canceled => '請聯絡客服',
                default => '請聯絡客服',
            };
        }

        // 已付款但尚未建立履約列：誠實說「準備中」。
        if ($fulfillment === null) {
            return '準備中';
        }

        /*
         * ⛔ R1：**逐一窮舉** enum，⛔ 不用會把未知狀態默認成「進行中」的
         * `default`。
         *
         * 初版的 `default => '進行中'` 有兩個問題：`ConfigurationPending`
         * （mapping／開關／payload 尚未就緒，**根本還沒開始履約**）被誤報成
         * 進行中；而日後新增任何狀態也會自動被歸進「進行中」——一個安全預設
         * 應該是相反方向。
         */
        return match ($fulfillment->status) {
            FulfillmentStatus::Completed => '已完成',

            // 真正在跑的四個狀態。
            FulfillmentStatus::Ready,
            FulfillmentStatus::Submitting,
            FulfillmentStatus::Submitted,
            // ⛔ 對客人來說「排隊中」與「處理中」都是進行中;⛔ 不暴露 SMM 原文。
            FulfillmentStatus::Pending,
            FulfillmentStatus::Processing => '進行中',

            /*
             * ⛔ 需要人處理的五種狀態，統一「請聯絡客服」。
             *
             * 不細分原因：對客人來說下一步都是聯絡客服，而細分會洩漏我們與
             * 供應商之間發生了什麼。
             *
             * ⭐ `ConfigurationPending` 屬於這一類：它代表設定沒就緒，客人
             * 再等也不會好。
             */
            FulfillmentStatus::Partial,
            FulfillmentStatus::Canceled,
            FulfillmentStatus::Failed,
            FulfillmentStatus::SubmissionUnknown,
            FulfillmentStatus::ConfigurationPending => '請聯絡客服',
        };
    }

    /**
     * The remaining count, or a placeholder.
     *
     * ⛔ 只讀**已驗證落盤**的 `provider_remains`。
     *
     * ⛔ 不推算、不用購買數量代替：那會給客人一個看起來精確、實際上是我們
     * 編出來的數字。`null` 就誠實說「更新中」——那是真話（排程還沒問到）。
     *
     * ⛔ `0` 顯示 `0`（全部補完），不被 placeholder 吞掉。
     *
     * ⭐ 狀態是「請聯絡客服」時顯示 `-`，⛔ 不是「更新中」。
     * 「更新中」是在承諾這個數字待會就會有——但那五種狀態代表這張單卡住了、
     * 需要人介入，排程不會再帶回新的剩餘數量。⛔ 對一個永遠不會更新的欄位
     * 說「更新中」，是在請客人等一個不會來的東西。
     */
    private static function remains(?FulfillmentOrder $fulfillment, string $status): string
    {
        // ⛔ 先判狀態：卡住的單不該顯示任何「稍後就有」的暗示。
        if ($status === '請聯絡客服') {
            return '-';
        }

        if ($fulfillment === null || $fulfillment->provider_remains === null) {
            return '更新中';
        }

        return number_format($fulfillment->provider_remains);
    }
}
