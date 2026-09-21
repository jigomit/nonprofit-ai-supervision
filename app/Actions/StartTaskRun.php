<?php

namespace App\Actions;

use App\Enums\ExpertGatePolicy;
use App\Enums\TaskRunStatus;
use App\Exceptions\GateViolation;
use App\Jobs\RunTaskJob;
use App\Models\Skill;
use App\Models\TaskRun;
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
     * @throws GateViolation when the task is not in the organization's catalogue
     */
    public function handle(Team $team, Skill $skill, User $user, array $inputs = []): TaskRun
    {
        $pivot = $this->catalogueEntry($team, $skill);
        $profile = $team->organizationProfile;

        $run = TaskRun::create([
            'team_id' => $team->id,
            'skill_id' => $skill->id,
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

        RunTaskJob::dispatch($run);

        return $run;
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
