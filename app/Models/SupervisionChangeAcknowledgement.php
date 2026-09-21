<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One organization confirming it has seen a change to a supervision level.
 *
 * @property int $id
 * @property int $team_id
 * @property int $supervision_change_id
 * @property int $acknowledged_by
 * @property Carbon $acknowledged_at
 */
#[Fillable(['team_id', 'supervision_change_id', 'acknowledged_by', 'acknowledged_at'])]
class SupervisionChangeAcknowledgement extends Model
{
    protected $table = 'supervision_acknowledgements';

    /**
     * @return BelongsTo<SupervisionChange, $this>
     */
    public function supervisionChange(): BelongsTo
    {
        return $this->belongsTo(SupervisionChange::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function acknowledger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'acknowledged_at' => 'datetime',
        ];
    }
}
