<?php

namespace App\Http\Controllers;

use App\Enums\SupervisionLevel;
use App\Enums\TaskRunStatus;
use App\Models\SupervisionChange;
use App\Models\TaskRun;
use App\Models\TaskSchedule;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The first screen after signing in.
 *
 * It answers one question — what needs a person right now — rather than
 * reporting how busy the organization has been. The queue is at the top and
 * the counts are at the bottom.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $team = $this->resolveTeam($request);

        return Inertia::render('Dashboard', [
            'pendingInvitations' => $this->pendingInvitations($user),
            'awaitingDecision' => $this->awaitingDecision($team, $user),
            'dueWork' => $this->dueWork($team),
            'recentRuns' => $this->recentRuns($team),
            'summary' => $this->summary($team),
            'setup' => $this->setup($team),
        ]);
    }

    /**
     * Work sitting at a gate this person can actually clear.
     *
     * A plain member cannot clear an expert gate, so listing one here would be
     * a to-do they have no way to do.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function awaitingDecision(Team $team, User $user): array
    {
        $clearsExpertGates = $team->decidersFor(SupervisionLevel::ExpertRequired)
            ->contains(fn (User $decider) => $decider->id === $user->id);

        return TaskRun::query()
            ->where('team_id', $team->id)
            ->awaitingDecision()
            ->when(! $clearsExpertGates, fn ($query) => $query
                ->where('status', TaskRunStatus::AwaitingReview->value))
            ->with(['skill', 'requester'])
            ->oldest()
            ->limit(8)
            ->get()
            ->map(fn (TaskRun $run) => [
                'id' => $run->id,
                'task' => $run->skill->name,
                'supervision' => $run->supervision_at_run->value,
                'supervisionLabel' => $run->supervision_at_run->label(),
                'requestedBy' => $run->requester->name,
                'waitingSince' => $run->completed_at?->diffForHumans(),
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function dueWork(Team $team): array
    {
        return TaskSchedule::query()
            ->where('team_id', $team->id)
            ->due()
            ->with('skill')
            ->orderBy('next_due_at')
            ->limit(6)
            ->get()
            ->map(fn (TaskSchedule $schedule) => [
                'id' => $schedule->id,
                'task' => $schedule->skill->name,
                'dueAt' => $schedule->next_due_at->toDateString(),
                'isOverdue' => $schedule->isOverdue(),
                'daysOverdue' => abs($schedule->daysUntilDue()),
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function recentRuns(Team $team): array
    {
        return TaskRun::query()
            ->where('team_id', $team->id)
            ->with('skill')
            ->latest()
            ->limit(5)
            ->get()
            ->map(fn (TaskRun $run) => [
                'id' => $run->id,
                'task' => $run->skill->name,
                'status' => $run->status->value,
                'statusLabel' => $run->status->label(),
                'releasedWithoutExpert' => $run->released_without_expert,
                'at' => $run->created_at?->diffForHumans(),
            ])
            ->all();
    }

    /**
     * @return array<string, int>
     */
    protected function summary(Team $team): array
    {
        $since = now()->subDays(30);
        $runs = TaskRun::query()->where('team_id', $team->id)->where('created_at', '>=', $since);

        return [
            'runs' => (clone $runs)->count(),
            'released' => (clone $runs)->where('status', TaskRunStatus::Released->value)->count(),
            'releasedWithoutExpert' => (clone $runs)->where('released_without_expert', true)->count(),
            'supervisionChanges' => SupervisionChange::query()->unacknowledgedBy($team->id)->count(),
        ];
    }

    /**
     * What is still missing before the organization can use this properly. An
     * empty dashboard should say what to do next, not just be empty.
     *
     * @return array<string, mixed>
     */
    protected function setup(Team $team): array
    {
        $profile = $team->organizationProfile;

        return [
            'isOnboarded' => $profile !== null && $profile->onboarded_at !== null,
            'catalogueSize' => $team->enabledSkills()->count(),
            'hasSchedules' => TaskSchedule::query()->where('team_id', $team->id)->exists(),
            'hasRun' => TaskRun::query()->where('team_id', $team->id)->exists(),
        ];
    }

    /**
     * @return Collection<int, array{code: string, inviterName: string, team: array{name: string, slug: string}}>
     */
    protected function pendingInvitations(User $user): Collection
    {
        return TeamInvitation::query()
            ->with(['inviter', 'team'])
            ->whereRaw('LOWER(email) = ?', [strtolower($user->email)])
            ->whereNull('accepted_at')
            ->where(fn ($query) => $query
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>=', now()))
            ->latest()
            ->get()
            ->map(fn (TeamInvitation $invitation) => [
                'code' => $invitation->code,
                'inviterName' => $invitation->inviter->name,
                'team' => [
                    'name' => $invitation->team->name,
                    'slug' => $invitation->team->slug,
                ],
            ]);
    }

    protected function resolveTeam(Request $request): Team
    {
        return Team::query()
            ->where('slug', $request->route('current_team'))
            ->firstOrFail();
    }
}
