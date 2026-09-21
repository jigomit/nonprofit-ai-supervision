<?php

namespace App\Models;

use App\Enums\SupervisionLevel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Records that an import moved a skill between supervision levels.
 *
 * The upstream library revises these calls — two community-finance skills moved
 * from review to expert-required in September 2026 — so a change has to surface
 * rather than silently altering the gate on work an organization already runs.
 *
 * @property int $id
 * @property int $skill_id
 * @property SupervisionLevel $from_level
 * @property SupervisionLevel $to_level
 * @property string|null $source_commit
 * @property-read Skill $skill
 */
#[Fillable(['skill_id', 'from_level', 'to_level', 'source_commit'])]
class SupervisionChange extends Model
{
    /**
     * @return BelongsTo<Skill, $this>
     */
    public function skill(): BelongsTo
    {
        return $this->belongsTo(Skill::class);
    }

    /**
     * @return HasMany<SupervisionChangeAcknowledgement, $this>
     */
    public function acknowledgements(): HasMany
    {
        return $this->hasMany(SupervisionChangeAcknowledgement::class);
    }

    /**
     * Changes this organization has not yet been told about, limited to skills
     * it actually has enabled — a level moving on work it never runs is not
     * news to it.
     *
     * @param  Builder<SupervisionChange>  $query
     * @return Builder<SupervisionChange>
     */
    public function scopeUnacknowledgedBy(Builder $query, int $teamId): Builder
    {
        return $query
            ->whereHas('skill.teams', fn ($q) => $q
                ->where('teams.id', $teamId)
                ->where('team_skill.enabled', true))
            ->whereDoesntHave('acknowledgements', fn ($q) => $q->where('team_id', $teamId));
    }

    /** Whether this change tightened the gate rather than loosening it. */
    public function isEscalation(): bool
    {
        return $this->to_level->severity() > $this->from_level->severity();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_level' => SupervisionLevel::class,
            'to_level' => SupervisionLevel::class,
        ];
    }
}
