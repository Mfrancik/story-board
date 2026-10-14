<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MK-5: which of the three ad styles (gallery, vault, poster) a campaign's posts are rendered in. Null reads as
 * gallery, so every campaign from before keeps the look closest to the kit without a data rewrite.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('marketing_campaigns', function (Blueprint $table) {
            $table->string('ad_style', 16)->nullable()->after('photo_mode');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('marketing_campaigns', function (Blueprint $table) {
            $table->dropColumn('ad_style');
        });
    }
};
