<?php

namespace App\Enums;

/**
 * How much human review a task genuinely needs before its output is used.
 *
 * These three values come from the source library's `metadata.supervision`
 * field and are the product's central abstraction: the level is a property of
 * the task, set upstream, not a per-tenant preference. An organization may make
 * a task stricter, never looser.
 */
enum SupervisionLevel: string
{
    case Unsupervised = 'unsupervised';
    case Review = 'review';
    case ExpertRequired = 'expert-required';

    public function label(): string
    {
        return match ($this) {
            self::Unsupervised => 'Unsupervised',
            self::Review => 'Needs review',
            self::ExpertRequired => 'Expert required',
        };
    }

    /**
     * What has to happen before output at this level may be released.
     */
    public function gate(): string
    {
        return match ($this) {
            self::Unsupervised => 'Released as soon as it is produced. A mistake costs time, not much else.',
            self::Review => 'A knowledgeable staff member must read it before it is used or circulated.',
            self::ExpertRequired => 'A credentialed professional must sign off before it is filed, adopted, or relied on.',
        };
    }

    /**
     * Higher means more supervision. Used to compare an organization's override
     * against the upstream level so an override can only ever tighten the gate.
     */
    public function severity(): int
    {
        return match ($this) {
            self::Unsupervised => 1,
            self::Review => 2,
            self::ExpertRequired => 3,
        };
    }

    public function isAtLeast(self $other): bool
    {
        return $this->severity() >= $other->severity();
    }

    /** Whether output at this level may be released without any approval. */
    public function releasesAutomatically(): bool
    {
        return $this === self::Unsupervised;
    }
}
