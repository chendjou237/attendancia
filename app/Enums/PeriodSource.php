<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum PeriodSource: string implements HasLabel
{
    case Scan = 'scan';
    case Administrative = 'administrative';
    case Override = 'override';

    public function getLabel(): string
    {
        return __('attendance.sources.'.$this->value);
    }
}
