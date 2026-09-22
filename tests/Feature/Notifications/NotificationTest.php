<?php

use App\Actions\RecordTaskDecision;
use App\Actions\StartTaskRun;
use App\Actions\SyncTeamCatalogue;
use App\Enums\ApprovalDecision;
use App\Enums\CredentialType;
use App\Enums\SupervisionLevel;
use App\Enums\TeamRole;
use App\Models\ExpertInvitation;
use App\Models\Skill;
use App\Models\TaskRun;
use App\Models\TaskSchedule;
use App\Models\User;
use App\Notifications\DecisionRecorded;
use App\Notifications\ExpertReviewRequested;
use App\Notifications\OverdueWorkDigest;
use App\Notifications\WorkAwaitingDecision;
use App\Services\ExecutionResult;
use App\Services\TaskExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    Notification::fake();

    $this->owner = User::factory()->create();
    $this->team = $this->owner->personalTeam();
    $this->base = '/'.$this->team->slug;

    app()->bind(TaskExecutor::class, fn () => new class implements TaskExecutor
    {
        public function execute(TaskRun $run): ExecutionResult
        {
            return new ExecutionResult('A draft.', 'claude-opus-5', []);
        }
    });
});

function member(mixed $team, string $role = TeamRole::Member->value): User
{
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => $role]);

    return $user;
}

function runTask(mixed $team, User $as, SupervisionLevel $level): TaskRun
{
    $skill = Skill::factory()->create(['supervision' => $level->value]);
    app(SyncTeamCatalogue::class)->handle($team);

    return app(StartTaskRun::class)->handle($team->refresh(), $skill, $as);
}

describe('work waiting on a decision', function () {
    it('tells the team when a review gate is reached', function () {
        $colleague = member($this->team);
        $second = member($this->team);

        runTask($this->team, $this->owner, SupervisionLevel::Review);

        // Everyone on the team can clear a review gate, so everyone hears —
        // except the owner, who started this one.
        Notification::assertSentTo($colleague, WorkAwaitingDecision::class);
        Notification::assertSentTo($second, WorkAwaitingDecision::class);
        Notification::assertNotSentTo($this->owner, WorkAwaitingDecision::class);
    });

    it('does not tell the person who just started it', function () {
        $colleague = member($this->team);

        runTask($this->team, $colleague, SupervisionLevel::Review);

        Notification::assertNotSentTo($colleague, WorkAwaitingDecision::class);
        Notification::assertSentTo($this->owner, WorkAwaitingDecision::class);
    });

    it('only tells owners and admins about an expert gate', function () {
        // A plain member cannot clear an expert gate, invite a professional, or
        // override — telling them would be noise.
        $plainMember = member($this->team);
        $admin = member($this->team, TeamRole::Admin->value);

        runTask($this->team, $this->owner, SupervisionLevel::ExpertRequired);

        Notification::assertNotSentTo($plainMember, WorkAwaitingDecision::class);
        Notification::assertSentTo($admin, WorkAwaitingDecision::class);
    });

    it('says nothing when work releases itself', function () {
        runTask($this->team, $this->owner, SupervisionLevel::Unsupervised);

        Notification::assertNothingSent();
    });

    it('never reaches another organization', function () {
        $outsider = User::factory()->create();

        runTask($this->team, $this->owner, SupervisionLevel::Review);

        Notification::assertNotSentTo($outsider, WorkAwaitingDecision::class);
    });
});

describe('the expert link', function () {
    it('is emailed to the address, not just created', function () {
        $run = TaskRun::factory()->awaitingExpert()->create(['team_id' => $this->team->id]);

        $this->actingAs($this->owner)
            ->post($this->base.'/tasks/'.$run->id.'/expert-invitation', [
                'email' => 'cpa@example.com',
                'name' => 'Dana Reyes',
            ])
            ->assertRedirect();

        Notification::assertSentTo(
            new AnonymousNotifiable,
            ExpertReviewRequested::class,
            fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'cpa@example.com'
        );
    });

    it('carries the reason a professional is needed', function () {
        $skill = Skill::factory()->expertRequired()->create([
            'supervision_note' => 'Filed with the IRS.',
        ]);
        $run = TaskRun::factory()->awaitingExpert()->create([
            'team_id' => $this->team->id,
            'skill_id' => $skill->id,
        ]);
        $invitation = ExpertInvitation::create([
            'token' => ExpertInvitation::generateToken(),
            'task_run_id' => $run->id,
            'invited_by' => $this->owner->id,
            'email' => 'cpa@example.com',
            'expires_at' => now()->addDays(14),
        ]);

        $mail = (new ExpertReviewRequested($invitation))->toMail(new AnonymousNotifiable);
        $rendered = collect($mail->introLines)->implode(' ');

        expect($rendered)->toContain('Filed with the IRS.')
            ->and($mail->actionUrl)->toContain($invitation->token);
    });
});

