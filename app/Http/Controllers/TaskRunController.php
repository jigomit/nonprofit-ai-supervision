<?php

namespace App\Http\Controllers;

use App\Actions\StartTaskRun;
use App\Enums\CredentialType;
use App\Enums\TaskRunStatus;
use App\Exceptions\GateViolation;
use App\Models\Skill;
use App\Models\TaskRun;
use App\Models\TaskRunAttachment;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
            'files' => ['array', 'max:'.config('signoff.attachments.max_files', 5)],
            'files.*' => [
                'file',
                'max:'.config('signoff.attachments.max_kilobytes', 10240),
                // By extension rather than by mime: the list is what this
                // application can actually read the words out of, and a
                // browser's guess at a CSV's type is not dependable.
                'extensions:'.implode(',', (array) config('signoff.attachments.extensions', [])),
            ],
        ]);

        $skill = Skill::query()->where('slug', $validated['skill'])->sole();

        try {
            $run = $start->handle(
                team: $team,
                skill: $skill,
                user: $request->user(),
                inputs: ['notes' => $validated['notes'] ?? ''],
                files: $request->file('files', []),
            );
        } catch (GateViolation $e) {
            return back()->withErrors(['skill' => $e->getMessage()]);
        }

        return to_route('tasks.show', ['current_team' => $team->slug, 'taskRun' => $run->id]);
    }

    public function show(Request $request, string $currentTeam, TaskRun $taskRun): Response
    {
        $team = $this->resolveTeam($request);
        abort_unless($taskRun->team_id === $team->id, 404);

        $taskRun->load(['skill', 'requester', 'approvals.user', 'expertInvitations', 'attachments']);

        return Inertia::render('tasks/Show', [
            'run' => [
                ...$this->summarise($taskRun),
                'output' => $taskRun->output !== null ? Str::markdown($taskRun->output) : null,
                // The draft as written, for copying. Only once released — a
                // download button on unreviewed work would be the bypass.
                'rawOutput' => $taskRun->isExportable() ? $taskRun->output : null,
                'isExportable' => $taskRun->isExportable(),
                'canRevise' => $taskRun->status === TaskRunStatus::Rejected,
                'revisedFrom' => $taskRun->revised_from_id,
                'failureReason' => $taskRun->failure_reason,
                'inputs' => $taskRun->inputs,
                // A draft is only as specific as what the model was told about
                // the organization. Saying so on the run stops a reviewer
                // reading thin work as the best the task can do.
                'thinContext' => $team->organizationProfile?->hasThinContext() ?? true,
                'isPlaceholder' => $taskRun->model === 'placeholder',
                // What the model was actually given, including the files it
                // was not: a reviewer who thinks the 990 was read will review
                // the draft as though it was.
                'attachments' => $taskRun->attachments->map(fn (TaskRunAttachment $file) => [
                    'id' => $file->id,
                    'name' => $file->original_name,
                    'size' => $file->humanSize(),
                    'status' => $file->extraction->value,
                    'statusLabel' => $file->extraction->label(),
                    'explanation' => $file->extraction->explanation(),
                    'reachedTheModel' => $file->extraction->reachedTheModel(),
                    'truncated' => $file->truncated,
                    'characters' => $file->text_chars,
                ]),
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
     * The finished document, as a file.
     *
     * Gated on release rather than on being signed in: work that has not
     * cleared its gate is readable for review and nothing more.
     */
    public function download(Request $request, string $currentTeam, TaskRun $taskRun): StreamedResponse
    {
        $team = $this->resolveTeam($request);
        abort_unless($taskRun->team_id === $team->id, 404);
        abort_unless($taskRun->isExportable(), 403);

        $filename = Str::slug($taskRun->skill->name).'-'.$taskRun->id.'.md';
        $body = $taskRun->output ?? '';

        return response()->streamDownload(
            fn () => print ($body),
            $filename,
            ['Content-Type' => 'text/markdown; charset=UTF-8'],
        );
    }

    /**
     * Hand back a file this organization uploaded.
     *
     * Streamed from a private disk rather than linked, so the only way to a
     * document is through a membership check.
     */
    public function attachment(
        Request $request,
        string $currentTeam,
        TaskRun $taskRun,
        TaskRunAttachment $attachment,
    ): StreamedResponse {
        $team = $this->resolveTeam($request);

        abort_unless($taskRun->team_id === $team->id, 404);
        abort_unless($attachment->task_run_id === $taskRun->id, 404);

        $disk = Storage::disk($attachment->disk());

        abort_unless($disk->exists($attachment->path), 404);

        return $disk->download($attachment->path, $attachment->original_name);
    }

    /**
     * Start a fresh attempt at work that was sent back.
     *
     * The rejected run is never edited — it stays as it was decided, and the
     * new one points back at it, so the record shows the work was returned and
     * redone rather than quietly rewritten.
     */
    public function revise(Request $request, string $currentTeam, TaskRun $taskRun, StartTaskRun $start): RedirectResponse
    {
        $team = $this->resolveTeam($request);
        abort_unless($taskRun->team_id === $team->id, 404);
        abort_unless($taskRun->status === TaskRunStatus::Rejected, 422);

        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:4000'],
        ]);

        try {
            $run = $start->handle(
                team: $team,
                skill: $taskRun->skill,
                user: $request->user(),
                inputs: ['notes' => $validated['notes'] ?? ($taskRun->inputs['notes'] ?? '')],
                revisionOf: $taskRun,
            );
        } catch (GateViolation $e) {
            return back()->withErrors(['notes' => $e->getMessage()]);
        }

        return to_route('tasks.show', ['current_team' => $team->slug, 'taskRun' => $run->id]);
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
            // Who has to clear it, in a sentence. "Waiting for review" and
            // "Waiting for an expert" are two very different asks and the
            // labels alone do not say which is which.
            'supervisionGate' => $run->supervision_at_run->gate(),
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
