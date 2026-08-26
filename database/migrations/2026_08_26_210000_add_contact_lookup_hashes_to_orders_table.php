<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keyed lookup hashes so a customer can find their own order.
 *
 * ⭐ `orders.customer_email` 與 `customer_phone` 是 encrypted cast：密文每次
 * 都不同，⛔ 無法用 `where()` 查詢。免會員訂單查詢需要一個可比對的值。
 *
 * ⛔ 存的是 `APP_KEY` 的 domain-separated HMAC-SHA256，不是明文、不是可逆
 * 加密、也不是無 key 的普通 hash——後者可以用字典離線暴力破解，因為 Email
 * 與手機的取值空間小到跑得完。詳見 `App\Support\ContactLookupHash`。
 *
 * ⛔ nullable：手機是選填欄位；既有訂單在 backfill 之前也還沒有值。
 *
 * ⛔ 建 index：查詢會直接以這兩個欄位過濾，沒有 index 就是每次全表掃描。
 * 這是**衍生資料**，可由既有欄位重建，因此 rollback 不需要 fail-closed
 * guard（與 `provider_remains` 那種來自 provider、無法重建的資料不同）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('customer_email_lookup_hash', 64)->nullable()->after('customer_phone');
            $table->string('customer_phone_lookup_hash', 64)->nullable()->after('customer_email_lookup_hash');

            $table->index('customer_email_lookup_hash');
            $table->index('customer_phone_lookup_hash');
        });
    }

    /**
     * ⛔ 安全可回退：這兩個欄位是衍生資料。
     *
     * 刪掉不會遺失任何無法重建的東西——`customer_email`／`customer_phone`
     * 仍在，隨時可以用 backfill command 重新算出來。⛔ 但回退前必須先確認
     * 沒有任何程式還在讀它（否則查詢會直接壞掉），這一點寫在 rollback 步驟裡，
     * 不是 migration 能檢查的。
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['customer_email_lookup_hash']);
            $table->dropIndex(['customer_phone_lookup_hash']);
            $table->dropColumn(['customer_email_lookup_hash', 'customer_phone_lookup_hash']);
        });
    }
};
