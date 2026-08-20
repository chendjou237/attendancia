<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Shared field/column labels
    |--------------------------------------------------------------------------
    |
    | Reused across many resources' forms/tables — reach for these before
    | inventing a resource-specific key. Resource-specific strings live
    | under 'resources.{resource}' instead.
    |
    */
    'common' => [
        'name' => 'Name',
        'code' => 'Code',
        'corridor' => 'Corridor',
        'room' => 'Room',
        'active' => 'Active',
        'created_at' => 'Created at',
        'updated_at' => 'Updated at',
        'date' => 'Date',
        'note' => 'Note',
        'language' => 'Language',
        'teacher' => 'Teacher',
        'period' => 'Period',
        'class' => 'Class',
        'status' => 'Status',
        'why' => 'Why',
        'override' => 'Override',
        'reason' => 'Reason',
        'source' => 'Source',
        'created_by' => 'Created by',
        'recorded_by' => 'Recorded by',
        'marked_by' => 'Marked by',
        'approved_by' => 'Approved by',
        'from' => 'From',
        'to' => 'To',
        'start' => 'Start',
        'end' => 'End',
        'download_pdf' => 'Download PDF',
        'view' => 'View',
        'select_placeholder' => 'Select an option…',
        'dash_placeholder' => '—',
        'ip_address' => 'IP address',
        'device' => 'Device',
        'serial' => 'Serial',
        'valid_from' => 'Valid from',
        'valid_to' => 'Valid to',
        'employment_type' => 'Employment type',
        'staff_no' => 'Staff no.',
        'full_name' => 'Full name',
    ],

    /*
    |--------------------------------------------------------------------------
    | Day names
    |--------------------------------------------------------------------------
    |
    | Carbon's dayOfWeek convention (0 = Sunday .. 6 = Saturday), already
    | used throughout this codebase — single shared source, replacing two
    | previously-duplicated hardcoded arrays (PeriodSlotForm::DAY_OPTIONS,
    | ManageTimetable::DAY_NAMES).
    |
    */
    'days' => [
        0 => 'Sunday',
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
    ],

    /*
    |--------------------------------------------------------------------------
    | Navigation labels
    |--------------------------------------------------------------------------
    |
    | Feeds getModelLabel()/getPluralModelLabel() on resources (which also
    | drives breadcrumbs and Create/Edit page titles, not just the sidebar)
    | and getTitle()/navigationLabel on custom pages.
    |
    */
    'nav' => [
        'audit_logs' => ['singular' => 'Audit log', 'plural' => 'Audit logs'],
        'calendar_days' => ['singular' => 'Calendar day', 'plural' => 'Calendar days'],
        'class_codes' => ['singular' => 'Class code', 'plural' => 'Class codes'],
        'corridors' => ['singular' => 'Corridor', 'plural' => 'Corridors'],
        'devices' => ['singular' => 'Device', 'plural' => 'Devices'],
        'monthly_reports' => ['singular' => 'Monthly report', 'plural' => 'Monthly reports'],
        'notices' => ['singular' => 'Notice', 'plural' => 'Notices'],
        'period_slots' => ['singular' => 'Period slot', 'plural' => 'Period slots'],
        'rooms' => ['singular' => 'Room', 'plural' => 'Rooms'],
        'rule_versions' => ['singular' => 'Rule version', 'plural' => 'Rule versions'],
        'teacher_biometric_ids' => ['singular' => 'Teacher biometric ID', 'plural' => 'Teacher biometric IDs'],
        'teachers' => ['singular' => 'Teacher', 'plural' => 'Teachers'],
        'users' => ['singular' => 'User', 'plural' => 'Users'],
        'exception_queue' => 'Exception queue',
        'device_monitor' => 'Device monitor',
        'teacher_attendance' => 'Teacher attendance',
    ],

    // Populated resource-by-resource, page-by-page, as each file is touched.
    'resources' => [],
    'pages' => [],
    'widgets' => [],
    'pdf' => [],

];
