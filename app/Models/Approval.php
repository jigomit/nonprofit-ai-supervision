<?php

namespace App\Models;

use App\Enums\ApprovalDecision;
use App\Enums\CredentialType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A person's decision about one run, kept permanently.
 *
 * This is the record an organization shows its board or a funder: who cleared
 * this work, what standing they claimed, and when. Rows are never edited after
 * the fact — a changed mind is a new run, not a rewritten history.
 *
 * @property int $id
 * @property int $task_run_id
 * @property int|null $user_id
 * @property int|null $expert_invitation_id
 * @property ApprovalDecision $decision
 * @property CredentialType|null $credential_type
 * @property string|null $credential_reference
 * @property string|null $approver_name
 * @property string|null $justification
 * @property Carbon $decided_at
 * @property-read TaskRun $taskRun
 * @property-read User|null $user
 */
#[Fillable([
    'task_run_id', 'user_id', 'expert_invitation_id', 'decision',
    'credential_type', 'credential_reference', 'approver_name',
    'justification', 'decided_at',
])]
class Approval extends Model
{
    /**
     * @return BelongsTo<TaskRun, $this>
     */
    public function taskRun(): BelongsTo
    {
        return $this->belongsTo(TaskRun::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<ExpertInvitation, $this>
     */
    public function expertInvitation(): BelongsTo
    {
        return $this->belongsTo(ExpertInvitation::class);
    }

    /** Whether this decision carries a claimed professional credential. */
    public function isCredentialed(): bool
    {
        return $this->credential_type !== null;
    }

    /**
     * How this decision should read on a board report.
     */
    public function summary(): string
    {
        $user = $this->user;
        $who = $this->approver_name ?? ($user !== null ? $user->name : null) ?? 'Someone';

        return match (true) {
            $this->decision === ApprovalDecision::ReleasedWithoutExpert => "{$who} released this without expert review",
            $this->isCredentialed() => "{$who} ({$this->credential_type?->label()}) approved this",
            default => "{$who} {$this->decision->label()} this",
        };
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'decision' => ApprovalDecision::class,
            'credential_type' => CredentialType::class,
            'decided_at' => 'datetime',
        ];
    }
}
