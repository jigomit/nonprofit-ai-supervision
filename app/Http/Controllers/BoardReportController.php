<?php

namespace App\Http\Controllers;

use App\Enums\ApprovalDecision;
use App\Enums\SupervisionLevel;
use App\Enums\TaskRunStatus;
use App\Models\Approval;
use App\Models\SupervisionChange;
use App\Models\SupervisionChangeAcknowledgement;
use App\Models\TaskRun;
use App\Models\Team;
use Carbon\CarbonInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * What an organization shows its board: how much work AI touched, how much of
 * it a person cleared, and — the figure that actually gets asked about — how
 * much went out without the sign-off its level called for.
 */
class BoardReportController extends Controller
{
    private const PERIODS = [30, 90, 365];

    public function index(Request $request): Response
    {
        $team = $this->resolveTeam($request);
        $days = $this->period($request);
        $since = now()->subDays($days);

        $runs = TaskRun::query()->where('team_id', $team->id)->where('created_at', '>=', $since);

        return Inertia::render('reports/Board', [
            'period' => ['days' => $days, 'since' => $since->toDateString(), 'options' => self::PERIODS],
            'totals' => [
                'runs' => (clone $runs)->count(),
                'released' => (clone $runs)->where('status', TaskRunStatus::Released->value)->count(),
                'awaiting' => (clone $runs)->awaitingDecision()->count(),
                'rejected' => (clone $runs)->where('status', TaskRunStatus::Rejected->value)->count(),
                'failed' => (clone $runs)->where('status', TaskRunStatus::Failed->value)->count(),
                'releasedWithoutExpert' => (clone $runs)->where('released_without_expert', true)->count(),
            ],
            'byLevel' => collect(SupervisionLevel::cases())
                ->map(fn (SupervisionLevel $level) => [
                    'value' => $level->value,
                    'label' => $level->label(),
                    'runs' => (clone $runs)->where('supervision_at_run', $level->value)->count(),
                ]),
            // The exceptions list. Named people, stated reasons — this is the
            // part a board reads.
            'overrides' => $this->overrides($team, $since),
            'expertSignOffs' => $this->expertSignOffs($team, $since),
            'supervisionChanges' => $this->supervisionChanges($team),
        ]);
    }

    public function acknowledge(Request $request, string $currentTeam, SupervisionChange $change): RedirectResponse
    {
        $team = $this->resolveTeam($request);

        SupervisionChangeAcknowledgement::updateOrCreate(
            ['team_id' => $team->id, 'supervision_change_id' => $change->id],
            ['acknowledged_by' => $request->user()->id, 'acknowledged_at' => now()],
        );

        return back();
    }

    /**
     * The audit record as a file. A board packet and a funder question both
     * want the same thing: every run, its gate, and who cleared it.
     */
    public function export(Request $request): StreamedResponse
    {
        $team = $this->resolveTeam($request);
        $days = $this->period($request);

        $runs = TaskRun::query()
            ->where('team_id', $team->id)
            ->where('created_at', '>=', now()->subDays($days))
            ->with(['skill', 'requester', 'approvals.user'])
            ->orderBy('created_at')
            ->get();

        $filename = sprintf('%s-ai-supervision-%s.csv', $team->slug, now()->toDateString());

        return response()->streamDownload(function () use ($runs) {
            $out = fopen('php://output', 'w');

            if ($out === false) {
                return;
            }

            fputcsv($out, [
                'Run', 'Started', 'Task', 'Supervision level', 'Status',
                'Requested by', 'Decided by', 'Credential', 'Licence',
                'Released without expert', 'Justification', 'Instructions revision',
            ]);

            foreach ($runs as $run) {
                $approval = $run->approvals->last();

                fputcsv($out, [
                    $run->id,
                    $run->created_at?->toDateTimeString(),
                    $run->skill->name,
                    $run->supervision_at_run->label(),
                    $run->status->label(),
                    $run->requester->name,
                    $approval instanceof Approval ? (string) $approval->approver_name : '',
                    $approval instanceof Approval ? (string) $approval->credential_type?->label() : '',
                    $approval instanceof Approval ? (string) $approval->credential_reference : '',
                    $run->released_without_expert ? 'YES' : '',
                    $approval instanceof Approval ? (string) $approval->justification : '',
                    (string) $run->skill_source_commit,
                ]);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function overrides(Team $team, CarbonInterface $since): array
    {
        return TaskRun::query()
            ->where('team_id', $team->id)
            ->where('released_without_expert', true)
            ->where('created_at', '>=', $since)
            ->with(['skill', 'approvals'])
            ->latest()
            ->get()
            ->map(function (TaskRun $run) {
                $approval = $run->approvals
                    ->firstWhere('decision', ApprovalDecision::ReleasedWithoutExpert);

                return [
                    'id' => $run->id,
                    'task' => $run->skill->name,
                    'releasedAt' => $run->released_at?->toDateString(),
                    'by' => $approval instanceof Approval && $approval->approver_name !== null
                        ? $approval->approver_name
                        : 'Unknown',
                    'justification' => $approval?->justification,
                    'supervisionNote' => $run->skill->supervision_note,
                ];
            })
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function expertSignOffs(Team $team, CarbonInterface $since): array
    {
        return TaskRun::query()
            ->where('team_id', $team->id)
            ->where('supervision_at_run', SupervisionLevel::ExpertRequired->value)
            ->where('status', TaskRunStatus::Released->value)
            ->where('released_without_expert', false)
            ->where('created_at', '>=', $since)
            ->with(['skill', 'approvals'])
            ->latest()
            ->get()
            ->map(function (TaskRun $run) {
                $approval = $run->approvals->last();

                return [
                    'id' => $run->id,
                    'task' => $run->skill->name,
                    'releasedAt' => $run->released_at?->toDateString(),
                    'by' => $approval instanceof Approval && $approval->approver_name !== null
                        ? $approval->approver_name
                        : 'Unknown',
                    'credential' => $approval?->credential_type?->label(),
                    'licence' => $approval?->credential_reference,
                ];
            })
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function supervisionChanges(Team $team): array
    {
        return SupervisionChange::query()
            ->unacknowledgedBy($team->id)
            ->with('skill')
            ->latest()
            ->get()
            ->map(fn (SupervisionChange $change) => [
                'id' => $change->id,
                'task' => $change->skill->name,
                'from' => $change->from_level->label(),
                'to' => $change->to_level->label(),
                'isEscalation' => $change->isEscalation(),
                'changedAt' => $change->created_at?->toDateString(),
            ])
            ->all();
    }

    protected function period(Request $request): int
    {
        $days = (int) $request->query('days', 90);

        return in_array($days, self::PERIODS, true) ? $days : 90;
    }

    protected function resolveTeam(Request $request): Team
    {
        return Team::query()
            ->where('slug', $request->route('current_team'))
            ->firstOrFail();
    }
}
