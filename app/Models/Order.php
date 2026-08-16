<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Order extends Model
{
    use HasFactory;

    protected $guarded = [];

    /*
     * 個資一律 encrypted at rest。
     *
     * 這些欄位是收款、開票與履約所必需，⛔ 不能刪除，但也不該在資料庫裡以明文
     * 存放——備份外流或 DB 被直接查詢時就是一次個資外洩。加密後 raw SQL 查不到
     * 明文，模型讀取則照常，遮罩與未來的 provider adapter 都不受影響。
     *
     * ⛔ 狀態、金額、商品名稱與 reference 不加密：那些要能篩選與對帳。
     */
    protected $casts = [
        'order_status' => OrderStatus::class,
        'payment_status' => PaymentStatus::class,
        'paid_at' => 'datetime',
        'customer_email' => 'encrypted',
        'customer_phone' => 'encrypted',
        'carrier_number' => 'encrypted',
        'love_code' => 'encrypted',
        'buyer_tax_id' => 'encrypted',
        'buyer_name' => 'encrypted',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function paymentAttempts(): HasMany
    {
        return $this->hasMany(PaymentAttempt::class)->orderBy('id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(OrderEvent::class)->orderBy('id');
    }

    /**
     * A public order number that cannot be guessed.
     *
     * Sequential ids would let a customer read another customer's order by
     * incrementing a number, so the reference is random and is the only
     * identifier shown outside the admin.
     */
    public static function newReference(): string
    {
        return 'IGL-'.strtoupper(Str::random(12));
    }

    /** Route model binding and admin lookups use the reference, never the id. */
    public function getRouteKeyName(): string
    {
        return 'reference';
    }

    public function isPaid(): bool
    {
        return $this->order_status === OrderStatus::Paid;
    }

    /** Email 只露首字與網域，⛔ 後台列表也不顯示完整地址。 */
    public function maskedEmail(): string
    {
        [$name, $domain] = array_pad(explode('@', (string) $this->customer_email, 2), 2, '');

        $head = mb_substr($name, 0, 1);

        return ($head === '' ? '*' : $head)
            .str_repeat('*', max(mb_strlen($name) - 1, 1))
            .($domain === '' ? '' : '@'.$domain);
    }

    public function maskedPhone(): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $this->customer_phone);

        return $digits === '' ? null
            : str_repeat('*', max(strlen($digits) - 3, 0)).substr($digits, -3);
    }

    /** 發票類型摘要；⛔ 不回顯完整統編、載具或愛心碼。 */
    public function invoiceSummary(): string
    {
        if ($this->invoice_kind === 'business') {
            return '公司統編電子發票（統編後 3 碼 '.substr((string) $this->buyer_tax_id, -3).'）';
        }

        return match ($this->personal_invoice_mode) {
            'mobile_barcode' => '個人電子發票（手機條碼載具）',
            'donation' => '個人電子發票（捐贈）',
            default => '個人電子發票（寄送至 Email）',
        };
    }
}