describe('a decision', function () {
    it('is reported back to whoever asked for the work', function () {
        $colleague = member($this->team);
        $run = runTask($this->team, $colleague, SupervisionLevel::Review);

        app(RecordTaskDecision::class)->handle(
            $run->refresh(),
            ApprovalDecision::Approved,
            user: $this->owner,
        );

        Notification::assertSentTo($colleague, DecisionRecorded::class);
    });

    it('is not mailed to the person who made it', function () {
        $run = runTask($this->team, $this->owner, SupervisionLevel::Review);

        app(RecordTaskDecision::class)->handle(
            $run->refresh(),
            ApprovalDecision::Approved,
            user: $this->owner,
        );

        Notification::assertNotSentTo($this->owner, DecisionRecorded::class);
    });

    it('says plainly when work went out without expert review', function () {
        $colleague = member($this->team);
        $run = runTask($this->team, $colleague, SupervisionLevel::ExpertRequired);

        $approval = app(RecordTaskDecision::class)->handle(
            $run->refresh(),
            ApprovalDecision::ReleasedWithoutExpert,
            user: $this->owner,
            justification: 'No CPA before the deadline.',
        );

        $mail = (new DecisionRecorded($approval))->toMail($colleague);
        $rendered = collect($mail->introLines)->implode(' ');

        expect($mail->subject)->toContain('without expert review')
            ->and($rendered)->toContain('board report')
            ->and($rendered)->toContain('No CPA before the deadline.');
    });

    it('reaches the requester when an outside expert decides', function () {
        $colleague = member($this->team);
        $run = runTask($this->team, $colleague, SupervisionLevel::ExpertRequired);

        $invitation = ExpertInvitation::create([
            'token' => ExpertInvitation::generateToken(),
            'task_run_id' => $run->id,
            'invited_by' => $this->owner->id,
            'email' => 'cpa@example.com',
            'expires_at' => now()->addDays(14),
        ]);

        app(RecordTaskDecision::class)->handle(
            $run->refresh(),
            ApprovalDecision::Approved,
            invitation: $invitation,
            credentialType: CredentialType::Cpa,
        );

        Notification::assertSentTo($colleague, DecisionRecorded::class);
    });
});

describe('the due-work digest', function () {
    it('mails one digest per organization, not one per item', function () {
        app(SyncTeamCatalogue::class)->handle($this->team);
        $colleague = member($this->team);

        TaskSchedule::factory()->overdue()->count(3)->create(['team_id' => $this->team->id]);

        $this->artisan('work:notify-due')->assertSuccessful();

        Notification::assertSentToTimes($this->owner, OverdueWorkDigest::class, 1);
        Notification::assertSentToTimes($colleague, OverdueWorkDigest::class, 1);
    });

    it('ignores work that is not due yet, and paused work', function () {
        TaskSchedule::factory()->create(['team_id' => $this->team->id]);
        TaskSchedule::factory()->overdue()->inactive()->create(['team_id' => $this->team->id]);

        $this->artisan('work:notify-due')->assertSuccessful();

        Notification::assertNothingSent();
    });

    it('sends nothing on a dry run', function () {
        TaskSchedule::factory()->overdue()->create(['team_id' => $this->team->id]);

        $this->artisan('work:notify-due', ['--dry-run' => true])
            ->expectsOutputToContain('Dry run')
            ->assertSuccessful();

        Notification::assertNothingSent();
    });

    it('keeps one organization schedule out of another digest', function () {
        $outsider = User::factory()->create();
        TaskSchedule::factory()->overdue()->create([
            'team_id' => $outsider->personalTeam()->id,
        ]);
        TaskSchedule::factory()->overdue()->create(['team_id' => $this->team->id]);

        $this->artisan('work:notify-due')->assertSuccessful();

        Notification::assertSentTo($this->owner, OverdueWorkDigest::class, function ($notification) {
            return $notification->schedules->every(
                fn (TaskSchedule $schedule) => $schedule->team_id === $this->team->id
            );
        });
    });
});
