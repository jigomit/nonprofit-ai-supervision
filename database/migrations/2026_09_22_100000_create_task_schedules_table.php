<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('skill_id')->constrained()->cascadeOnDelete();

            $table->string('cadence');

            // Anchored either to a fixed month, or to the organization's fiscal
            // year end. The second is what makes a shared calendar useful: a
            // 990 is due four and a half months after year end, whenever that
            // year happens to end.
            $table->boolean('anchored_to_fiscal_year_end')->default(false);
            $table->unsignedTinyInteger('anchor_month')->nullable();
            $table->unsignedTinyInteger('anchor_day')->default(1);
            $table->unsignedTinyInteger('months_after_anchor')->default(0);

            $table->date('next_due_at');
            $table->timestamp('last_started_at')->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(['team_id', 'skill_id']);
            $table->index(['team_id', 'is_active', 'next_due_at']);
        });

        Schema::table('task_runs', function (Blueprint $table) {
            // Which schedule, if any, this run satisfied.
            $table->foreignId('task_schedule_id')
                ->nullable()
                ->after('skill_id')
                ->constrained()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('task_runs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('task_schedule_id');
        });

        Schema::dropIfExists('task_schedules');
    }
};
