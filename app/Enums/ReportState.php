<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ReportState: string implements HasLabel
{
    case Draft = 'draft';
    case OfficerReviewed = 'officer_reviewed';
    case PrincipalApproved = 'principal_approved';
    case SentToHr = 'sent_to_hr';

    public function getLabel(): string
    {
        return __('attendance.report_states.'.$this->value);
    }
}
