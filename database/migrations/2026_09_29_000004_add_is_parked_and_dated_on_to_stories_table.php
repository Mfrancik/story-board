<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Facts the home page groups by, computed once per refresh rather than per request (SB-3).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('stories', function (Blueprint $table) {
            // In an initiative folder whose README says `Status: draft group` at the ref.
            $table->boolean('is_parked')->default(false)->after('initiative');
            // First date in the story's Source line: when it was asked for. Orders "oldest first".
            $table->date('dated_on')->nullable()->after('source');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stories', function (Blueprint $table) {
            $table->dropColumn(['is_parked', 'dated_on']);
        });
    }
};
