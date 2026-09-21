<?php

namespace App\Http\Controllers;

use App\Actions\StartTaskRun;
use App\Enums\Cadence;
use App\Exceptions\GateViolation;
use App\Models\Skill;
use App\Models\TaskSchedule;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TaskScheduleController extends Controller
{
    public function index(Request $request): Response
    {
        $team = $this->resolveTeam($request);
        $fiscalYearEndMonth = $team->organizationProfile?->fiscal_year_end_month;

        $schedules = TaskSchedule::query()
            ->where('team_id', $team->id)
            ->with('skill')
            ->orderBy('next_due_at')
            ->get()
            ->map(fn (TaskSchedule $schedule) => [
                'id' => $schedule->id,
                'skill' => [
                    'slug' => $schedule->skill->slug,
                    'name' => $schedule->skill->name,
                ],
                'supervision' => $schedule->skill->supervision->value,
                'supervisionLabel' => $schedule->skill->supervision->label(),
                'cadence' => $schedule->cadence->value,
                'cadenceLabel' => $schedule->cadence->label(),
                'anchoredToFiscalYearEnd' => $schedule->anchored_to_fiscal_year_end,
                'nextDueAt' => $schedule->next_due_at->toDateString(),
                'daysUntilDue' => $schedule->daysUntilDue(),
                'isOverdue' => $schedule->isOverdue(),
                'isActive' => $schedule->is_active,
                'lastStartedAt' => $schedule->last_started_at?->toIso8601String(),
            ]);

        return Inertia::render('schedules/Index', [
            'schedules' => $schedules,
            'cadences' => Cadence::options(),
            'fiscalYearEndMonth' => $fiscalYearEndMonth,
            // Only work already in the catalogue can be scheduled.
            'schedulableSkills' => $team->enabledSkills()
                ->whereNotIn('skills.id', TaskSchedule::query()->where('team_id', $team->id)->select('skill_id'))
                ->orderBy('name')
                ->get()
                ->map(fn (Skill $skill) => [
                    'slug' => $skill->slug,
                    'name' => $skill->name,
                    'supervision' => $skill->supervision->value,
                ]),
            'counts' => [
                'due' => TaskSchedule::query()->where('team_id', $team->id)->due()->count(),
                'overdue' => TaskSchedule::query()
                    ->where('team_id', $team->id)
                    ->active()
                    ->whereDate('next_due_at', '<', now()->toDateString())
                    ->count(),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $team = $this->resolveTeam($request);

        $validated = $request->validate([
            'skill' => ['required', 'string', 'exists:skills,slug'],
            'cadence' => ['required', Rule::enum(Cadence::class)],
            'anchored_to_fiscal_year_end' => ['boolean'],
            'anchor_month' => ['nullable', 'integer', 'between:1,12'],
            'anchor_day' => ['required', 'integer', 'between:1,28'],
            'months_after_anchor' => ['required', 'integer', 'between:0,11'],
        ]);

        $skill = Skill::query()->where('slug', $validated['skill'])->sole();

        $alreadyScheduled = TaskSchedule::query()
            ->where('team_id', $team->id)
            ->where('skill_id', $skill->id)
            ->exists();

        if ($alreadyScheduled) {
            return back()->withErrors(['skill' => 'That task is already on the calendar.']);
        }

        $cadence = Cadence::from($validated['cadence']);
        $anchoredToYearEnd = (bool) ($validated['anchored_to_fiscal_year_end'] ?? false);
        $fiscalYearEndMonth = $team->organizationProfile?->fiscal_year_end_month;

        if ($anchoredToYearEnd && $fiscalYearEndMonth === null) {
            return back()->withErrors([
                'anchored_to_fiscal_year_end' => 'Set your fiscal year end on the Organization page first.',
            ]);
        }

        TaskSchedule::create([
            'team_id' => $team->id,
            'skill_id' => $skill->id,
            'cadence' => $cadence,
            'anchored_to_fiscal_year_end' => $anchoredToYearEnd,
            'anchor_month' => $validated['anchor_month'] ?? null,
            'anchor_day' => $validated['anchor_day'],
            'months_after_anchor' => $validated['months_after_anchor'],
            'next_due_at' => TaskSchedule::firstDueDate(
                cadence: $cadence,
                anchoredToFiscalYearEnd: $anchoredToYearEnd,
                anchorMonth: $validated['anchor_month'] ?? null,
                anchorDay: $validated['anchor_day'],
                monthsAfterAnchor: $validated['months_after_anchor'],
                fiscalYearEndMonth: $fiscalYearEndMonth,
            ),
        ]);

        return back()->with('status', 'Added to the calendar.');
    }

    public function update(Request $request, string $currentTeam, TaskSchedule $schedule): RedirectResponse
    {
        $team = $this->resolveTeam($request);
        abort_unless($schedule->team_id === $team->id, 404);

        $validated = $request->validate(['is_active' => ['required', 'boolean']]);

        $schedule->forceFill(['is_active' => $validated['is_active']])->save();

        return back();
    }

    public function destroy(Request $request, string $currentTeam, TaskSchedule $schedule): RedirectResponse
    {
        $team = $this->resolveTeam($request);
        abort_unless($schedule->team_id === $team->id, 404);

        $schedule->delete();

        return back()->with('status', 'Removed from the calendar.');
    }

    /**
     * Start the work this occurrence calls for. Nothing runs itself — the
     * calendar surfaces what is due and a person decides to begin.
     */
    public function run(
        Request $request,
        string $currentTeam,
        TaskSchedule $schedule,
        StartTaskRun $start,
    ): RedirectResponse {
        $team = $this->resolveTeam($request);
        abort_unless($schedule->team_id === $team->id, 404);

        try {
            $run = $start->handle(
                team: $team,
                skill: $schedule->skill,
                user: $request->user(),
                inputs: ['notes' => 'Started from the calendar.'],
                schedule: $schedule,
            );
        } catch (GateViolation $e) {
            return back()->withErrors(['schedule' => $e->getMessage()]);
        }

        return to_route('tasks.show', ['current_team' => $team->slug, 'taskRun' => $run->id]);
    }

    protected function resolveTeam(Request $request): Team
    {
        return Team::query()
            ->where('slug', $request->route('current_team'))
            ->firstOrFail();
    }
}
