<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A local order, created the moment checkout validates.
     *
     * The order exists before any payment is attempted so that a failure,
     * an abandoned attempt or a support enquiry always has something local to
     * refer to. Payment outcomes live in payment_attempts, never as a single
     * overwritten field here.
     */
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();

            // 對外只露這個；⛔ 不可用流水號，否則能被猜出別人的訂單。
            $table->string('reference', 32)->unique();

            // 防重複建單：同一次 checkout 只會有一個 token。
            $table->string('checkout_token', 64)->unique();

            $table->string('order_status')->index();
            $table->string('payment_status')->index();

            // 金額以「分」為單位的整數保存，⛔ 不用 float，避免累加誤差。
            $table->unsignedInteger('total_amount');
            $table->string('currency', 3)->default('TWD');

            // 聯絡與電子發票資料。⛔ 這些欄位不得出現在 URL 或一般 log。
            $table->string('customer_email');
            $table->string('customer_phone')->nullable();
            $table->string('invoice_kind');
            $table->string('personal_invoice_mode')->nullable();
            $table->string('carrier_number')->nullable();
            $table->string('love_code')->nullable();
            $table->string('buyer_tax_id')->nullable();
            $table->string('buyer_name')->nullable();

            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['order_status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
