<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('skills', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->text('description');
            $table->string('category');
            $table->boolean('is_core')->default(true);

            // The supervision layer — the reason this application exists.
            $table->string('supervision');
            $table->text('supervision_note');

            // The instructions handed to the model. Kept verbatim so the body is
            // byte-identical between runs; anything interpolated per-request
            // would destroy the prompt cache and multiply cost roughly tenfold.
            $table->longText('body');
            $table->string('body_hash', 64);
            $table->unsignedInteger('token_estimate');

            // Provenance. `source_commit` is the library revision this row was
            // built from, so an audit record can name its exact source.
            $table->string('source_commit', 40)->nullable();
            $table->date('date_added')->nullable();
            $table->date('last_reviewed')->nullable();
            $table->string('license')->nullable();

            $table->timestamps();

            $table->index('category');
            $table->index('supervision');
            $table->index('is_core');
        });

        // Cross-references parsed from "(use nonprofit-x)" in each description.
        // These drive task sequencing and the "you will also need" prompts.
        Schema::create('skill_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_skill_id')->constrained('skills')->cascadeOnDelete();
            $table->foreignId('to_skill_id')->constrained('skills')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['from_skill_id', 'to_skill_id']);
        });

        // Raised when an import changes a skill's supervision level. Upstream
        // tiers are not stable, and an organization running a task that just
        // became expert-required needs to be told rather than discovering it.
        Schema::create('supervision_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('skill_id')->constrained()->cascadeOnDelete();
            $table->string('from_level');
            $table->string('to_level');
            $table->string('source_commit', 40)->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();

            $table->index('acknowledged_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supervision_changes');
        Schema::dropIfExists('skill_links');
        Schema::dropIfExists('skills');
    }
};
