<?php

namespace App\Http\Controllers;

use App\Actions\StartTaskRun;
use App\Enums\CredentialType;
use App\Enums\TaskRunStatus;
use App\Exceptions\GateViolation;
use App\Models\Skill;
use App\Models\TaskRun;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class TaskRunController extends Controller
{
    public function index(Request $request): Response
    {
        $team = $this->resolveTeam($request);
        $status = (string) $request->query('status', '');

        $runs = TaskRun::query()
            ->where('team_id', $team->id)
            ->with(['skill', 'requester'])
            ->when($status === 'awaiting', fn ($query) => $query->awaitingDecision())
            ->when(
                TaskRunStatus::tryFrom($status) !== null,
                fn ($query) => $query->where('status', $status),
            )
            ->latest()
            ->limit(100)
            ->get()
            ->map(fn (TaskRun $run) => $this->summarise($run));

        return Inertia::render('tasks/Index', [
            'runs' => $runs,
            'filters' => ['status' => $status],
            'counts' => [
                'awaiting' => TaskRun::query()->where('team_id', $team->id)->awaitingDecision()->count(),
                'released' => TaskRun::query()->where('team_id', $team->id)->where('status', TaskRunStatus::Released->value)->count(),
                'releasedWithoutExpert' => TaskRun::query()->where('team_id', $team->id)->where('released_without_expert', true)->count(),
            ],
        ]);
    }

    public function store(Request $request, StartTaskRun $start): RedirectResponse
    {
        $team = $this->resolveTeam($request);

        $validated = $request->validate([
            'skill' => ['required', 'string', 'exists:skills,slug'],
            'notes' => ['nullable', 'string', 'max:4000'],
        ]);

        $skill = Skill::query()->where('slug', $validated['skill'])->sole();

        try {
            $run = $start->handle($team, $skill, $request->user(), [
                'notes' => $validated['notes'] ?? '',
            ]);
        } catch (GateViolation $e) {
            return back()->withErrors(['skill' => $e->getMessage()]);
        }

        return to_route('tasks.show', ['current_team' => $team->slug, 'taskRun' => $run->id]);
    }

    public function show(Request $request, string $currentTeam, TaskRun $taskRun): Response
    {
        $team = $this->resolveTeam($request);
        abort_unless($taskRun->team_id === $team->id, 404);

        $taskRun->load(['skill', 'requester', 'approvals.user', 'expertInvitations']);

        return Inertia::render('tasks/Show', [
            'run' => [
                ...$this->summarise($taskRun),
                'output' => $taskRun->output !== null ? Str::markdown($taskRun->output) : null,
                'failureReason' => $taskRun->failure_reason,
                'inputs' => $taskRun->inputs,
                'supervisionNote' => $taskRun->skill->supervision_note,
                'supervisionGate' => $taskRun->supervision_at_run->gate(),
                'allowsOverride' => $taskRun->allowsExpertOverride(),
                'usage' => $taskRun->usage,
                'sourceCommit' => $taskRun->skill_source_commit,
                'approvals' => $taskRun->approvals->map(fn ($approval) => [
                    'id' => $approval->id,
                    'decision' => $approval->decision->value,
                    'summary' => $approval->summary(),
                    'justification' => $approval->justification,
                    'credentialReference' => $approval->credential_reference,
                    'decidedAt' => $approval->decided_at->toIso8601String(),
                ]),
                'pendingInvitations' => $taskRun->expertInvitations
                    ->filter(fn ($invitation) => $invitation->isUsable())
                    ->map(fn ($invitation) => [
                        'email' => $invitation->email,
                        'name' => $invitation->name,
                        'url' => route('expert.review', $invitation->token),
                        'expiresAt' => $invitation->expires_at->toIso8601String(),
                    ])->values(),
            ],
            'credentialTypes' => CredentialType::options(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function summarise(TaskRun $run): array
    {
        return [
            'id' => $run->id,
            'skill' => [
                'slug' => $run->skill->slug,
                'name' => $run->skill->name,
            ],
            'status' => $run->status->value,
            'statusLabel' => $run->status->label(),
            'supervision' => $run->supervision_at_run->value,
            'supervisionLabel' => $run->supervision_at_run->label(),
            'requestedBy' => $run->requester->name,
            'releasedWithoutExpert' => $run->released_without_expert,
            'createdAt' => $run->created_at?->toIso8601String(),
            'releasedAt' => $run->released_at?->toIso8601String(),
        ];
    }

    protected function resolveTeam(Request $request): Team
    {
        return Team::query()
            ->where('slug', $request->route('current_team'))
            ->firstOrFail();
    }
}
