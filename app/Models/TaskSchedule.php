<?php

namespace App\Models;

use App\Enums\Cadence;
use Carbon\CarbonInterface;
use Database\Factories\TaskScheduleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;

/**
 * A recurring piece of work in an organization's calendar.
 *
 * Nothing here runs itself. A schedule says when work is due and surfaces it;
 * a person still starts it. Auto-running would fill the approval queue with
 * drafts nobody asked for, and the premise of the product is that a human
 * decides — which starts with deciding to begin.
 *
 * @property int $id
 * @property int $team_id
 * @property int $skill_id
 * @property Cadence $cadence
 * @property bool $anchored_to_fiscal_year_end
 * @property int|null $anchor_month
 * @property int $anchor_day
 * @property int $months_after_anchor
 * @property Carbon $next_due_at
 * @property Carbon|null $last_started_at
 * @property bool $is_active
 * @property-read Team $team
 * @property-read Skill $skill
 */
#[Fillable([
    'team_id', 'skill_id', 'cadence', 'anchored_to_fiscal_year_end',
    'anchor_month', 'anchor_day', 'months_after_anchor', 'next_due_at',
    'last_started_at', 'is_active',
])]
class TaskSchedule extends Model
{
    /** @use HasFactory<TaskScheduleFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return BelongsTo<Skill, $this>
     */
    public function skill(): BelongsTo
    {
        return $this->belongsTo(Skill::class);
    }

    /**
     * @param  Builder<TaskSchedule>  $query
     * @return Builder<TaskSchedule>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<TaskSchedule>  $query
     * @return Builder<TaskSchedule>
     */
    public function scopeDue(Builder $query, ?CarbonInterface $on = null): Builder
    {
        return $query->active()->whereDate('next_due_at', '<=', ($on ?? now())->toDateString());
    }

    public function isOverdue(?CarbonInterface $on = null): bool
    {
        return $this->is_active && $this->next_due_at->lt(($on ?? now())->startOfDay());
    }

    public function daysUntilDue(?CarbonInterface $on = null): int
    {
        return (int) ($on ?? now())->startOfDay()->diffInDays($this->next_due_at, false);
    }

    /**
     * The first date this schedule falls due, given when it was set up.
     *
     * A schedule anchored to the fiscal year end moves with the organization:
     * `months_after_anchor` is counted from the last day of the fiscal year, so
     * "four months after year end" stays correct whenever that year ends.
     */
    public static function firstDueDate(
        Cadence $cadence,
        ?CarbonInterface $from = null,
        bool $anchoredToFiscalYearEnd = false,
        ?int $anchorMonth = null,
        int $anchorDay = 1,
        int $monthsAfterAnchor = 0,
        ?int $fiscalYearEndMonth = null,
    ): CarbonInterface {
        $from = ($from ?? now())->copy()->startOfDay();

        $month = match (true) {
            $anchoredToFiscalYearEnd => $fiscalYearEndMonth ?? 12,
            $anchorMonth !== null => $anchorMonth,
            default => $from->month,
        };

        $due = Date::create($from->year, $month, 1)
            ->addMonthsNoOverflow($monthsAfterAnchor);

        $due = $due->day(min($anchorDay, $due->daysInMonth));

        // Anything already past rolls forward a whole period, so a schedule
        // created today is never born overdue.
        while ($due->lt($from)) {
            $due = $cadence->advance($due);
        }

        return $due;
    }

    /**
     * Move past the occurrence just satisfied. Loops rather than adding once,
     * so a schedule left alone for months lands on the next genuinely future
     * date instead of catching up one period at a time.
     */
    public function advance(?CarbonInterface $on = null): void
    {
        $on = ($on ?? now())->copy()->startOfDay();
        $next = $this->cadence->advance($this->next_due_at);

        while ($next->lte($on)) {
            $next = $this->cadence->advance($next);
        }

        $this->forceFill([
            'next_due_at' => $next,
            'last_started_at' => now(),
        ])->save();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cadence' => Cadence::class,
            'anchored_to_fiscal_year_end' => 'boolean',
            'anchor_month' => 'integer',
            'anchor_day' => 'integer',
            'months_after_anchor' => 'integer',
            'next_due_at' => 'date',
            'last_started_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }
}
