<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A display-only name snapshot alongside the existing service id snapshot.
     *
     * ⛔ Purely additive and free-text: unlike `provider_service_id_snapshot`
     * this has no allowlist to check against (a catalog `name` can be any
     * provider-controlled string), so it carries no CHECK constraint. It is
     * frozen once at Ready the same way the id snapshot is — a later mapping
     * change or catalog rename must never rewrite an already-submitted row's
     * history.
     *
     * ⛔ Existing rows keep this column `null`. Display code falls back to a
     * live `(provider, provider_service_id_snapshot)` catalog lookup, and
     * then to the order item's own service name — never to a blank value.
     */
    public function up(): void
    {
        Schema::table('fulfillment_orders', function (Blueprint $table) {
            $table->string('provider_service_name_snapshot', 255)
                ->nullable()
                ->after('provider_service_id_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('fulfillment_orders', function (Blueprint $table) {
            $table->dropColumn('provider_service_name_snapshot');
        });
    }
};
