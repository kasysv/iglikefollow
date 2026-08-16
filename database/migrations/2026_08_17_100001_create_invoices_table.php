<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One e-invoice per paid order.
     *
     * ⛔ No buyer details are copied here — no email, phone, tax id, company
     * name or carrier. The order already stores those, encrypted, and a second
     * copy would be a second place for personal data to leak from while adding
     * nothing: this table's job is to record what happened with the provider.
     *
     * The unique key on order_id is the database-level guarantee that a single
     * order can never be invoiced twice, no matter how many times a queue
     * redelivers the job or how many OrderPaid events arrive.
     */
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();

            // ⛔ 一張訂單只能有一張發票；這是資料庫層的最終保證。
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();

            $table->string('provider', 32);
            $table->string('status', 32);

            // 整數台幣，與已付款訂單金額相同；⛔ 不接受小數。
            $table->unsignedInteger('amount');
            $table->string('currency', 3)->default('TWD');

            // provider 回傳的識別；發票號碼與隨機碼是對帳依據，非個資。
            $table->string('provider_reference')->nullable();
            $table->string('invoice_number', 32)->nullable();
            $table->string('random_code', 8)->nullable();

            // ⛔ 只存整理過的錯誤碼與訊息，不存 raw response。
            $table->string('failure_code', 64)->nullable();
            $table->string('failure_message')->nullable();

            $table->timestamp('issued_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->timestamp('allowance_at')->nullable();
            // 結果不明時記錄需要人工確認的時間點。
            $table->timestamp('reconciliation_required_at')->nullable();

            $table->timestamps();

            $table->index('status');
            $table->index('invoice_number');
        });
    }

    /**
     * ⛔ Refuses to drop a table that holds issued invoices.
     *
     * An invoice is a tax record: the authority has its own copy, and dropping
     * ours silently leaves this business unable to say what it issued. A
     * rollback is a development convenience, so it gives way to the data — the
     * operator must export and clear deliberately, not discover the loss later.
     */
    public function down(): void
    {
        if (! Schema::hasTable('invoices')) {
            return;
        }

        $count = DB::table('invoices')->count();

        if ($count > 0) {
            throw new RuntimeException(
                "無法回滾：invoices 已有 {$count} 筆發票紀錄，刪表會失去稅務憑證。"
                .'請先匯出並確認後再手動清除。'
            );
        }

        Schema::dropIfExists('invoices');
    }
};
