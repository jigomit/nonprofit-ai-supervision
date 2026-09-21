<?php

namespace App\Actions;

use App\Enums\ApprovalDecision;
use App\Enums\CredentialType;
use App\Enums\TaskRunStatus;
use App\Exceptions\GateViolation;
use App\Models\Approval;
use App\Models\ExpertInvitation;
use App\Models\TaskRun;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The gate. Every release of a piece of work goes through here.
 *
 * The rules this enforces are the product's only real promise, so they live in
 * one place and throw rather than return false. In particular: expert-required
 * work cannot reach Released on a bare approval — it needs either a claimed
 * professional credential, or an explicit, justified, permanently flagged
 * override that the organization's policy allows.
 */
class RecordTaskDecision
{
    public function handle(
        TaskRun $run,
        ApprovalDecision $decision,
        ?User $user = null,
        ?ExpertInvitation $invitation = null,
        ?CredentialType $credentialType = null,
        ?string $credentialReference = null,
        ?string $justification = null,
        ?string $approverName = null,
    ): Approval {
        $this->guard($run, $decision, $user, $invitation, $credentialType, $justification);

        return DB::transaction(function () use (
            $run, $decision, $user, $invitation, $credentialType,
            $credentialReference, $justification, $approverName,
        ) {
            $name = $approverName
                ?? ($user !== null ? $user->name : null)
                ?? ($invitation !== null ? $invitation->name : null);

            $approval = $run->approvals()->create([
                'user_id' => $user?->id,
                'expert_invitation_id' => $invitation?->id,
                'decision' => $decision,
                'credential_type' => $credentialType,
                'credential_reference' => $credentialReference,
                'approver_name' => $name,
                'justification' => $justification,
                'decided_at' => now(),
            ]);

            $invitation?->forceFill(['used_at' => now()])->save();

            $run->forceFill([
                'status' => $decision->releasesWork()
                    ? TaskRunStatus::Released
                    : TaskRunStatus::Rejected,
                'released_at' => $decision->releasesWork() ? now() : null,
                'released_without_expert' => $decision === ApprovalDecision::ReleasedWithoutExpert,
            ])->save();

            return $approval;
        });
    }

    protected function guard(
        TaskRun $run,
        ApprovalDecision $decision,
        ?User $user,
        ?ExpertInvitation $invitation,
        ?CredentialType $credentialType,
        ?string $justification,
    ): void {
        if (! $run->status->isAwaitingDecision()) {
            throw GateViolation::notAwaitingDecision($run->status->value);
        }

        if (($user === null) === ($invitation === null)) {
            throw GateViolation::ambiguousApprover();
        }

        if ($invitation !== null && (! $invitation->isUsable() || $invitation->task_run_id !== $run->id)) {
            throw GateViolation::invitationNotUsable();
        }

        // A rejection needs nothing else: anyone entitled to decide can stop
        // work from being released.
        if ($decision === ApprovalDecision::Rejected) {
            return;
        }

        if ($decision === ApprovalDecision::ReleasedWithoutExpert) {
            if ($run->status !== TaskRunStatus::AwaitingExpert) {
                throw GateViolation::overrideOnlyAppliesToExpertGate();
            }

            if (! $run->expert_gate_policy_at_run->allowsOverride()) {
                throw GateViolation::overrideNotPermitted();
            }

            if ($justification === null || trim($justification) === '') {
                throw GateViolation::overrideNeedsJustification();
            }

            return;
        }

        // A plain approval on an expert gate is the case that must never slip
        // through: without a claimed credential it is just a staff sign-off
        // wearing the wrong label.
        if ($run->status === TaskRunStatus::AwaitingExpert && $credentialType === null) {
            throw GateViolation::expertCredentialRequired();
        }
    }
}
