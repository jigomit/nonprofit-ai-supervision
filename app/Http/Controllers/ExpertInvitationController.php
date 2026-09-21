<?php

namespace App\Http\Controllers;

use App\Enums\CredentialType;
use App\Enums\TaskRunStatus;
use App\Models\ExpertInvitation;
use App\Models\TaskRun;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ExpertInvitationController extends Controller
{
    public function store(Request $request, string $currentTeam, TaskRun $taskRun): RedirectResponse
    {
        $team = Team::query()->where('slug', $currentTeam)->firstOrFail();
        abort_unless($taskRun->team_id === $team->id, 404);

        // Only work actually waiting on an expert can be sent to one.
        abort_unless($taskRun->status === TaskRunStatus::AwaitingExpert, 422);

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'name' => ['nullable', 'string', 'max:120'],
            'credential_type' => ['nullable', Rule::enum(CredentialType::class)],
        ]);

        ExpertInvitation::create([
            'token' => ExpertInvitation::generateToken(),
            'task_run_id' => $taskRun->id,
            'invited_by' => $request->user()->id,
            'email' => $validated['email'],
            'name' => $validated['name'] ?? null,
            'credential_type' => $validated['credential_type'] ?? null,
            'expires_at' => now()->addDays(ExpertInvitation::LIFETIME_DAYS),
        ]);

        return back()->with('status', 'Invitation link created.');
    }

    /**
     * The guest page an invited professional lands on. No account, no login —
     * the link itself is the credential for reaching this one piece of work.
     */
    public function show(ExpertInvitation $invitation): Response
    {
        $run = $invitation->taskRun->load(['skill', 'team']);

        return Inertia::render('expert/Review', [
            'usable' => $invitation->isUsable() && $run->status === TaskRunStatus::AwaitingExpert,
            'invitation' => [
                'token' => $invitation->token,
                'name' => $invitation->name,
                'credentialType' => $invitation->credential_type?->value,
                'expiresAt' => $invitation->expires_at->toIso8601String(),
            ],
            'run' => [
                'organization' => $run->team->name,
                'task' => $run->skill->name,
                'supervisionNote' => $run->skill->supervision_note,
                'status' => $run->status->value,
                'output' => $run->output !== null ? Str::markdown($run->output) : null,
                'requestedBy' => $run->requester->name,
                'createdAt' => $run->created_at?->toIso8601String(),
            ],
            'credentialTypes' => CredentialType::options(),
        ]);
    }
}
