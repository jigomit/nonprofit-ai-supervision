<?php

namespace Database\Factories;

use App\Enums\ExpertGatePolicy;
use App\Enums\SupervisionLevel;
use App\Enums\TaskRunStatus;
use App\Models\Skill;
use App\Models\TaskRun;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaskRun>
 */
class TaskRunFactory extends Factory
{
    protected $model = TaskRun::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'skill_id' => Skill::factory(),
            'requested_by' => User::factory(),
            'status' => TaskRunStatus::AwaitingReview->value,
            'supervision_at_run' => SupervisionLevel::Review->value,
            'expert_gate_policy_at_run' => ExpertGatePolicy::default()->value,
            'inputs' => ['notes' => 'Draft it for the spring appeal.'],
            'output' => 'A draft.',
            'model' => 'claude-opus-5',
            'started_at' => now()->subMinute(),
            'completed_at' => now(),
        ];
    }

    public function awaitingExpert(ExpertGatePolicy $policy = ExpertGatePolicy::OverrideWithJustification): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TaskRunStatus::AwaitingExpert->value,
            'supervision_at_run' => SupervisionLevel::ExpertRequired->value,
            'expert_gate_policy_at_run' => $policy->value,
        ]);
    }

    public function released(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TaskRunStatus::Released->value,
            'supervision_at_run' => SupervisionLevel::Unsupervised->value,
            'released_at' => now(),
        ]);
    }

    public function queued(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TaskRunStatus::Queued->value,
            'output' => null,
            'started_at' => null,
            'completed_at' => null,
        ]);
    }
}
