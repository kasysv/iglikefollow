<?php

use App\Support\HomeHighlightsRollbackGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Make the homepage's three selling points editable.
     *
     * Everything around them — eyebrow, h1, intro, CTA — moved into settings
     * during M2A, but this strip stayed hardcoded in the Blade template, so
     * changing a word needed a developer and a deploy.
     *
     * Six nullable columns rather than one JSON blob: each is a short label the
     * editor fills in directly, and a JSON column would need its own shape
     * validation to stop a malformed save from breaking the homepage render.
     *
     * ⛔ Nullable with no default. Existing rows keep rendering the current text
     * through the template's fallback, so this migration changes no pixel on the
     * live page until someone deliberately edits a field.
     */
    public function up(): void
    {
        Schema::table('site_settings', function (Blueprint $table) {
            $table->string('home_highlight_1_title')->nullable()->after('home_intro');
            $table->string('home_highlight_1_body')->nullable()->after('home_highlight_1_title');
            $table->string('home_highlight_2_title')->nullable()->after('home_highlight_1_body');
            $table->string('home_highlight_2_body')->nullable()->after('home_highlight_2_title');
            $table->string('home_highlight_3_title')->nullable()->after('home_highlight_2_body');
            $table->string('home_highlight_3_body')->nullable()->after('home_highlight_3_title');
        });
    }

    /**
     * ⛔ Fails closed when the columns hold editor-written copy.
     *
     * Dropping them destroys that text permanently — there is no other copy,
     * and re-running `up()` restores the columns but not their contents. An
     * earlier version only warned about this in a comment, which protects
     * nobody: a comment is read by whoever already thought to check.
     *
     * When the columns are genuinely empty the rollback proceeds normally.
     */
    public function down(): void
    {
        HomeHighlightsRollbackGuard::assertNoHighlightContent();

        Schema::table('site_settings', function (Blueprint $table) {
            $table->dropColumn(HomeHighlightsRollbackGuard::COLUMNS);
        });
    }
};
