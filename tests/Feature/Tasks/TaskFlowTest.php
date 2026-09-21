<?php

use App\Actions\SyncTeamCatalogue;
use App\Enums\ApprovalDecision;
use App\Enums\ExpertGatePolicy;
use App\Enums\SupervisionLevel;
use App\Enums\TaskRunStatus;
use App\Enums\TeamRole;
use App\Models\ExpertInvitation;
use App\Models\OrganizationProfile;
use App\Models\Skill;
use App\Models\TaskRun;
use App\Models\User;
use App\Services\ExecutionResult;
use App\Services\TaskExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = $this->user->personalTeam();
    $this->base = '/'.$this->team->slug;

    app()->bind(TaskExecutor::class, fn () => new class implements TaskExecutor
    {
        public function execute(TaskRun $run): ExecutionResult
        {
            return new ExecutionResult('A draft of the work.', 'claude-opus-5', []);
        }
    });
});

function enable(mixed $team, array $attributes = []): Skill
{
    $skill = Skill::factory()->create($attributes);
    app(SyncTeamCatalogue::class)->handle($team);

    return $skill;
}

it('runs a task from the catalogue and lands it at its gate', function () {
    $skill = enable($this->team, ['supervision' => SupervisionLevel::Review->value]);

    $this->actingAs($this->user)
        ->post($this->base.'/tasks', ['skill' => $skill->slug, 'notes' => 'For the gala.'])
        ->assertRedirect();

    $run = TaskRun::query()->sole();

    expect($run->status)->toBe(TaskRunStatus::AwaitingReview)
        ->and($run->inputs['notes'])->toBe('For the gala.');

    $this->actingAs($this->user)
        ->get($this->base.'/tasks/'.$run->id)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('tasks/Show')
            ->where('run.status', 'awaiting_review')
            ->where('run.output', fn (string $html) => str_contains($html, 'A draft of the work.'))
        );
});

it('will not run a task outside the catalogue', function () {
    $skill = Skill::factory()->create();

    $this->actingAs($this->user)
        ->post($this->base.'/tasks', ['skill' => $skill->slug])
        ->assertSessionHasErrors('skill');

    expect(TaskRun::count())->toBe(0);
});

it('releases work when a member approves it', function () {
    $skill = enable($this->team, ['supervision' => SupervisionLevel::Review->value]);
    $this->actingAs($this->user)->post($this->base.'/tasks', ['skill' => $skill->slug]);
    $run = TaskRun::query()->sole();

    $this->actingAs($this->user)
        ->post($this->base.'/tasks/'.$run->id.'/decision', ['decision' => 'approved'])
        ->assertRedirect();

    expect($run->refresh()->status)->toBe(TaskRunStatus::Released);
});

it('refuses to release expert work on a bare approval', function () {
    $skill = enable($this->team, ['supervision' => SupervisionLevel::ExpertRequired->value]);
    $this->actingAs($this->user)->post($this->base.'/tasks', ['skill' => $skill->slug]);
    $run = TaskRun::query()->sole();

    $this->actingAs($this->user)
        ->post($this->base.'/tasks/'.$run->id.'/decision', ['decision' => 'approved'])
        ->assertSessionHasErrors('decision');

    expect($run->refresh()->status)->toBe(TaskRunStatus::AwaitingExpert);
});

it('only lets an owner release without an expert', function () {
    $skill = enable($this->team, ['supervision' => SupervisionLevel::ExpertRequired->value]);
    $this->actingAs($this->user)->post($this->base.'/tasks', ['skill' => $skill->slug]);
    $run = TaskRun::query()->sole();

    $member = User::factory()->create();
    $this->team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $this->actingAs($member)
        ->post($this->base.'/tasks/'.$run->id.'/decision', [
            'decision' => 'released_without_expert',
            'justification' => 'We need it today.',
        ])
        ->assertForbidden();

    expect($run->refresh()->released_without_expert)->toBeFalse();

    $this->actingAs($this->user)
        ->post($this->base.'/tasks/'.$run->id.'/decision', [
            'decision' => 'released_without_expert',
            'justification' => 'Board chair reviewed it informally.',
        ])
        ->assertRedirect();

    expect($run->refresh()->released_without_expert)->toBeTrue()
        ->and($run->status)->toBe(TaskRunStatus::Released);
});

