<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MK-8: the earlier versions of a post. ReviseDraft copies the post here before rewriting it, with the feedback the
 * owner gave on that version, so a revision never loses what it replaced. The post row is always the current version.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('marketing_post_versions', function (Blueprint $table) {
            $table->id();
            // A version is part of its post's history: removing the post removes its history.
            $table->foreignId('post_id')->constrained('marketing_posts')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->text('caption');
            // Null on delete, as on the post: deleting a favourite photo leaves the history in place, photo-less.
            $table->foreignId('asset_id')->nullable()->constrained('marketing_assets')->nullOnDelete();
            // What the owner said about this version, which produced the next one.
            $table->text('feedback')->nullable();
            $table->json('chips')->nullable();
            $table->json('generation_meta')->nullable();
            $table->timestamp('created_at')->nullable();

            // One row per version of a post; also the index the studio reads a post's history by (MK-9).
            $table->unique(['post_id', 'version']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('marketing_post_versions');
    }
};
