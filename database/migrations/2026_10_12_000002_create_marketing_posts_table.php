<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MK-1: a project's marketing posts — drafts, approved, posted, skipped or
 * discarded. "Missed" is never stored: it is derived from an approved post whose
 * time has passed without being marked posted (BuildCampaignRunway).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('marketing_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            // Null for a one-off post; a deleted campaign leaves its posts (and their history) behind as one-offs.
            $table->foreignId('campaign_id')->nullable()->constrained('marketing_campaigns')->nullOnDelete();
            $table->dateTime('scheduled_for')->nullable();
            // draft | approved | posted | skipped | discarded
            $table->string('status', 16)->default('draft');
            $table->text('caption');
            // The post's photo; its foreign key to the assets table arrives with that table in MK-2.
            $table->unsignedBigInteger('asset_id')->nullable();
            // ai | manual | kit
            $table->string('source', 8);
            $table->json('generation_meta')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            // A campaign's posts by date (runway, approved-through); a project's posts by status (what needs approval).
            $table->index(['campaign_id', 'scheduled_for']);
            $table->index(['project_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('marketing_posts');
    }
};
