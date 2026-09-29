<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SB-17: one read-only production database connection per project. Host,
 * database, username and password are stored encrypted (the model's `encrypted`
 * casts, keyed by APP_KEY), so every one of them is a TEXT column: ciphertext is
 * several times longer than the value and has no fixed length.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('prod_connections', function (Blueprint $table) {
            $table->id();
            // One connection per project; it is a fact about the project and goes with it.
            $table->foreignId('project_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('host');
            $table->unsignedSmallInteger('port')->default(3306);
            $table->text('database');
            $table->text('username');
            $table->text('password');
            $table->boolean('use_ssl')->default(true);
            // When SHOW GRANTS last proved this user SELECT-only.
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('prod_connections');
    }
};
