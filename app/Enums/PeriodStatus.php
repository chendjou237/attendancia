<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum PeriodStatus: string implements HasLabel
{
    case Present = 'present';
    case PresentAdmin = 'present_admin';
    case Absent = 'absent';
    case AbsentJustified = 'absent_justified';
    case Unpaired = 'unpaired';
    case LocationMismatch = 'location_mismatch';

    /**
     * §9: statuses that count as taught for the monthly hours total.
     */
    public function countsAsTaught(): bool
    {
        return match ($this) {
            self::Present, self::PresentAdmin => true,
            default => false,
        };
    }

    /**
     * §9: pending statuses are not a judgement and must reach a human,
     * never resolve silently.
     */
    public function isPending(): bool
    {
        return match ($this) {
            self::Unpaired, self::LocationMismatch => true,
            default => false,
        };
    }

    public function getLabel(): string
    {
        return __('attendance.statuses.'.$this->value);
    }
}
