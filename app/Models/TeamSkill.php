<?php

namespace App\Models;

use App\Enums\SupervisionLevel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\Pivot;
use InvalidArgumentException;

/**
 * A skill as it exists in one organization's catalogue.
 *
 * The supervision level belongs to the task, not the tenant — that is the whole
 * premise. An organization may decide a task needs *more* review than the
 * library says, but never less, so the override is one-directional and the
 * model refuses the other direction rather than trusting callers.
 *
 * @property int $team_id
 * @property int $skill_id
 * @property bool $enabled
 * @property SupervisionLevel|null $supervision_override
 * @property-read Skill $skill
 */
#[Fillable(['team_id', 'skill_id', 'enabled', 'supervision_override'])]
class TeamSkill extends Pivot
{
    protected $table = 'team_skill';

    public $incrementing = true;

    /**
     * The level that actually governs this task here: the organization's
     * stricter override when set, otherwise the library's own call.
     */
    public function effectiveSupervision(SupervisionLevel $libraryLevel): SupervisionLevel
    {
        if ($this->supervision_override === null) {
            return $libraryLevel;
        }

        return $this->supervision_override->isAtLeast($libraryLevel)
            ? $this->supervision_override
            : $libraryLevel;
    }

    /**
     * @throws InvalidArgumentException when the override would loosen the gate
     */
    public function overrideSupervision(?SupervisionLevel $level, SupervisionLevel $libraryLevel): void
    {
        if ($level !== null && ! $level->isAtLeast($libraryLevel)) {
            throw new InvalidArgumentException(
                "Cannot lower supervision from [{$libraryLevel->value}] to [{$level->value}]."
            );
        }

        $this->supervision_override = $level;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'supervision_override' => SupervisionLevel::class,
        ];
    }
}
