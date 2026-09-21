<?php

namespace App\Enums;

/**
 * The professional standing an approver claims when clearing an
 * expert-required gate.
 *
 * The library's own test for that level is narrow: the output goes to an
 * outside authority, or it legally binds the organization. These are the
 * professions that can answer for that, and the claim is recorded rather than
 * verified — the organization invites its own accountant or attorney, and the
 * record says who signed and what they said they were.
 */
enum CredentialType: string
{
    case Cpa = 'cpa';
    case Attorney = 'attorney';
    case LicensedAuditor = 'licensed_auditor';

    public function label(): string
    {
        return match ($this) {
            self::Cpa => 'CPA',
            self::Attorney => 'Attorney',
            self::LicensedAuditor => 'Licensed auditor',
        };
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $type) => ['value' => $type->value, 'label' => $type->label()],
            self::cases(),
        );
    }
}
