<?php

return [

    /*
    |--------------------------------------------------------------------------
    | School timezone
    |--------------------------------------------------------------------------
    |
    | period_slots.start_time/end_time are wall-clock times as the school
    | thinks about them. Everything is stored in UTC (§14); this is the
    | offset used to convert a slot's local time into the UTC instant it
    | actually falls on. Cameroon is WAT (UTC+1) year-round, no DST.
    |
    */
    'timezone' => env('ATTENDANCE_TIMEZONE', 'Africa/Douala'),

];
