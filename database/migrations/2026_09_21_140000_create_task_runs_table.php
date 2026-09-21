<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('skill_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();

            $table->string('status')->default('queued');

            // The level in force when this run started. Deliberately a copy:
            // the library revises its calls, and an audit record has to say
            // what the rule was at the time, not what it became afterwards.
            $table->string('supervision_at_run');
            $table->string('expert_gate_policy_at_run');

            $table->json('inputs')->nullable();
            $table->longText('output')->nullable();
            $table->text('failure_reason')->nullable();

            // What it cost and what produced it, for the board report and for
            // catching a prompt-cache regression before it multiplies the bill.
            $table->string('model')->nullable();
            $table->json('usage')->nullable();
            $table->string('skill_body_hash', 64)->nullable();
            $table->string('skill_source_commit', 40)->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('released_at')->nullable();

            // Set when work was let through without a credentialed sign-off.
            // Counted on the board report, so it is a column, not a lookup.
            $table->boolean('released_without_expert')->default(false);

            $table->timestamps();

            $table->index(['team_id', 'status']);
            $table->index(['team_id', 'created_at']);
        });

        Schema::create('approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_run_id')->constrained()->cascadeOnDelete();

            // Either a member of the organization, or an outside professional
            // acting through an invitation. Exactly one of these is set.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('expert_invitation_id')->nullable();

            $table->string('decision');
            $table->string('credential_type')->nullable();
            $table->string('credential_reference')->nullable();
            $table->string('approver_name')->nullable();
            $table->text('justification')->nullable();
            $table->timestamp('decided_at');
            $table->timestamps();

            $table->index('task_run_id');
        });

        // An outside accountant or attorney the organization already works
        // with, invited by link. No account, no vetting, no marketplace — the
        // professional relationship already exists outside this application.
        Schema::create('expert_invitations', function (Blueprint $table) {
            $table->id();
            $table->string('token', 64)->unique();
            $table->foreignId('task_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invited_by')->constrained('users')->cascadeOnDelete();
            $table->string('email');
            $table->string('name')->nullable();
            $table->string('credential_type')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            $table->index(['task_run_id', 'used_at']);
        });

        Schema::table('approvals', function (Blueprint $table) {
            $table->foreign('expert_invitation_id')
                ->references('id')->on('expert_invitations')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('approvals', function (Blueprint $table) {
            $table->dropForeign(['expert_invitation_id']);
        });

        Schema::dropIfExists('expert_invitations');
        Schema::dropIfExists('approvals');
        Schema::dropIfExists('task_runs');
    }
};
