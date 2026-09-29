<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SB-18: one value per production metric per local day (board.timezone), so the
 * Production page can show a change against yesterday and 7 days ago and a
 * 30-day trend line. Every successful read upserts today's row: the latest read
 * of the day wins. Nothing is pruned (the story keeps every day).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('prod_snapshots', function (Blueprint $table) {
            $table->id();
            // History goes with its project and its metric: removing either removes its snapshots.
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('prod_metric_id')->constrained()->cascadeOnDelete();
            // The owner's local calendar day, not the UTC date of read_at.
            $table->date('day');
            // Counts today, but a custom SELECT may return a fraction (an average, a ratio).
            $table->decimal('value', 20, 4);
            $table->timestamp('read_at');
            $table->timestamps();

            $table->unique(['prod_metric_id', 'day']);
            // The page reads a project's last 30 days.
            $table->index(['project_id', 'day']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('prod_snapshots');
    }
};
