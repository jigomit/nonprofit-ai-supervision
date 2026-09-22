<?php

namespace Database\Factories;

use App\Enums\SupervisionLevel;
use App\Models\Skill;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Skill>
 */
class SkillFactory extends Factory
{
    protected $model = Skill::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // paragraphs() is typed as array|string whichever way it is called, so
        // build the body from paragraph(), which is unambiguously a string.
        $body = collect(range(1, 6))
            ->map(fn () => fake()->paragraph())
            ->implode("\n\n");

        return [
            'slug' => 'nonprofit-'.fake()->unique()->slug(2),
            'name' => fake()->sentence(3),
            'description' => fake()->sentence(12),
            'category' => fake()->randomElement(config('skills.core_categories')),
            'is_core' => true,
            'supervision' => SupervisionLevel::Review->value,
            'supervision_note' => fake()->sentence(10),
            // Most of the library carries these, so the default should too —
            // a factory that omits them hides the pre-flight from every test
            // that does not ask for it.
            'failure_modes' => [fake()->sentence(12), fake()->sentence(10)],
            'deliverables' => [fake()->sentence(8)],
            'body' => $body,
            'body_hash' => hash('sha256', $body),
            'token_estimate' => (int) ceil(strlen($body) / 3.5),
            'source_commit' => str_repeat('a', 40),
            'date_added' => now()->subMonth()->toDateString(),
            'last_reviewed' => null,
            'license' => 'MIT',
        ];
    }

    /** A skill from the seven that document neither. */
    public function withoutPreflight(): static
    {
        return $this->state(fn (array $attributes) => [
            'failure_modes' => [],
            'deliverables' => [],
        ]);
    }

    public function unsupervised(): static
    {
        return $this->state(fn (array $attributes) => [
            'supervision' => SupervisionLevel::Unsupervised->value,
        ]);
    }

    public function expertRequired(): static
    {
        return $this->state(fn (array $attributes) => [
            'supervision' => SupervisionLevel::ExpertRequired->value,
        ]);
    }

    public function specialCollection(string $category = 'faith-based'): static
    {
        return $this->state(fn (array $attributes) => [
            'category' => $category,
            'is_core' => false,
        ]);
    }
}
