<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Why a session ended up UNPAIRED — stored in sessions.anomaly_code so
 * the exception queue (Phase B) doesn't have to re-derive it, and so the
 * rule engine can distinguish a plain missing scan from a location
 * mismatch (§9's LOCATION_MISMATCH status).
 */
enum SessionAnomaly: string implements HasLabel
{
    case NoScanIn = 'no_scan_in';
    case NoScanOut = 'no_scan_out';
    case TooShort = 'too_short';
    case LocationMismatch = 'location_mismatch';

    public function getLabel(): string
    {
        return __('attendance.session_anomalies.'.$this->value);
    }
}
