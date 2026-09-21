<?php

namespace App\Enums;

/**
 * Where a piece of work sits between being asked for and being usable.
 *
 * The terminal state that matters is Released: until a run reaches it, the
 * output is not to be filed, sent, or relied on. Which gate stands between
 * Running and Released is decided by the task's supervision level, snapshotted
 * on the run itself.
 */
enum TaskRunStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Failed = 'failed';
    case AwaitingReview = 'awaiting_review';
    case AwaitingExpert = 'awaiting_expert';
    case Released = 'released';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Queued',
            self::Running => 'Running',
            self::Failed => 'Failed',
            self::AwaitingReview => 'Waiting for review',
            self::AwaitingExpert => 'Waiting for an expert',
            self::Released => 'Released',
            self::Rejected => 'Rejected',
        };
    }

    /** Whether the output may be used. */
    public function isReleased(): bool
    {
        return $this === self::Released;
    }

    /** Whether the run is finished, one way or another. */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Released, self::Rejected, self::Failed], true);
    }

    /** Whether someone still has to act on this run. */
    public function isAwaitingDecision(): bool
    {
        return in_array($this, [self::AwaitingReview, self::AwaitingExpert], true);
    }

    /**
     * The state a finished run lands in, given the level that governs it.
     */
    public static function gateFor(SupervisionLevel $level): self
    {
        return match ($level) {
            SupervisionLevel::Unsupervised => self::Released,
            SupervisionLevel::Review => self::AwaitingReview,
            SupervisionLevel::ExpertRequired => self::AwaitingExpert,
        };
    }
}
