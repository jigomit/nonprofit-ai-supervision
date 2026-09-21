<?php

namespace App\Models;

use App\Enums\BudgetBand;
use App\Enums\ExpertGatePolicy;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The nonprofit-specific facts about a team: what kind of organization it is,
 * which special collections apply to it, and how it handles the expert gate.
 *
 * @property int $id
 * @property int $team_id
 * @property string|null $entity_type
 * @property string|null $ein
 * @property string|null $state_of_incorporation
 * @property int|null $fiscal_year_end_month
 * @property BudgetBand|null $budget_band
 * @property string|null $mission
 * @property ExpertGatePolicy $expert_gate_policy
 * @property array<int, string>|null $collections
 * @property Carbon|null $onboarded_at
 * @property-read Team $team
 */
#[Fillable([
    'team_id', 'entity_type', 'ein', 'state_of_incorporation', 'fiscal_year_end_month',
    'budget_band', 'mission', 'expert_gate_policy', 'collections', 'onboarded_at',
])]
class OrganizationProfile extends Model
{
    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function isOnboarded(): bool
    {
        return $this->onboarded_at !== null;
    }

    /**
     * Whether this organization is large enough to staff a second reviewer.
     * Used to warn during onboarding rather than to block anything.
     */
    public function canStaffReview(): bool
    {
        return $this->budget_band?->canStaffReview() ?? true;
    }

    /**
     * @return array<int, string>
     */
    public function enabledCollections(): array
    {
        return array_values(array_filter((array) ($this->collections ?? [])));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'budget_band' => BudgetBand::class,
            'expert_gate_policy' => ExpertGatePolicy::class,
            'collections' => 'array',
            'fiscal_year_end_month' => 'integer',
            'onboarded_at' => 'datetime',
        ];
    }
}
