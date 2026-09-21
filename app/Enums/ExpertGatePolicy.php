<?php

namespace App\Enums;

/**
 * What an organization does when a task needs a credentialed professional and
 * none is available.
 *
 * Most small nonprofits have no CPA or attorney on call, and a gate that simply
 * stops teaches people to do the work in a personal ChatGPT tab instead — which
 * is the shadow use this application exists to convert into a record. So the
 * default lets the work through and marks it, permanently, as released without
 * expert review. The flag is the product; the wall is not.
 */
enum ExpertGatePolicy: string
{
    case OverrideWithJustification = 'override_with_justification';
    case Block = 'block';

    public function label(): string
    {
        return match ($this) {
            self::OverrideWithJustification => 'Allow an override, on the record',
            self::Block => 'Block until an expert signs off',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::OverrideWithJustification => 'An owner can release expert-required work without a credentialed sign-off, but must say who decided and why. The release stays flagged on the record and in the board report.',
            self::Block => 'Expert-required work cannot be released at all until a credentialed professional signs off. Strictest setting; choose it if a funder or your board requires it.',
        };
    }

    public function allowsOverride(): bool
    {
        return $this === self::OverrideWithJustification;
    }

    public static function default(): self
    {
        return self::OverrideWithJustification;
    }
}
