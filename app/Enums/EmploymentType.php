<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum EmploymentType: string implements HasLabel
{
    case Hourly = 'hourly';
    case Salaried = 'salaried';

    public function getLabel(): string
    {
        return __('attendance.employment_types.'.$this->value);
    }
}
