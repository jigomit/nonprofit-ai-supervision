<?php

namespace App\Models;

use App\Enums\SupervisionLevel;
use Database\Factories\SkillFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One task from the nonprofit skills library, with the supervision level that
 * governs whether its output may be released.
 *
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string $description
 * @property string $category
 * @property bool $is_core
 * @property SupervisionLevel $supervision
 * @property string $supervision_note
 * @property string $body
 * @property string $body_hash
 * @property int $token_estimate
 * @property string|null $source_commit
 * @property Carbon|null $date_added
 * @property Carbon|null $last_reviewed
 * @property string|null $license
 * @property-read Collection<int, Skill> $relatedSkills
 * @property-read Collection<int, Team> $teams
 * @property-read Collection<int, SupervisionChange> $supervisionChanges
 */
#[Fillable([
    'slug', 'name', 'description', 'category', 'is_core',
    'supervision', 'supervision_note', 'body', 'body_hash', 'token_estimate',
    'source_commit', 'date_added', 'last_reviewed', 'license',
])]
class Skill extends Model
{
    /** @use HasFactory<SkillFactory> */
    use HasFactory;

    /**
     * Skills this one points at via "(use nonprofit-x)" in its description.
     *
     * @return BelongsToMany<Skill, $this>
     */
    public function relatedSkills(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'skill_links', 'from_skill_id', 'to_skill_id')
            ->withTimestamps();
    }

    /**
     * Organizations whose catalogue this task is in.
     *
     * @return BelongsToMany<Team, $this, TeamSkill, 'pivot'>
     */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'team_skill')
            ->using(TeamSkill::class)
            ->withPivot(['enabled', 'supervision_override'])
            ->withTimestamps();
    }

    /**
     * @return HasMany<SupervisionChange, $this>
     */
    public function supervisionChanges(): HasMany
    {
        return $this->hasMany(SupervisionChange::class);
    }

    /**
     * @param  Builder<Skill>  $query
     * @return Builder<Skill>
     */
    public function scopeCore(Builder $query): Builder
    {
        return $query->where('is_core', true);
    }

    /**
     * @param  Builder<Skill>  $query
     * @return Builder<Skill>
     */
    public function scopeSupervision(Builder $query, SupervisionLevel $level): Builder
    {
        return $query->where('supervision', $level->value);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_core' => 'boolean',
            'supervision' => SupervisionLevel::class,
            'token_estimate' => 'integer',
            'date_added' => 'date',
            'last_reviewed' => 'date',
        ];
    }
}
