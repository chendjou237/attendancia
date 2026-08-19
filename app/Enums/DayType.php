<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum DayType: string implements HasLabel
{
    case Teaching = 'teaching';
    case PublicHoliday = 'public_holiday';
    case SchoolClosure = 'school_closure';
    case HalfDay = 'half_day';
    case ClassesSuspended = 'classes_suspended';

    /**
     * §8.1: holiday/closure days have no expected sessions at all.
     */
    public function hasNoExpectedSessions(): bool
    {
        return match ($this) {
            self::PublicHoliday, self::SchoolClosure => true,
            default => false,
        };
    }

    public function getLabel(): string
    {
        return __('attendance.day_types.'.$this->value);
    }
}
