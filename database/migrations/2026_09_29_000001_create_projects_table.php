<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registered projects: local checkouts the board reads stories from, through git only.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            // A local checkout; the board only ever runs read-only git against it.
            $table->string('path', 1024);
            $table->string('ref')->default('origin/main');
            $table->boolean('is_enabled')->default(true);
            // pending (never refreshed) | ok | stale (fetch/index failed) | unreachable (not a repo).
            $table->string('state', 16)->default('pending');
            $table->char('sha', 40)->nullable();
            $table->timestamp('indexed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
