<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MK-2: one brand per project — the fixed look (colours, font, radius, shadow, logo) and voice every marketing draft
 * uses, plus the kit's idea menu that MK-7's topic picker reads.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('marketing_brands', function (Blueprint $table) {
            $table->id();
            // One brand per project: the unique index is what makes Settings' one form "create = edit".
            $table->foreignId('project_id')->unique()->constrained()->cascadeOnDelete();
            // {name, accent, ink, surface, font, radius, shadow}
            $table->json('values');
            // Sanitised SVG only (SanitiseSvg); never raw repo or upload bytes.
            $table->text('logo_svg')->nullable();
            // Where the logo came from, e.g. "resources/views/components/app-mark.blade.php @ origin/main · 52e084e".
            $table->string('logo_source')->nullable();
            $table->text('voice')->nullable();
            // {filler: string[], banned: string[]}
            $table->json('copy_rules');
            // [{category, ideas: [{title, text}]}] in the kit's order (a JSON object's keys would be re-sorted by MySQL),
            // parsed from the kit's captions file by marketing:seed-kit.
            $table->json('idea_menu');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('marketing_brands');
    }
};
