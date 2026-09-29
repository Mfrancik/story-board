<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a refresh was last tried, whatever its outcome. `indexed_at` only moves
 * on success, so staleness keyed on it alone retried a failing project's fetch
 * on every page load.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->timestamp('refresh_attempted_at')->nullable()->after('indexed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('refresh_attempted_at');
        });
    }
};
