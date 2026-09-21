<?php

namespace App\Enums;

/**
 * Annual operating budget, banded.
 *
 * This is not decoration. Supervision needs at least two people — someone to do
 * the work and someone else to review it — and organizations under roughly $1M
 * typically have one to five staff and no professional on call. Recording the
 * band lets the app say so honestly at onboarding rather than selling a gate
 * the organization cannot staff.
 */
enum BudgetBand: string
{
    case Under250k = 'under_250k';
    case From250kTo1m = '250k_to_1m';
    case From1mTo5m = '1m_to_5m';
    case Over5m = 'over_5m';

    public function label(): string
    {
        return match ($this) {
            self::Under250k => 'Under $250k',
            self::From250kTo1m => '$250k – $1M',
            self::From1mTo5m => '$1M – $5M',
            self::Over5m => 'Over $5M',
        };
    }

    /**
     * Whether an organization this size can realistically staff a second
     * reviewer. Below the line the app warns rather than blocks.
     */
    public function canStaffReview(): bool
    {
        return match ($this) {
            self::Under250k, self::From250kTo1m => false,
            self::From1mTo5m, self::Over5m => true,
        };
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $band) => ['value' => $band->value, 'label' => $band->label()],
            self::cases(),
        );
    }
}
