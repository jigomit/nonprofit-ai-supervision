<?php

namespace App\Models;

use App\Enums\CredentialType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A single-use link that lets an outside professional clear one expert gate.
 *
 * The organization invites the accountant or attorney it already works with.
 * There is no account to create and no credential to verify here: the standing
 * is claimed and recorded, and the professional relationship that makes the
 * claim meaningful lives outside this application.
 *
 * @property int $id
 * @property string $token
 * @property int $task_run_id
 * @property int $invited_by
 * @property string $email
 * @property string|null $name
 * @property CredentialType|null $credential_type
 * @property Carbon $expires_at
 * @property Carbon|null $used_at
 * @property-read TaskRun $taskRun
 * @property-read User $inviter
 */
#[Fillable([
    'token', 'task_run_id', 'invited_by', 'email', 'name',
    'credential_type', 'expires_at', 'used_at',
])]
class ExpertInvitation extends Model
{
    /** How long an invitation stays usable. */
    public const LIFETIME_DAYS = 14;

    public static function generateToken(): string
    {
        return Str::random(64);
    }

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
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /**
     * @param  Builder<ExpertInvitation>  $query
     * @return Builder<ExpertInvitation>
     */
    public function scopeUsable(Builder $query): Builder
    {
        return $query->whereNull('used_at')->where('expires_at', '>', now());
    }

    public function isUsable(): bool
    {
        return $this->used_at === null && $this->expires_at->isFuture();
    }

    public function getRouteKeyName(): string
    {
        return 'token';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'credential_type' => CredentialType::class,
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }
}
