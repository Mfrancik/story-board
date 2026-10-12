<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MK-8: the owner's taste, as examples rather than training: likes and dislikes (with reasons) on posts and the
 * written feedback given when revising one. BuildTasteExamples reads the latest of each into every prompt. Taste
 * shapes the words only; the brand's look is never in here.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('marketing_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            // A deleted campaign's feedback still counts for its project, as its posts outlive it as one-offs.
            $table->foreignId('campaign_id')->nullable()->constrained('marketing_campaigns')->nullOnDelete();
            $table->foreignId('post_id')->constrained('marketing_posts')->cascadeOnDelete();
            // 1 liked, -1 disliked, null for written feedback only.
            $table->tinyInteger('vote')->nullable();
            $table->json('reasons')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('created_at')->nullable();

            // BuildTasteExamples: a project's latest feedback.
            $table->index(['project_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('marketing_feedback');
    }
};
