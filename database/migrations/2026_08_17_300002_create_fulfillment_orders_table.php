<?php

use App\Enums\FulfillmentAttentionReason;
use App\Enums\FulfillmentPayloadType;
use App\Enums\FulfillmentStatus;
use App\Support\M4aRollbackGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per order item, created only after a committed OrderPaid.
     *
     * The unique index on `order_item_id` is the real guarantee that a
     * redelivered event, a retried job or two concurrent workers cannot produce
     * two dispatches for the same item. Application checks come first for a
     * clearer error; the index is what makes it impossible.
     *
     * ⛔ `order_items.target_value` is deliberately not copied here. It is the
     * customer's account or post URL, it is encrypted at rest, and it is an
     * immutable order snapshot — a second copy would be a second place to leak
     * it from and a second thing to keep in step.
     *
     * ⛔ No `charge`, `currency`, `start_count` or `remains` columns. Those come
     * from a provider response nobody here has ever seen; inventing their types
     * now would bake in a guess that a real response then has to be bent to fit.
     * M4B adds them additively once there is evidence.
     */
    public function up(): void
    {
        Schema::create('fulfillment_orders', function (Blueprint $table) {
            $table->id();

            // ⛔ unique：一個商品項目最多一筆履約，這是防重複派單的最終防線。
            $table->foreignId('order_item_id')->unique()->constrained()->restrictOnDelete();

            // mapping 可能日後被刪；⛔ restrict，且快照獨立保存。
            $table->foreignId('fulfillment_mapping_id')->nullable()->constrained()->restrictOnDelete();

            $table->string('provider', 32);

            /*
             * 進入 ready 時凍結的設定快照。
             *
             * ⛔ 之後改 mapping 不得改動已經送出的單：對帳時要能回答「當時
             * 送的是哪一個 service ID」，而不是「現在設定成什麼」。
             */
            $table->string('provider_service_id_snapshot', 64)->nullable();
            $table->string('payload_type_snapshot', 32)->nullable();

            // ⛔ 單向 keyed hash，永遠無法還原成請求內容。
            $table->string('request_fingerprint', 64)->nullable();

            $table->string('provider_order_id', 64)->nullable();

            // ⛔ 只存本地 allowlist 的代碼，不存 provider 原文。
            $table->string('provider_status_code', 32)->nullable();

            $table->string('status', 32)->default(FulfillmentStatus::ConfigurationPending->value);
            $table->string('attention_code', 32)->nullable();

            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'provider']);
        });

        $this->addValueConstraints();
        $this->addProviderOrderIdUniqueIndex();
    }

    /**
     * The same provider order id must never appear twice for one provider.
     *
     * ⛔ Rows without an id are exempt: before submission every row has NULL,
     * and a plain unique index over (provider, NULL) would let exactly one
     * un-submitted row exist per provider — which would block the second item
     * of any order.
     *
     * SQLite and PostgreSQL express that with a partial index. MySQL does not
     * support one, but it already ignores NULLs in unique indexes, so a plain
     * unique index has the identical effect there.
     */
    private function addProviderOrderIdUniqueIndex(): void
    {
        match (DB::getDriverName()) {
            'sqlite', 'pgsql' => DB::statement(
                'CREATE UNIQUE INDEX fulfillment_orders_provider_order_id_unique
                 ON fulfillment_orders (provider, provider_order_id)
                 WHERE provider_order_id IS NOT NULL'
            ),
            'mysql', 'mariadb' => Schema::table('fulfillment_orders', function (Blueprint $table) {
                $table->unique(['provider', 'provider_order_id'], 'fulfillment_orders_provider_order_id_unique');
            }),
            default => throw new RuntimeException(
                '⛔ 未支援的資料庫 driver，無法建立 provider_order_id 唯一索引。'
            ),
        };
    }

    public function down(): void
    {
        M4aRollbackGuard::assertAllTablesAreEmpty();

        $this->dropValueConstraints();

        Schema::dropIfExists('fulfillment_orders');
    }

    private function addValueConstraints(): void
    {
        $statuses = "'".implode("','", FulfillmentStatus::values())."'";
        $reasons = "'".implode("','", FulfillmentAttentionReason::values())."'";
        $payloads = "'".implode("','", FulfillmentPayloadType::values())."'";

        /*
         * ⛔ 前綴由呼叫端傳入，不用字串取代：'status' 這個字也出現在
         * provider_status_code 裡，盲目取代會產生無效 SQL。
         */
        $condition = fn (string $p) => "{$p}status IN ({$statuses})"
            ." AND ({$p}attention_code IS NULL OR {$p}attention_code IN ({$reasons}))"
            ." AND ({$p}payload_type_snapshot IS NULL OR {$p}payload_type_snapshot IN ({$payloads}))"
            ." AND {$p}attempt_count >= 0"
            /*
             * ⛔ 已送出就必須有供應商單號。
             *
             * 沒有單號的 submitted 是一筆無法對帳的紀錄：我們宣稱送出去了，
             * 卻沒有任何東西可以拿去問對方。
             */
            ." AND NOT ({$p}status = 'submitted' AND {$p}provider_order_id IS NULL)";

        $message = 'fulfillment_orders: status／attention_code／payload_type 必須合法，'
            .'且 submitted 必須具備 provider_order_id';

        match (DB::getDriverName()) {
            'sqlite' => $this->addSqliteTriggers($condition('NEW.'), $message),
            'mysql', 'mariadb', 'pgsql' => DB::statement(
                'ALTER TABLE fulfillment_orders ADD CONSTRAINT fulfillment_orders_values_check CHECK ('
                .$condition('').')'
            ),
            default => throw new RuntimeException(
                '⛔ 未支援的資料庫 driver，無法建立 fulfillment_orders 的資料約束。'
            ),
        };
    }

    private function addSqliteTriggers(string $condition, string $message): void
    {
        foreach (['insert' => 'INSERT', 'update' => 'UPDATE'] as $suffix => $event) {
            DB::statement("
                CREATE TRIGGER fulfillment_orders_values_check_{$suffix}
                BEFORE {$event} ON fulfillment_orders
                FOR EACH ROW
                WHEN NOT ({$condition})
                BEGIN
                    SELECT RAISE(ABORT, '{$message}');
                END
            ");
        }
    }

    private function dropValueConstraints(): void
    {
        if (! Schema::hasTable('fulfillment_orders')) {
            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS fulfillment_orders_values_check_insert');
            DB::statement('DROP TRIGGER IF EXISTS fulfillment_orders_values_check_update');
        }
    }
};
