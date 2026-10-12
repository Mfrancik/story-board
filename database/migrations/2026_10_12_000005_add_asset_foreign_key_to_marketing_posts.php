<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MK-2: the foreign key MK-1 left off `marketing_posts.asset_id` until the assets table existed. Null on delete:
 * deleting a favourite photo leaves the posts that used it in place, photo-less, rather than deleting them.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('marketing_posts', function (Blueprint $table) {
            $table->foreign('asset_id')->references('id')->on('marketing_assets')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('marketing_posts', function (Blueprint $table) {
            $table->dropForeign(['asset_id']);
            // MySQL made an index of the same name for the constraint; leave the table as MK-1 created it.
            $table->dropIndex('marketing_posts_asset_id_foreign');
        });
    }
};
