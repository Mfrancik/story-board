<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The latest story-index snapshot of each project: one row per story file at the project's ref.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('stories', function (Blueprint $table) {
            $table->id();
            // A snapshot belongs to its project; unregistering a project drops its snapshot.
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            // Nullable: a file whose name carries no story ID is still stored, with a parse error.
            $table->string('story_id', 32)->nullable();
            $table->string('title', 512)->nullable();
            // Raw first word of `Status:` — kept even when outside the vocabulary, so it can be shown.
            $table->string('status', 64)->nullable();
            $table->string('initiative')->nullable();
            $table->string('journey')->nullable();
            $table->string('path', 512);
            $table->text('source')->nullable();
            $table->json('depends_on');
            $table->json('mockups');
            $table->json('parse_errors');
            $table->char('sha', 40);
            $table->timestamps();

            $table->unique(['project_id', 'path']);
            $table->index(['project_id', 'story_id']);
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stories');
    }
};
