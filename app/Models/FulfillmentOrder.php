<?php

namespace App\Models;

use App\Enums\FulfillmentAttentionReason;
use App\Enums\FulfillmentEventCode;
use App\Enums\FulfillmentPayloadType;
use App\Enums\FulfillmentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One order item's journey through a provider.
 *
 * ⛔ Created only after a committed `OrderPaid`, one per item, enforced by a
 * unique index. Everything about this model assumes the expensive mistake is
 * sending twice, not sending late.
 */
class FulfillmentOrder extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'status' => FulfillmentStatus::class,
        'attention_code' => FulfillmentAttentionReason::class,
        'payload_type_snapshot' => FulfillmentPayloadType::class,
        'attempt_count' => 'integer',
        /*
         * ⭐ provider 回報的剩餘數量。
         *
         * ⛔ cast 成 integer 而非留字串：後台要能顯示 `0`，而 `'0'` 在
         * truthy 判斷下會被當成空值。nullable 保留「尚未取得」（null）與
         * 「對方回報 0」（已補完）的區別。
         */
        'provider_remains' => 'integer',
        // ⭐ 起始值：與 remains 同規則，null／0 語意相同。
        'provider_start_count' => 'integer',
        'sequence_no' => 'integer',
        'quantity_override' => 'integer',
        'suggested_quantity_snapshot' => 'integer',
        /*
         * ⛔⛔ 更換連結是客人的交付目標,與 `order_items.target_value` 同等敏感。
         * ⛔ 必須加密落盤——DB 外流時它不該是明文。
         */
        'target_value_override' => 'encrypted',
        'submitted_at' => 'datetime',
        'last_synced_at' => 'datetime',
    ];

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    /** 這一批取代的上一批；⛔ 第 1 批為 null。 */
    public function replaces(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaces_fulfillment_order_id');
    }

    /**
     * 取代這一批的下一批；⛔ 最多一個（由 unique index 保證）。
     *
     * ⭐ 「已被更換」＝ `replacement()` 存在。這是判斷一批是否仍為鏈尾的唯一
     * 依據，⛔ 不看 status——Owner 可以在任何 status 下更換。
     */
    public function replacement(): HasOne
    {
        return $this->hasOne(self::class, 'replaces_fulfillment_order_id');
    }

    /** 建立這筆更換的 Owner；⛔ 第 1 批為 null。 */
    public function replacementCreator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'replacement_created_by_user_id');
    }

    /** 這一批是不是 Owner 建立的更換履約。 */
    public function isReplacement(): bool
    {
        return (int) $this->sequence_no > 1;
    }

    /**
     * The delivery target this batch is actually sent with.
     *
     * ⛔⛔ 第 1 批讀**不可變的** `order_items` 快照；更換批次讀**自己這一列**
     * 的 encrypted override。
     *
     * ⛔ 更換時絕不改寫 `order_items.target_value`：那是客人下單當下同意的
     * 內容，是訂單的事實。改寫它會讓歷史看起來像客人從一開始就填了新連結。
     */
    public function effectiveTarget(): string
    {
        if ($this->isReplacement()) {
            return (string) $this->target_value_override;
        }

        return (string) ($this->orderItem?->target_value ?? '');
    }

    /**
     * The quantity this batch is actually sent with.
     *
     * ⛔ 同上：更換批次用 Owner 實際輸入的數量，⛔ 不是原訂購量。
     */
    public function effectiveQuantity(): int
    {
        if ($this->isReplacement()) {
            return (int) $this->quantity_override;
        }

        return (int) ($this->orderItem?->quantity ?? 0);
    }

    public function mapping(): BelongsTo
    {
        return $this->belongsTo(FulfillmentMapping::class, 'fulfillment_mapping_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(FulfillmentEvent::class)->orderBy('id');
    }

    /**
     * Append one entry to the timeline.
     *
     * ⛔ Takes a code from a closed enum, never a message. There is nowhere in
     * this table for provider text to land, which is the only reliable way to
     * keep it out.
     */
    public function recordEvent(
        FulfillmentEventCode $code,
        ?FulfillmentStatus $from = null,
        ?FulfillmentStatus $to = null,
    ): FulfillmentEvent {
        return $this->events()->create([
            'event_code' => $code->value,
            'from_status' => $from?->value,
            'to_status' => $to?->value,
        ]);
    }

    /**
     * A one-way fingerprint of what would be sent.
     *
     * ⛔ A keyed hash, not an encryption: nothing here should ever be turned
     * back into a request. It answers only "is this the same call as last
     * time".
     *
     * ⛔ Keyed with the app key rather than a plain hash. The inputs are small
     * and guessable — a service id and a round quantity — so an unkeyed digest
     * could be reversed by trying candidates until one matched.
     */
    public static function fingerprint(array $payload): string
    {
        ksort($payload);

        return hash_hmac(
            'sha256',
            json_encode($payload, JSON_UNESCAPED_UNICODE),
            (string) config('app.key'),
        );
    }

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    /**
     * The service name to show a human, best available source.
     *
     * ⛔ Three tiers, in order: the frozen snapshot taken when this row became
     * Ready; failing that, a live catalog lookup keyed on the exact
     * `(provider, provider_service_id_snapshot)` pair (never id alone — a
     * second provider could reuse the same numeric id); failing that, this
     * site's own service name with an explicit "not found" marker, never a
     * blank string or a guess.
     *
     * ⛔ A row with no id snapshot at all (blocked before Ready) has nothing
     * to look up — it falls straight to the order item's name, unmarked,
     * since there was never a provider service to find.
     */
    public function displayServiceName(): string
    {
        if (filled($this->provider_service_name_snapshot)) {
            return $this->provider_service_name_snapshot;
        }

        if (filled($this->provider_service_id_snapshot)) {
            $liveName = ProviderService::query()
                ->where('provider', $this->provider)
                ->where('provider_service_id', $this->provider_service_id_snapshot)
                ->value('name');

            if (filled($liveName)) {
                return $liveName;
            }

            return ($this->orderItem?->service_name ?? '未知服務').'（SMM 目錄未找到）';
        }

        return $this->orderItem?->service_name ?? '未知服務';
    }

    /**
     * The status exactly as TheMostPanel reported it.
     *
     * ⭐ Owner 要求後台顯示 provider 的**原文**，不再把 `In progress` 譯成
     * 「處理中」、`Completed` 譯成「已完成」。原因很實際：客服在對照 SMM 後台
     * 排查時，看到的必須是同一個字串。
     *
     * ⛔ 只回傳 `provider_status_code`——那個欄位只可能存有 gateway allowlist
     * 中的 exact token，因為 unrecognised 的回應根本不會寫入它。
     *
     * ⛔ 尚未取得時回固定的本地占位，**不拿內部 enum 的 label 冒充 provider
     * 回傳**。內部狀態（`ready`／`submitting`／`configuration_pending`）是我們
     * 描述自己處境的詞，任何供應商都不會這樣回報；把它顯示在「SMM 狀態」
     * 欄位會讓人以為那是對方說的。
     */
    public function displayProviderStatus(): string
    {
        return filled($this->provider_status_code)
            ? (string) $this->provider_status_code
            : '尚未取得';
    }

    /**
     * The provider's remaining count, as text for the admin.
     *
     * ⛔ `0` 必須顯示為 `0`，不得被 placeholder 吞掉——它代表「全部補完」，
     * 與 `null`（還沒問到）是兩件完全不同的事。這就是為什麼這裡用
     * `=== null` 而不是 `filled()`／truthy 判斷。
     */
    public function displayRemains(): string
    {
        return $this->provider_remains === null
            ? '尚未取得'
            : number_format($this->provider_remains);
    }

    /**
     * The provider's starting count, as text for the admin.
     *
     * ⛔ 與 `displayRemains()` 完全相同的規則：`0` 必須顯示為 `0`（開始前本來
     * 就是 0），⛔ 不得被 placeholder 吞掉；`null` 才是「尚未取得」。
     * 這就是為什麼用 `=== null` 而不是 `filled()`／truthy 判斷。
     */
    public function displayStartCount(): string
    {
        return $this->provider_start_count === null
            ? '尚未取得'
            : number_format($this->provider_start_count);
    }

    /**
     * ⛔ Never submit twice.
     *
     * A row that already carries a provider order id has been accepted, even if
     * our own status somehow disagrees — that id is proof something exists on
     * their side.
     */
    public function canBeSubmitted(): bool
    {
        return $this->status === FulfillmentStatus::Ready
            && $this->provider_order_id === null;
    }
}
