<?php

namespace App\Exceptions;

use DomainException;

/**
 * Thrown when something tries to release work the gate does not permit.
 *
 * This is deliberately an exception rather than a validation failure. A caller
 * that reaches one of these has bypassed the interface, and the gate is the one
 * guarantee the product makes — it should break loudly, not return false.
 */
class GateViolation extends DomainException
{
    public static function notAwaitingDecision(string $status): self
    {
        return new self("This work is not waiting on a decision (it is {$status}).");
    }

    public static function expertCredentialRequired(): self
    {
        return new self('Approving expert-required work needs a credentialed professional.');
    }

    public static function overrideNotPermitted(): self
    {
        return new self('This organization does not allow releasing expert-required work without a sign-off.');
    }

    public static function overrideNeedsJustification(): self
    {
        return new self('Releasing without expert review has to say who decided and why.');
    }

    public static function overrideOnlyAppliesToExpertGate(): self
    {
        return new self('Only expert-required work can be released without expert review.');
    }

    public static function invitationNotUsable(): self
    {
        return new self('That expert invitation has already been used or has expired.');
    }

    public static function ambiguousApprover(): self
    {
        return new self('An approval is either by a member or by an invited expert, not both.');
    }
}
