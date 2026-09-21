<?php

use App\Actions\RecordTaskDecision;
use App\Enums\ApprovalDecision;
use App\Enums\CredentialType;
use App\Enums\ExpertGatePolicy;
use App\Enums\SupervisionLevel;
use App\Enums\TaskRunStatus;
use App\Exceptions\GateViolation;
use App\Models\ExpertInvitation;
use App\Models\TaskRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->gate = app(RecordTaskDecision::class);
});

function inviteExpert(TaskRun $run, User $inviter, array $overrides = []): ExpertInvitation
{
    return ExpertInvitation::create(array_merge([
        'token' => ExpertInvitation::generateToken(),
        'task_run_id' => $run->id,
        'invited_by' => $inviter->id,
        'email' => 'cpa@example.com',
        'name' => 'Dana Reyes',
        'credential_type' => CredentialType::Cpa->value,
        'expires_at' => now()->addDays(ExpertInvitation::LIFETIME_DAYS),
    ], $overrides));
}

describe('the review gate', function () {
    it('releases work once a member approves it', function () {
        $run = TaskRun::factory()->create();

        $approval = $this->gate->handle($run, ApprovalDecision::Approved, user: $this->user);

        expect($run->refresh()->status)->toBe(TaskRunStatus::Released)
            ->and($run->released_at)->not->toBeNull()
            ->and($run->released_without_expert)->toBeFalse()
            ->and($approval->approver_name)->toBe($this->user->name)
            ->and($approval->isCredentialed())->toBeFalse();
    });

    it('does not release rejected work', function () {
        $run = TaskRun::factory()->create();

        $this->gate->handle($run, ApprovalDecision::Rejected, user: $this->user);

        expect($run->refresh()->status)->toBe(TaskRunStatus::Rejected)
            ->and($run->released_at)->toBeNull()
            ->and($run->isReleased())->toBeFalse();
    });

    it('refuses an expert override on a review gate', function () {
        $run = TaskRun::factory()->create();

        expect(fn () => $this->gate->handle(
            $run,
            ApprovalDecision::ReleasedWithoutExpert,
            user: $this->user,
            justification: 'We were in a hurry.',
        ))->toThrow(GateViolation::class, 'Only expert-required work');

        expect($run->refresh()->status)->toBe(TaskRunStatus::AwaitingReview);
    });
});

describe('the expert gate', function () {
    it('cannot be cleared by a plain approval', function () {
        $run = TaskRun::factory()->awaitingExpert()->create();

        // The case that must never slip through: a staff sign-off wearing the
        // label of a credentialed one.
        expect(fn () => $this->gate->handle($run, ApprovalDecision::Approved, user: $this->user))
            ->toThrow(GateViolation::class, 'needs a credentialed professional');

        expect($run->refresh()->status)->toBe(TaskRunStatus::AwaitingExpert)
            ->and($run->isReleased())->toBeFalse()
            ->and($run->approvals()->count())->toBe(0);
    });

    it('is cleared by an invited expert who claims a credential', function () {
        $run = TaskRun::factory()->awaitingExpert()->create();
        $invitation = inviteExpert($run, $this->user);

        $approval = $this->gate->handle(
            $run,
            ApprovalDecision::Approved,
            invitation: $invitation,
            credentialType: CredentialType::Cpa,
            credentialReference: 'OH-12345',
        );

        expect($run->refresh()->status)->toBe(TaskRunStatus::Released)
            ->and($run->released_without_expert)->toBeFalse()
            ->and($approval->isCredentialed())->toBeTrue()
            ->and($approval->credential_reference)->toBe('OH-12345')
            ->and($approval->summary())->toContain('Dana Reyes (CPA) approved')
            ->and($invitation->refresh()->used_at)->not->toBeNull();
    });

    it('lets a member approve when they claim a credential themselves', function () {
        $run = TaskRun::factory()->awaitingExpert()->create();

        $this->gate->handle(
            $run,
            ApprovalDecision::Approved,
            user: $this->user,
            credentialType: CredentialType::Attorney,
        );

        expect($run->refresh()->status)->toBe(TaskRunStatus::Released);
    });
});

