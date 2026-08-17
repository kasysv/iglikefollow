<?php

use App\Enums\FulfillmentEventCode;
use App\Enums\FulfillmentStatus;
use App\Support\M4aRollbackGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An append-only timeline of what happened to a fulfilment row.
     *
     * ⛔ There is no message column, and that is the point. A free-text field
     * would fill up with provider responses and exception strings — the two
     * things most likely to carry a credential or a customer's account — and
     * this table is shown in the admin. A closed code plus from/to status says
     * what happened without ever becoming a place where such text can land.
     */
    public function up(): void
    {
        Schema::create('fulfillment_events', function (Blueprint $table) {
            $table->id();

            // 履約列被刪時，時間線一起走；⛔ 它單獨存在沒有意義。
            $table->foreignId('fulfillment_order_id')->constrained()->cascadeOnDelete();

            $table->string('event_code', 32);
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32)->nullable();

            $table->timestamps();

            $table->index(['fulfillment_order_id', 'id']);
        });

        $this->addValueConstraints();
    }

    public function down(): void
    {
        M4aRollbackGuard::assertAllTablesAreEmpty();

        $this->dropValueConstraints();

        Schema::dropIfExists('fulfillment_events');
    }

    private function addValueConstraints(): void
    {
        $codes = "'".implode("','", FulfillmentEventCode::values())."'";
        $statuses = "'".implode("','", FulfillmentStatus::values())."'";

        // ⛔ 前綴由呼叫端傳入，不用字串取代加上 NEW.。
        $condition = fn (string $p) => "{$p}event_code IN ({$codes})"
            ." AND ({$p}from_status IS NULL OR {$p}from_status IN ({$statuses}))"
            ." AND ({$p}to_status IS NULL OR {$p}to_status IN ({$statuses}))";

        $message = 'fulfillment_events: event_code 與 status 必須是 allowlist 中的值';

        match (DB::getDriverName()) {
            'sqlite' => $this->addSqliteTriggers($condition('NEW.'), $message),
            'mysql', 'mariadb', 'pgsql' => DB::statement(
                'ALTER TABLE fulfillment_events ADD CONSTRAINT fulfillment_events_values_check CHECK ('
                .$condition('').')'
            ),
            default => throw new RuntimeException(
                '⛔ 未支援的資料庫 driver，無法建立 fulfillment_events 的資料約束。'
            ),
        };

        $this->addAppendOnlyGuard();
    }

    /**
     * ⛔ No event may be rewritten after the fact.
     *
     * A timeline that can be edited afterwards is not evidence. The application
     * never updates an event, and this makes that true even for a raw query or
     * a future refactor that forgets.
     *
     * ⛔ Deliberately UPDATE only, not DELETE. A DELETE trigger also fires for
     * the `ON DELETE CASCADE` from `fulfillment_orders` — which would make a
     * fulfilment row impossible to delete once it had any event, and would
     * abort the very rollback path M4aRollbackGuard exists to make safe. The
     * timeline should follow its parent out, not pin the parent in place.
     *
     * Deleting a fulfilment row on its own is already prevented upstream: its
     * `order_item_id` is `restrictOnDelete`, and nothing in the application
     * deletes one.
     */
    private function addAppendOnlyGuard(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            // ⛔ 其他 driver 由權限與應用層負責；不假裝這裡已經擋住。
            return;
        }

        $message = 'fulfillment_events 是 append-only：不得修改既有事件';

        DB::statement("
            CREATE TRIGGER fulfillment_events_append_only_update
            BEFORE UPDATE ON fulfillment_events
            FOR EACH ROW
            BEGIN
                SELECT RAISE(ABORT, '{$message}');
            END
        ");
    }

    private function addSqliteTriggers(string $condition, string $message): void
    {
        // ⛔ 只有 INSERT：UPDATE 由 append-only trigger 全面阻擋。
        DB::statement("
            CREATE TRIGGER fulfillment_events_values_check_insert
            BEFORE INSERT ON fulfillment_events
            FOR EACH ROW
            WHEN NOT ({$condition})
            BEGIN
                SELECT RAISE(ABORT, '{$message}');
            END
        ");
    }

    private function dropValueConstraints(): void
    {
        if (! Schema::hasTable('fulfillment_events')) {
            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS fulfillment_events_values_check_insert');
            DB::statement('DROP TRIGGER IF EXISTS fulfillment_events_append_only_update');
            DB::statement('DROP TRIGGER IF EXISTS fulfillment_events_append_only_delete');
        }
    }
};
