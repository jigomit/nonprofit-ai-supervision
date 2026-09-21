<?php

namespace App\Enums;

use Carbon\CarbonInterface;

/**
 * How often a piece of recurring work comes round.
 *
 * Most nonprofit work is calendar-driven rather than ad hoc — 79 of the 102
 * tasks in the library describe an annual cycle and 46 name an explicit
 * deadline — so the schedule is what turns a catalogue into an operating
 * rhythm.
 */
enum Cadence: string
{
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case Annually = 'annually';

    public function label(): string
    {
        return match ($this) {
            self::Monthly => 'Monthly',
            self::Quarterly => 'Quarterly',
            self::Annually => 'Annually',
        };
    }

    public function months(): int
    {
        return match ($this) {
            self::Monthly => 1,
            self::Quarterly => 3,
            self::Annually => 12,
        };
    }

    /**
     * Move a due date forward by one full period, keeping the day of month.
     */
    public function advance(CarbonInterface $from): CarbonInterface
    {
        return $from->copy()->addMonthsNoOverflow($this->months());
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $cadence) => ['value' => $cadence->value, 'label' => $cadence->label()],
            self::cases(),
        );
    }
}