describe('the expert override', function () {
    it('releases the work but flags it permanently', function () {
        $run = TaskRun::factory()->awaitingExpert()->create();

        $approval = $this->gate->handle(
            $run,
            ApprovalDecision::ReleasedWithoutExpert,
            user: $this->user,
            justification: 'Board chair is a retired CPA and reviewed it informally.',
        );

        expect($run->refresh()->status)->toBe(TaskRunStatus::Released)
            ->and($run->released_without_expert)->toBeTrue()
            ->and($approval->decision)->toBe(ApprovalDecision::ReleasedWithoutExpert)
            ->and($approval->justification)->toContain('retired CPA')
            ->and($approval->summary())->toContain('released this without expert review');
    });

    it('demands a justification', function () {
        $run = TaskRun::factory()->awaitingExpert()->create();

        expect(fn () => $this->gate->handle(
            $run,
            ApprovalDecision::ReleasedWithoutExpert,
            user: $this->user,
            justification: '   ',
        ))->toThrow(GateViolation::class, 'who decided and why');

        expect($run->refresh()->released_without_expert)->toBeFalse();
    });

    it('is impossible under a blocking policy', function () {
        $run = TaskRun::factory()->awaitingExpert(ExpertGatePolicy::Block)->create();

        expect(fn () => $this->gate->handle(
            $run,
            ApprovalDecision::ReleasedWithoutExpert,
            user: $this->user,
            justification: 'We need it today.',
        ))->toThrow(GateViolation::class, 'does not allow releasing');

        expect($run->refresh()->status)->toBe(TaskRunStatus::AwaitingExpert);
    });

    it('stays governed by the policy in force when the run started', function () {
        // An organization loosening its policy afterwards must not retroactively
        // unlock work that was blocked when it ran.
        $run = TaskRun::factory()->awaitingExpert(ExpertGatePolicy::Block)->create();
        $run->team->organizationProfile()->create([
            'expert_gate_policy' => ExpertGatePolicy::OverrideWithJustification->value,
        ]);

        expect(fn () => $this->gate->handle(
            $run,
            ApprovalDecision::ReleasedWithoutExpert,
            user: $this->user,
            justification: 'Policy changed since.',
        ))->toThrow(GateViolation::class, 'does not allow releasing');
    });
});

describe('invitations', function () {
    it('refuses an expired invitation', function () {
        $run = TaskRun::factory()->awaitingExpert()->create();
        $invitation = inviteExpert($run, $this->user, ['expires_at' => now()->subDay()]);

        expect(fn () => $this->gate->handle(
            $run,
            ApprovalDecision::Approved,
            invitation: $invitation,
            credentialType: CredentialType::Cpa,
        ))->toThrow(GateViolation::class, 'already been used or has expired');
    });

    it('refuses a second use of the same invitation', function () {
        $run = TaskRun::factory()->awaitingExpert()->create();
        $invitation = inviteExpert($run, $this->user);

        $this->gate->handle($run, ApprovalDecision::Approved, invitation: $invitation, credentialType: CredentialType::Cpa);

        $other = TaskRun::factory()->awaitingExpert()->create();

        expect(fn () => $this->gate->handle(
            $other,
            ApprovalDecision::Approved,
            invitation: $invitation->refresh(),
            credentialType: CredentialType::Cpa,
        ))->toThrow(GateViolation::class, 'already been used or has expired');
    });

    it('refuses an invitation issued for a different run', function () {
        $run = TaskRun::factory()->awaitingExpert()->create();
        $other = TaskRun::factory()->awaitingExpert()->create();
        $invitation = inviteExpert($other, $this->user);

        expect(fn () => $this->gate->handle(
            $run,
            ApprovalDecision::Approved,
            invitation: $invitation,
            credentialType: CredentialType::Cpa,
        ))->toThrow(GateViolation::class, 'already been used or has expired');
    });
});

describe('general invariants', function () {
    it('refuses to decide on work that is not waiting', function () {
        foreach ([TaskRun::factory()->released(), TaskRun::factory()->queued()] as $factory) {
            $run = $factory->create();

            expect(fn () => $this->gate->handle($run, ApprovalDecision::Approved, user: $this->user))
                ->toThrow(GateViolation::class, 'not waiting on a decision');
        }
    });

    it('refuses a decision with both a member and an invitation', function () {
        $run = TaskRun::factory()->awaitingExpert()->create();
        $invitation = inviteExpert($run, $this->user);

        expect(fn () => $this->gate->handle(
            $run,
            ApprovalDecision::Approved,
            user: $this->user,
            invitation: $invitation,
            credentialType: CredentialType::Cpa,
        ))->toThrow(GateViolation::class, 'either by a member or by an invited expert');
    });

    it('refuses a decision with no approver at all', function () {
        $run = TaskRun::factory()->create();

        expect(fn () => $this->gate->handle($run, ApprovalDecision::Approved))
            ->toThrow(GateViolation::class, 'either by a member or by an invited expert');
    });

    it('routes a finished run to the gate its level demands', function () {
        expect(TaskRunStatus::gateFor(SupervisionLevel::Unsupervised))->toBe(TaskRunStatus::Released)
            ->and(TaskRunStatus::gateFor(SupervisionLevel::Review))->toBe(TaskRunStatus::AwaitingReview)
            ->and(TaskRunStatus::gateFor(SupervisionLevel::ExpertRequired))->toBe(TaskRunStatus::AwaitingExpert);
    });
});
