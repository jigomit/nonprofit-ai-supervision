<?php

namespace App\Http\Controllers;

use App\Actions\RecordTaskDecision;
use App\Enums\ApprovalDecision;
use App\Enums\CredentialType;
use App\Enums\TeamPermission;
use App\Exceptions\GateViolation;
use App\Models\ExpertInvitation;
use App\Models\TaskRun;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TaskDecisionController extends Controller
{
    /**
     * A member of the organization decides on a run.
     */
    public function store(
        Request $request,
        string $currentTeam,
        TaskRun $taskRun,
        RecordTaskDecision $gate,
    ): RedirectResponse {
        $team = Team::query()->where('slug', $currentTeam)->firstOrFail();
        abort_unless($taskRun->team_id === $team->id, 404);

        $validated = $request->validate([
            'decision' => ['required', Rule::enum(ApprovalDecision::class)],
            'credential_type' => ['nullable', Rule::enum(CredentialType::class)],
            'credential_reference' => ['nullable', 'string', 'max:100'],
            'justification' => ['nullable', 'string', 'max:2000'],
        ]);

        $decision = ApprovalDecision::from($validated['decision']);

        // Letting work out without a credentialed sign-off is an owner-level
        // act, not something any member can do.
        if ($decision === ApprovalDecision::ReleasedWithoutExpert) {
            abort_unless(
                $request->user()->hasTeamPermission($team, TeamPermission::UpdateTeam),
                403,
            );
        }

        try {
            $gate->handle(
                run: $taskRun,
                decision: $decision,
                user: $request->user(),
                credentialType: isset($validated['credential_type'])
                    ? CredentialType::from($validated['credential_type'])
                    : null,
                credentialReference: $validated['credential_reference'] ?? null,
                justification: $validated['justification'] ?? null,
            );
        } catch (GateViolation $e) {
            return back()->withErrors(['decision' => $e->getMessage()]);
        }

        return back();
    }

    /**
     * An invited professional decides, without an account.
     */
    public function storeFromInvitation(
        Request $request,
        ExpertInvitation $invitation,
        RecordTaskDecision $gate,
    ): RedirectResponse {
        abort_unless($invitation->isUsable(), 410);

        $validated = $request->validate([
            'decision' => ['required', Rule::in([
                ApprovalDecision::Approved->value,
                ApprovalDecision::Rejected->value,
            ])],
            'approver_name' => ['required', 'string', 'max:120'],
            'credential_type' => ['required_if:decision,approved', Rule::enum(CredentialType::class)],
            'credential_reference' => ['nullable', 'string', 'max:100'],
            'justification' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $gate->handle(
                run: $invitation->taskRun,
                decision: ApprovalDecision::from($validated['decision']),
                invitation: $invitation,
                credentialType: isset($validated['credential_type'])
                    ? CredentialType::from($validated['credential_type'])
                    : null,
                credentialReference: $validated['credential_reference'] ?? null,
                justification: $validated['justification'] ?? null,
                approverName: $validated['approver_name'],
            );
        } catch (GateViolation $e) {
            return back()->withErrors(['decision' => $e->getMessage()]);
        }

        return back()->with('status', 'Thank you — your decision has been recorded.');
    }
}
