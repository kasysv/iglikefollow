<?php

use App\Enums\FulfillmentPayloadType;
use App\Support\M4aRollbackGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which provider service a product variant maps to.
     *
     * ⛔ A separate table rather than a column on `service_variants`. The
     * existing `external_sku` is a catalogue field copied into every order
     * snapshot; overloading it would mean a supplier configuration change
     * silently rewrote what past orders claim to have bought.
     *
     * `is_enabled` defaults to false, and even true does not authorise a real
     * dispatch: M4A has no HTTP client at all.
     */
    public function up(): void
    {
        Schema::create('fulfillment_mappings', function (Blueprint $table) {
            $table->id();

            // ⛔ restrict：款式被刪除時不得默默帶走它的供應商設定。
            $table->foreignId('service_variant_id')->constrained()->restrictOnDelete();

            $table->string('provider', 32);

            // ⛔ 不猜實際型別，也不 seed 真實值；M4A 沒有任何真實 service ID。
            $table->string('provider_service_id', 64);

            $table->string('payload_type', 32)->default(FulfillmentPayloadType::LinkQuantity->value);

            $table->boolean('is_enabled')->default(false);

            $table->timestamps();

            // 一個款式對一個 provider 只能有一筆設定。
            $table->unique(['service_variant_id', 'provider']);
        });

        $this->addValueConstraints();
    }

    public function down(): void
    {
        // ⛔ 三張表一起檢查：batch rollback 是反序執行的。
        M4aRollbackGuard::assertAllTablesAreEmpty();

        $this->dropValueConstraints();

        Schema::dropIfExists('fulfillment_mappings');
    }

    /**
     * Second-layer protection in the database itself.
     *
     * Model validation can be bypassed by a raw query, a seeder or a future
     * refactor. These say the same thing where nothing can go around them.
     */
    private function addValueConstraints(): void
    {
        $providers = "'themostpanel'";
        $payloads = "'".implode("','", FulfillmentPayloadType::values())."'";

        /*
         * ⛔ 前綴由呼叫端傳入，不用字串取代加上 NEW.：
         * 'provider' 這個字也出現在 provider_service_id 裡，盲目取代會產生
         * 無效 SQL。
         */
        $condition = fn (string $p) => "length(trim({$p}provider_service_id)) > 0"
            ." AND {$p}provider IN ({$providers})"
            ." AND {$p}payload_type IN ({$payloads})";

        $message = 'fulfillment_mappings: provider 與 payload_type 必須合法，provider_service_id 不得為空';

        match (DB::getDriverName()) {
            'sqlite' => $this->addSqliteTriggers($condition('NEW.'), $message),
            'mysql', 'mariadb', 'pgsql' => DB::statement(
                'ALTER TABLE fulfillment_mappings ADD CONSTRAINT fulfillment_mappings_values_check CHECK ('
                .$condition('').')'
            ),
            // ⛔ 未知 driver 就失敗，不靜默略過：略過會讓正式環境完全沒有這道保護。
            default => throw new RuntimeException(
                '⛔ 未支援的資料庫 driver，無法建立 fulfillment_mappings 的資料約束。'
            ),
        };
    }

    private function addSqliteTriggers(string $condition, string $message): void
    {
        foreach (['insert' => 'INSERT', 'update' => 'UPDATE'] as $suffix => $event) {
            DB::statement("
                CREATE TRIGGER fulfillment_mappings_values_check_{$suffix}
                BEFORE {$event} ON fulfillment_mappings
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
        if (! Schema::hasTable('fulfillment_mappings')) {
            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS fulfillment_mappings_values_check_insert');
            DB::statement('DROP TRIGGER IF EXISTS fulfillment_mappings_values_check_update');
        }
    }
};
