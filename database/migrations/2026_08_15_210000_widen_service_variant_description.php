<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The variant description is now a multi-line blurb, not one line.
     *
     * It was a VARCHAR(255). Chinese text costs 3 bytes per character there,
     * so a three-line description of ~90 characters already sits at ~254
     * bytes — one edit away from being silently truncated on MySQL. TEXT
     * removes the byte ceiling; the form keeps a character limit so the box
     * still cannot grow unbounded.
     */
    public function up(): void
    {
        Schema::table('service_variants', function (Blueprint $table) {
            $table->text('description')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('service_variants', function (Blueprint $table) {
            $table->string('description')->nullable()->change();
        });
    }
};
