<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Give the homepage CTA an explicit target.
     *
     * Until now 'platform'/'service' meant "whichever published record sorts
     * first", so re-ordering the catalogue silently moved the homepage's main
     * button. The target is now pinned to a chosen record.
     */
    public function up(): void
    {
        Schema::table('site_settings', function (Blueprint $table) {
            $table->foreignId('primary_cta_platform_id')->nullable()->after('primary_cta_route')
                ->constrained('platforms')->nullOnDelete();
            $table->foreignId('primary_cta_service_id')->nullable()->after('primary_cta_platform_id')
                ->constrained('services')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('site_settings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('primary_cta_platform_id');
            $table->dropConstrainedForeignId('primary_cta_service_id');
        });
    }
};
