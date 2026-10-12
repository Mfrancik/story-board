<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MK-1: a project's recurring marketing campaigns (e.g. "TrueView Tuesday · Tue 19:00").
 * Generic on purpose: nothing here knows about coins (owner, 2026-10-12).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('marketing_campaigns', function (Blueprint $table) {
            $table->id();
            // A campaign means nothing without its project: removing the project removes its campaigns.
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            // ISO weekdays (1 = Monday … 7 = Sunday) the campaign posts on.
            $table->json('days');
            // Wall-clock time in `timezone`, so a 19:00 post stays at 19:00 across daylight-saving changes.
            $table->time('time');
            $table->string('timezone', 64);
            $table->boolean('is_enabled')->default(true);
            // {ideas: [keys], own: [{text, detail}]}: the idea-menu pool plus the owner's own topics (MK-7).
            $table->json('topics');
            $table->text('brief')->nullable();
            // per_post | favourites | text_only | ai_decides
            $table->string('photo_mode', 16);
            $table->timestamps();

            $table->unique(['project_id', 'slug']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('marketing_campaigns');
    }
};
