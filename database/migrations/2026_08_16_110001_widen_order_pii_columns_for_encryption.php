<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Make room for ciphertext in the columns that hold personal data.
     *
     * Email, phone, carrier, love code, tax id, buyer name and the fulfilment
     * target are all required to take payment, issue an invoice and deliver
     * the service, so none can be dropped — but they should not sit in the
     * database as plain text.
     *
     * Laravel's encrypted cast produces a base64 envelope several times longer
     * than the input and of unpredictable length, so these become TEXT.
     * ⛔ No index is added to any of them: an index over ciphertext cannot
     * answer a search anyway, and would only leak equality information.
     */
    /** 選填欄位維持 nullable；⛔ customer_email 仍為必填，不得放寬。 */
    private const NULLABLE = [
        'customer_phone',
        'carrier_number',
        'love_code',
        'buyer_tax_id',
        'buyer_name',
    ];

    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->text('customer_email')->change();

            foreach (self::NULLABLE as $column) {
                $table->text($column)->nullable()->change();
            }
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->text('target_value')->change();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('customer_email')->change();

            foreach (self::NULLABLE as $column) {
                $table->string($column)->nullable()->change();
            }
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->string('target_value')->change();
        });
    }
};
