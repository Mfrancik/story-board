<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MK-8: a post's version number (1 as drafted, +1 per revision; earlier versions live in marketing_post_versions) and
 * the copy-rules lint warnings saved with its current caption.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('marketing_posts', function (Blueprint $table) {
            $table->unsignedInteger('version')->default(1)->after('caption');
            // [{code, match}] from LintCaption: warnings only, a post is never blocked by them. Null = not linted.
            $table->json('lint')->nullable()->after('generation_meta');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('marketing_posts', function (Blueprint $table) {
            $table->dropColumn(['version', 'lint']);
        });
    }
};
