<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum SessionState: string implements HasLabel
{
    case Paired = 'paired';
    case Unpaired = 'unpaired';

    public function getLabel(): string
    {
        return __('attendance.session_states.'.$this->value);
    }
}