it('counts overrides on the work list', function () {
    $skill = enable($this->team, ['supervision' => SupervisionLevel::ExpertRequired->value]);
    $this->actingAs($this->user)->post($this->base.'/tasks', ['skill' => $skill->slug]);
    $run = TaskRun::query()->sole();

    $this->actingAs($this->user)->post($this->base.'/tasks/'.$run->id.'/decision', [
        'decision' => 'released_without_expert',
        'justification' => 'No CPA available.',
    ]);

    $this->actingAs($this->user)
        ->get($this->base.'/tasks')
        ->assertInertia(fn ($page) => $page
            ->where('counts.releasedWithoutExpert', 1)
            ->where('counts.awaiting', 0)
        );
});

it('cannot see another organization run', function () {
    $other = User::factory()->create();
    $run = TaskRun::factory()->create(['team_id' => $other->personalTeam()->id]);

    $this->actingAs($this->user)
        ->get($this->base.'/tasks/'.$run->id)
        ->assertNotFound();
});

describe('the expert link', function () {
    beforeEach(function () {
        OrganizationProfile::create([
            'team_id' => $this->team->id,
            'expert_gate_policy' => ExpertGatePolicy::Block->value,
        ]);
        $skill = enable($this->team, ['supervision' => SupervisionLevel::ExpertRequired->value]);
        $this->actingAs($this->user)->post($this->base.'/tasks', ['skill' => $skill->slug]);
        $this->run = TaskRun::query()->sole();
    });

    it('is created for work waiting on an expert', function () {
        $this->actingAs($this->user)
            ->post($this->base.'/tasks/'.$this->run->id.'/expert-invitation', [
                'email' => 'cpa@example.com',
                'name' => 'Dana Reyes',
            ])
            ->assertRedirect();

        $invitation = ExpertInvitation::query()->sole();

        expect($invitation->task_run_id)->toBe($this->run->id)
            ->and($invitation->isUsable())->toBeTrue();
    });

    it('cannot be created for work that is not waiting on an expert', function () {
        $reviewRun = TaskRun::factory()->create(['team_id' => $this->team->id]);

        $this->actingAs($this->user)
            ->post($this->base.'/tasks/'.$reviewRun->id.'/expert-invitation', ['email' => 'a@b.com'])
            ->assertStatus(422);
    });

    it('lets an invited professional review without an account', function () {
        $invitation = ExpertInvitation::create([
            'token' => ExpertInvitation::generateToken(),
            'task_run_id' => $this->run->id,
            'invited_by' => $this->user->id,
            'email' => 'cpa@example.com',
            'expires_at' => now()->addDays(14),
        ]);

        // No actingAs: the link is the only credential for reaching this page.
        $this->get('/expert-review/'.$invitation->token)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('expert/Review')
                ->where('usable', true)
                ->where('run.task', $this->run->skill->name)
            );

        $this->post('/expert-review/'.$invitation->token, [
            'decision' => 'approved',
            'approver_name' => 'Dana Reyes',
            'credential_type' => 'cpa',
            'credential_reference' => 'OH-12345',
        ])->assertRedirect();

        expect($this->run->refresh()->status)->toBe(TaskRunStatus::Released)
            ->and($this->run->released_without_expert)->toBeFalse();

        $approval = $this->run->approvals()->sole();

        expect($approval->decision)->toBe(ApprovalDecision::Approved)
            ->and($approval->approver_name)->toBe('Dana Reyes')
            ->and($approval->isCredentialed())->toBeTrue();
    });

    it('requires a credential to approve through the link', function () {
        $invitation = ExpertInvitation::create([
            'token' => ExpertInvitation::generateToken(),
            'task_run_id' => $this->run->id,
            'invited_by' => $this->user->id,
            'email' => 'cpa@example.com',
            'expires_at' => now()->addDays(14),
        ]);

        $this->post('/expert-review/'.$invitation->token, [
            'decision' => 'approved',
            'approver_name' => 'Dana Reyes',
        ])->assertSessionHasErrors('credential_type');

        expect($this->run->refresh()->status)->toBe(TaskRunStatus::AwaitingExpert);
    });

    it('is dead once used', function () {
        $invitation = ExpertInvitation::create([
            'token' => ExpertInvitation::generateToken(),
            'task_run_id' => $this->run->id,
            'invited_by' => $this->user->id,
            'email' => 'cpa@example.com',
            'expires_at' => now()->addDays(14),
            'used_at' => now(),
        ]);

        $this->get('/expert-review/'.$invitation->token)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('usable', false));

        $this->post('/expert-review/'.$invitation->token, [
            'decision' => 'approved',
            'approver_name' => 'Dana Reyes',
            'credential_type' => 'cpa',
        ])->assertStatus(410);
    });
});
