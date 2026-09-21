<?php

namespace Database\Factories;

use App\Enums\Cadence;
use App\Models\Skill;
use App\Models\TaskSchedule;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaskSchedule>
 */
class TaskScheduleFactory extends Factory
{
    protected $model = TaskSchedule::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'skill_id' => Skill::factory(),
            'cadence' => Cadence::Annually->value,
            'anchored_to_fiscal_year_end' => false,
            'anchor_month' => 6,
            'anchor_day' => 1,
            'months_after_anchor' => 0,
            'next_due_at' => now()->addMonth()->toDateString(),
            'is_active' => true,
        ];
    }

    public function due(): static
    {
        return $this->state(fn (array $attributes) => [
            'next_due_at' => now()->toDateString(),
        ]);
    }

    public function overdue(): static
    {
        return $this->state(fn (array $attributes) => [
            'next_due_at' => now()->subWeek()->toDateString(),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
