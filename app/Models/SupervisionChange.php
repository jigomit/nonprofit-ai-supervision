<?php

namespace App\Models;

use App\Enums\SupervisionLevel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

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
 * @property Carbon|null $acknowledged_at
 * @property-read Skill $skill
 */
#[Fillable(['skill_id', 'from_level', 'to_level', 'source_commit', 'acknowledged_at'])]
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
     * @param  Builder<SupervisionChange>  $query
     * @return Builder<SupervisionChange>
     */
    public function scopeUnacknowledged(Builder $query): Builder
    {
        return $query->whereNull('acknowledged_at');
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
            'acknowledged_at' => 'datetime',
        ];
    }
}
