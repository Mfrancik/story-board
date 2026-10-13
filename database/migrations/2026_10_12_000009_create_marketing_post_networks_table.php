<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MK-9: where a post went out. In phase 1 the owner posts by hand and "Mark as posted" stores the Facebook post link
 * here, so phase 2 can backfill engagement from `external_id`/`url` without asking again. One row per network.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('marketing_post_networks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('marketing_posts')->cascadeOnDelete();
            // facebook (the only network in phase 1)
            $table->string('network', 16);
            // posted (phase 2 adds scheduled, failed)
            $table->string('status', 16);
            // The network's own id for the post; phase 2 reads it from the URL or the API.
            $table->string('external_id')->nullable();
            $table->string('url', 2048)->nullable();
            // Reach, reactions, comments, shares as last synced (phase 2).
            $table->json('engagement')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['post_id', 'network']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('marketing_post_networks');
    }
};
