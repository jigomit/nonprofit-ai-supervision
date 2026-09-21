<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The starter kit owns `teams`, so the nonprofit-specific profile hangs
        // off it rather than being merged in. Keeps the kit upgradable.
        Schema::create('organization_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->unique()->constrained()->cascadeOnDelete();

            $table->string('entity_type')->nullable();
            $table->string('ein', 10)->nullable();
            $table->string('state_of_incorporation', 2)->nullable();
            $table->unsignedTinyInteger('fiscal_year_end_month')->nullable();
            $table->string('budget_band')->nullable();
            $table->text('mission')->nullable();

            // How this organization handles work that needs a credentialed
            // professional when none is available.
            $table->string('expert_gate_policy')->default('override_with_justification');

            // Special collections this organization opted into. Core categories
            // are always included, so only the opt-ins are stored.
            $table->json('collections')->nullable();

            $table->timestamp('onboarded_at')->nullable();
            $table->timestamps();
        });

        // The organization's working catalogue. A row exists for every skill it
        // could run; `enabled` is what it has actually turned on.
        Schema::create('team_skill', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('skill_id')->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(true);

            // An organization may demand more supervision than the library
            // does, never less. Enforced in TeamSkill, not just here.
            $table->string('supervision_override')->nullable();

            $table->timestamps();

            $table->unique(['team_id', 'skill_id']);
            $table->index(['team_id', 'enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_skill');
        Schema::dropIfExists('organization_profiles');
    }
};
