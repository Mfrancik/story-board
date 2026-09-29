<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SB-17: the numbers the board reads from a project's production database.
 * A preset keeps its table and columns in `config`; a custom metric keeps its
 * one SELECT in `sql`.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('prod_metrics', function (Blueprint $table) {
            $table->id();
            // Metrics belong to the project, not the connection row: removing the connection deletes
            // them explicitly (RemoveProdConnection); removing the project cascades.
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            // A preset's name (total_users, …) or `custom-<random>`; stable across saves so SB-18's history lines up.
            $table->string('key', 64);
            $table->string('kind', 16);
            $table->string('label');
            $table->json('config')->nullable();
            $table->text('sql')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();

            $table->unique(['project_id', 'key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('prod_metrics');
    }
};
