<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MK-5: the ad a post is drawn as. `ad` holds the ad spec (layout, headline, subline, cta, asset_id, crop, badge) and
 * where its render stands (`render`: status and the two PNG assets). Null until the post is first rendered, so no
 * existing row is rewritten; `marketing:render` fills it for posts from before.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('marketing_posts', function (Blueprint $table) {
            $table->json('ad')->nullable()->after('is_text_only');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('marketing_posts', function (Blueprint $table) {
            $table->dropColumn('ad');
        });
    }
};
