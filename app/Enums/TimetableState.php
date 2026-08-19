<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum TimetableState: string implements HasLabel
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';

    public function getLabel(): string
    {
        return __('attendance.timetable_states.'.$this->value);
    }
}
