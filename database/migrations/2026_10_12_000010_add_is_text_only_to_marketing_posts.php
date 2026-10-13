<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MK-9: the studio's "Text only" choice on a post. A photo-per-post campaign holds a draft with no photo back from
 * approval ("Needs a photo"); choosing Text only is the owner saying no photo is wanted, so it must be remembered
 * apart from a photo that is simply missing.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('marketing_posts', function (Blueprint $table) {
            $table->boolean('is_text_only')->default(false)->after('asset_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('marketing_posts', function (Blueprint $table) {
            $table->dropColumn('is_text_only');
        });
    }
};
