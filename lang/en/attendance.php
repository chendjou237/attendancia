<?php

return [

    'statuses' => [
        'present' => 'Present',
        'present_admin' => 'Present (administrative)',
        'absent' => 'Absent',
        'absent_justified' => 'Absent (justified)',
        'unpaired' => 'Unpaired',
        'location_mismatch' => 'Location mismatch',
    ],

    'day_types' => [
        'teaching' => 'Teaching day',
        'public_holiday' => 'Public holiday',
        'school_closure' => 'School closure',
        'half_day' => 'Half day',
        'classes_suspended' => 'Classes suspended',
    ],

    'session_states' => [
        'paired' => 'Paired',
        'unpaired' => 'Unpaired',
    ],

    'sources' => [
        'scan' => 'Scan',
        'administrative' => 'Administrative',
        'override' => 'Override',
    ],

    'roles' => [
        'admin' => 'Admin',
        'officer' => 'Officer',
        'principal' => 'Principal',
        'hr' => 'HR',
    ],

    'employment_types' => [
        'hourly' => 'Hourly-paid',
        'salaried' => 'Salaried',
    ],

    'timetable_states' => [
        'draft' => 'Draft',
        'submitted' => 'Submitted',
        'approved' => 'Approved',
    ],

    'report_states' => [
        'draft' => 'Draft',
        'officer_reviewed' => 'Officer reviewed',
        'principal_approved' => 'Principal approved',
        'sent_to_hr' => 'Sent to HR',
    ],

];
