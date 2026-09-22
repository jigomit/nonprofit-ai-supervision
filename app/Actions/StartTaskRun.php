<?php

namespace App\Actions;

use App\Enums\ExpertGatePolicy;
use App\Enums\TaskRunStatus;
use App\Exceptions\GateViolation;
use App\Jobs\RunTaskJob;
use App\Models\OrganizationProfile;
use App\Models\Skill;
use App\Models\TaskRun;
use App\Models\TaskSchedule;
use App\Models\Team;
use App\Models\TeamSkill;
use App\Models\User;

/**
 * Starts one task for one organization.
 *
 * Everything that governs how the output may later be released is decided and
 * written down here, at the moment the work begins: the supervision level in
 * force, and the organization's expert-gate policy. Both drift, and a record
 * that re-derives them later would quietly change the rules under work that has
 * already been done.
 */
class StartTaskRun
{
    /**
     * @param  array<string, mixed>  $inputs
     *
     * @throws GateViolation when the task is not in the organization's
     *                       catalogue, or the organization has run its
     *                       allowance for the day
     */
    public function handle(
        Team $team,
        Skill $skill,
        User $user,
        array $inputs = [],
        ?TaskSchedule $schedule = null,
        ?TaskRun $revisionOf = null,
    ): TaskRun {
        $pivot = $this->catalogueEntry($team, $skill);
        $profile = $team->organizationProfile;

        // Counted before the row is written, so a refused run leaves nothing
        // behind. A revision counts too: it is another call on the key.
        $this->guardDailyLimit($team, $profile);

        $run = TaskRun::create([
            'team_id' => $team->id,
            'skill_id' => $skill->id,
            'task_schedule_id' => $schedule?->id,
            'revised_from_id' => $revisionOf?->id,
            'requested_by' => $user->id,
            'status' => TaskRunStatus::Queued,
            'supervision_at_run' => $pivot->effectiveSupervision($skill->supervision),
            'expert_gate_policy_at_run' => $profile !== null
                ? $profile->expert_gate_policy
                : ExpertGatePolicy::default(),
            'inputs' => $inputs,
            // Provenance for the audit record: which revision of the
            // instructions produced this, and the exact bytes of them.
            'skill_body_hash' => $skill->body_hash,
            'skill_source_commit' => $skill->source_commit,
        ]);

        // Starting the work is what satisfies the occurrence, not finishing
        // it: the schedule tracks whether someone got to it, and the gate
        // tracks whether the output was any good.
        $schedule?->advance();

        RunTaskJob::dispatch($run);

        return $run;
    }

    /**
     * @throws GateViolation
     */
    protected function guardDailyLimit(Team $team, ?OrganizationProfile $profile): void
    {
        // An organization with a number of its own overrides the default,
        // including when that number is deliberately lower.
        $limit = $profile !== null && $profile->daily_run_limit !== null
            ? $profile->daily_run_limit
            : (int) config('ai.daily_run_limit', 0);

        if ($limit <= 0) {
            return;
        }

        $started = TaskRun::query()
            ->where('team_id', $team->id)
            ->where('created_at', '>=', now()->startOfDay())
            ->count();

        if ($started >= $limit) {
            throw GateViolation::dailyRunLimitReached($limit);
        }
    }

    protected function catalogueEntry(Team $team, Skill $skill): TeamSkill
    {
        $pivot = TeamSkill::query()
            ->where('team_id', $team->id)
            ->where('skill_id', $skill->id)
            ->where('enabled', true)
            ->first();

        if (! $pivot instanceof TeamSkill) {
            throw new GateViolation(
                "[{$skill->slug}] is not enabled in this organization's catalogue."
            );
        }

        return $pivot;
    }
}
