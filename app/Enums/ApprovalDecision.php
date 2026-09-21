<?php

namespace App\Enums;

/**
 * What a person decided about a piece of work.
 *
 * ReleasedWithoutExpert is deliberately its own decision rather than an
 * Approved with a footnote. It is the one an organization has to be able to
 * count later — on the board report, and in front of a funder — so it must be
 * impossible to confuse with a genuine credentialed sign-off.
 */
enum ApprovalDecision: string
{
    case Approved = 'approved';
    case Rejected = 'rejected';
    case ReleasedWithoutExpert = 'released_without_expert';

    public function label(): string
    {
        return match ($this) {
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::ReleasedWithoutExpert => 'Released without expert review',
        };
    }

    public function releasesWork(): bool
    {
        return $this !== self::Rejected;
    }

    /** Whether this decision has to name who decided and why. */
    public function requiresJustification(): bool
    {
        return $this === self::ReleasedWithoutExpert;
    }
}
