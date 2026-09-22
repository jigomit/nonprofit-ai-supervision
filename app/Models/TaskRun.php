<?php

namespace App\Models;

use App\Enums\ExpertGatePolicy;
use App\Enums\SupervisionLevel;
use App\Enums\TaskRunStatus;
use Database\Factories\TaskRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One attempt at one task for one organization.
 *
 * The supervision level and the organization's expert-gate policy are copied
 * onto the row when the run starts. Both change over time — the library revises
 * its levels and an organization can change its policy — and an audit record
 * that silently reinterprets itself is not an audit record.
 *
 * @property int $id
 * @property int $team_id
 * @property int $skill_id
 * @property int|null $task_schedule_id
 * @property int|null $revised_from_id
 * @property int $requested_by
 * @property TaskRunStatus $status
 * @property SupervisionLevel $supervision_at_run
 * @property ExpertGatePolicy $expert_gate_policy_at_run
 * @property array<string, mixed>|null $inputs
 * @property string|null $output
 * @property string|null $failure_reason
 * @property string|null $model
 * @property array<string, mixed>|null $usage
 * @property string|null $skill_body_hash
 * @property string|null $skill_source_commit
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $released_at
 * @property bool $released_without_expert
 * @property-read Team $team
 * @property-read Skill $skill
 * @property-read User $requester
 * @property-read Collection<int, Approval> $approvals
 * @property-read Collection<int, ExpertInvitation> $expertInvitations
 */
#[Fillable([
    'team_id', 'skill_id', 'task_schedule_id', 'revised_from_id', 'requested_by', 'status', 'supervision_at_run',
    'expert_gate_policy_at_run', 'inputs', 'output', 'failure_reason', 'model',
    'usage', 'skill_body_hash', 'skill_source_commit', 'started_at',
    'completed_at', 'released_at', 'released_without_expert',
])]
class TaskRun extends Model
{
    /** @use HasFactory<TaskRunFactory> */
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
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * The run this one was started to replace, if any.
     *
     * @return BelongsTo<TaskRun, $this>
     */
    public function revisedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'revised_from_id');
    }

    /**
     * @return HasMany<TaskRun, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(self::class, 'revised_from_id');
    }

    /**
     * Whether the output may be taken out of the application.
     *
     * Only released work. A draft is readable so it can be reviewed, but
     * handing someone a download button before the gate has cleared would
     * build the bypass this whole application exists to close.
     */
    public function isExportable(): bool
    {
        return $this->status->isReleased() && $this->output !== null;
    }

    /**
     * @return HasMany<TaskRunAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(TaskRunAttachment::class);
    }

    /**
     * @return HasMany<Approval, $this>
     */
    public function approvals(): HasMany
    {
        return $this->hasMany(Approval::class);
    }

    /**
     * @return HasMany<ExpertInvitation, $this>
     */
    public function expertInvitations(): HasMany
    {
        return $this->hasMany(ExpertInvitation::class);
    }

    /**
     * @param  Builder<TaskRun>  $query
     * @return Builder<TaskRun>
     */
    public function scopeAwaitingDecision(Builder $query): Builder
    {
        return $query->whereIn('status', [
            TaskRunStatus::AwaitingReview->value,
            TaskRunStatus::AwaitingExpert->value,
        ]);
    }

    /**
     * Whether the output may be used yet.
     */
    public function isReleased(): bool
    {
        return $this->status->isReleased();
    }

    /**
     * Whether an owner may let this through without a credentialed sign-off.
     * Only ever true for an expert gate under a policy that permits it.
     */
    public function allowsExpertOverride(): bool
    {
        return $this->status === TaskRunStatus::AwaitingExpert
            && $this->expert_gate_policy_at_run->allowsOverride();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TaskRunStatus::class,
            'supervision_at_run' => SupervisionLevel::class,
            'expert_gate_policy_at_run' => ExpertGatePolicy::class,
            'inputs' => 'array',
            'usage' => 'array',
            'released_without_expert' => 'boolean',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }
}
