<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SB-5: work that is not on the ref yet. Extra checkouts of a project, and
 * story rows that live on a branch, in a worktree, or as untracked files.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('project_locations', function (Blueprint $table) {
            $table->id();
            // Locations are facts about a project; they go when the project goes.
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            // alias = a sibling clone registered by hand; worktree = found by `git worktree list` each refresh.
            $table->string('kind', 16);
            $table->string('path', 1024);
            $table->string('branch')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'kind']);
        });

        Schema::table('stories', function (Blueprint $table) {
            // null = on the project's ref (SB-2's snapshot); branch | worktree | untracked otherwise.
            $table->string('location_kind', 16)->nullable()->after('sha');
            // Human label: `branch <name>`, `worktree <path>`, `untracked in <path>`.
            $table->text('location')->nullable()->after('location_kind');
            $table->string('branch')->nullable()->after('location');

            // The same path now appears once on the ref and again per location, so the
            // SB-2 uniqueness no longer holds; lookups by path keep an ordinary index.
            $table->dropUnique(['project_id', 'path']);
            $table->index(['project_id', 'path']);
            $table->index(['project_id', 'location_kind']);
        });
    }

    /**
     * Reverse the migrations. Off-main rows are dropped first so SB-2's unique key can return.
     */
    public function down(): void
    {
        DB::table('stories')->whereNotNull('location_kind')->delete();

        Schema::table('stories', function (Blueprint $table) {
            $table->dropIndex(['project_id', 'location_kind']);
            $table->dropIndex(['project_id', 'path']);
            $table->unique(['project_id', 'path']);
            $table->dropColumn(['location_kind', 'location', 'branch']);
        });

        Schema::dropIfExists('project_locations');
    }
};
