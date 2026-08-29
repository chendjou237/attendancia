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
        'day' => 'Day',
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
    | and getTitle()/getNavigationLabel() on custom pages.
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

    'resources' => [

        'period_slots' => [
            'sequence' => 'Sequence',
            'sequence_help' => 'Order within the day (1, 2, 3…) — determines position in the bell-schedule grid.',
            'seq' => 'Seq',
            'is_break' => 'Break / lunch period',
            'is_break_short' => 'Break',
            'open_ended' => 'Open-ended',
            'current_only' => 'Current version only',
            'valid_from_help' => 'Applies to this date onward. Existing computed results before this date are never rewritten.',
            'valid_to_help' => 'Leave empty if this is the current, open-ended version.',
        ],

        'notices' => [
            'types' => [
                'sick_leave' => 'Sick leave',
                'official_mission' => 'Official mission',
                'bereavement' => 'Bereavement',
                'other' => 'Other',
            ],
            'type' => 'Type',
            'reference' => 'Reference',
            'reference_help' => "Paper reference the officer is recording, e.g. a doctor's note number.",
            'attachment_path' => 'Attachment path',
            'attachment_path_help' => 'No upload handling yet — a path or reference to a scanned copy, if one exists.',
        ],

        'class_codes' => [
            'name_fr' => 'Name (French)',
        ],

        'devices' => [
            'model' => 'Model',
            'model_help' => 'Terminal model, e.g. DS-K1T8005EFX. Card readers on card-capable models are not accepted for attendance — teachers must use a fingerprint.',
            'firmware' => 'Firmware',
            'last_seen_at' => 'Last seen at',
            'last_seen_at_help' => 'Written by the ingestion worker — not editable here.',
            'clock_offset' => 'Clock offset',
            'clock_offset_help' => 'Device/server clock drift, written by the ingestion worker.',
        ],

        'audit_logs' => [
            'entity' => 'Entity',
            'entity_id' => 'Entity ID',
            'action' => 'Action',
            'actor' => 'Actor',
            'system' => 'System',
        ],

        'calendar_days' => [
            'day_type' => 'Day type',
            'half_day_cutoff' => 'Half-day cutoff (last slot that still counts)',
            'slot_option' => ':day, period :period (:start–:end)',
            'scoped_to' => 'Scoped to',
            'suspended_help' => 'Leave empty to suspend the whole school. Select specific classes for a partial suspension (e.g. one form sitting a sequence exam).',
            'whole_school' => 'Whole school',
            'marked_at' => 'Marked at',
        ],

        'teacher_biometric_ids' => [
            'biometric_id' => 'Biometric ID (employeeNoString)',
            'biometric_id_help' => 'The device-side id — confirm the exact field name and value against a real scan first (§13.5).',
            'valid_to_help' => 'Leave empty for an active mapping. Assigning this id to a different teacher automatically closes this one out.',
            'active_placeholder' => 'Active',
        ],

        'rule_versions' => [
            'grace_late' => 'Grace (late)',
            'grace_early' => 'Grace (early)',
            'pair_window_before' => 'Pair window (before)',
            'pair_window_after' => 'Pair window (after)',
            'debounce' => 'Debounce',
            'min_session' => 'Min. session',
            'hours_per_period' => 'Hours/period',
            'valid_from_help' => 'Applies to this date onward. Existing computed results before this date are never rewritten.',
            'note_help' => 'Why this version exists — leadership will ask, and future-you will not remember.',
        ],

        'teachers' => [
            'timetable_title' => 'Timetable — :name',
            'notification_version_created' => 'New timetable version created',
            'notification_timetable_saved' => 'Timetable saved',
            'active_from' => 'Active from',
            'active_to' => 'Active to',
            'timetable_action' => 'Timetable',
            'version' => 'Version',
            'no_versions' => 'No versions yet',
            'new_version_valid_from' => 'New version valid from',
            'new_version_button' => 'New version',
            'copy_current' => 'copy current',
            'save_timetable' => 'Save timetable',
            'no_timetable_version' => 'No timetable version exists yet for this teacher. Create one above.',
        ],

        'users' => [
            'email' => 'Email',
            'password' => 'Password',
            'password_help' => 'Leave blank to keep the current password.',
            'password_confirmation' => 'Confirm password',
            'role' => 'Role',
            'locale_help' => 'Leave blank to follow the site default / their own language switcher choice.',
        ],

        'monthly_reports' => [
            'month' => 'Month',
            'pending_exceptions' => 'Pending exceptions',
            'generated_at' => 'Generated at',
            'not_generated' => 'Not yet generated',
            'sent_to_hr' => 'Sent to HR',
            'generate_action' => 'Generate report',
            'generate_help' => 'Any day within the target month — only the month matters. Generating an existing Draft or Officer-reviewed report refreshes it from the latest data; an already-approved report is untouched.',
            'notification_ready' => 'Report for :month ready',
            'view_title' => 'Monthly report — :month',
            'regenerate_action' => 'Regenerate from latest data',
            'notification_refreshed' => 'Report refreshed from current data',
            'mark_reviewed_action' => 'Mark reviewed',
            'approve_action' => 'Principal approve',
            'approve_modal_description' => 'This freezes the report. Once approved, it can no longer be regenerated — a correction after this point needs a manual, audited adjustment, not a re-run of the generator.',
            'send_to_hr_action' => 'Send to HR',
            'notification_state_updated' => 'Report state updated',
            'not_generated_heading' => 'Not generated yet',
            'not_generated_description' => 'This report has no data yet. Go back to the list and use "Generate report" for this month.',
            'generated_prefix' => 'Generated',
            'pending_heading' => ':count period(s) still pending',
            'pending_description' => 'Unpaired or location-mismatch results included below are not counted as present or absent until the Exception Queue resolves them. Resolve those first, or approve knowing this report undercounts affected teachers.',
            'type' => 'Type',
            'pending' => 'Pending',
            'hours' => 'Hours',
            'no_period_results' => 'No period results for this month.',
            'pdf_title' => 'Monthly Attendance Report',
            'pdf_subtitle_all' => ':month — all teachers',
            'draft_notice' => 'This report is still :state — it has not been approved and these numbers may still change before the month is closed out.',
            'pending_school' => ":count period(s) across the school this month are still unresolved (unpaired or location-mismatch) and are not counted as present or absent below until the Exception Queue resolves them — the affected teachers' totals are undercounted, not wrong.",
            'pending_teacher' => ':count period(s) for this teacher this month are still unresolved (unpaired or location-mismatch) and are not counted as present or absent below until the Exception Queue resolves them.',
            'report_state_prefix' => 'Report state:',
            'total_periods' => 'Total periods',
            'footer_disclaimer' => 'Generated :date for internal use — not a substitute for the official monthly report record.',
        ],

    ],

    'pages' => [

        'exception_queue' => [
            'override_action' => 'Override',
            'actual_status' => 'Actual status',
            'reason_help' => 'Mandatory — this becomes part of the audit trail (§9, §14).',
            'notification_override_recorded' => 'Override recorded',
            'empty_state' => 'No pending exceptions',
        ],

        'device_monitor' => [
            'online' => 'Online',
            'silent' => 'Silent',
            'offline' => 'Offline',
            'no_devices' => 'No devices configured yet — add one under Devices.',
            'simulate_scan_heading' => 'Simulate a scan',
            'simulate_scan_body' => 'No device on site yet, or want to see the feed below move without walking to the corridor? Pick a device and an enrolled teacher and fire one real scan through the same pipeline a live terminal uses.',
            'no_enrolled_teachers' => 'No teacher has a biometric ID enrolled yet — add one under Teacher Biometric IDs first.',
            'select_teacher_placeholder' => 'Select a teacher…',
            'simulate_scan_button' => 'Simulate scan',
            'live_activity' => 'Live activity',
            'updates_every_3s' => 'Updates every 3s',
            'time' => 'Time',
            'result' => 'Result',
            'unmatched' => 'Unmatched',
            'passed' => 'Passed',
            'failed' => 'Failed',
            'other' => 'Other',
            'no_scans_yet' => 'No scans yet — simulate one above, or wait for the real device.',
            'notification_pick_device_teacher' => 'Pick a device and a teacher first',
            'notification_scan_recorded' => 'Scan recorded — :teacher on :device',
            'never_seen' => 'Never seen',
            'last_seen' => 'Last seen __TIME__',
            'ago_seconds' => 's ago',
            'ago_minutes' => 'm ago',
            'ago_hours' => 'h ago',
            'ago_days' => 'd ago',
        ],

        'teacher_attendance' => [
            'date_range' => 'Date range',
            'indicator_from' => 'From :date',
            'indicator_until' => 'Until :date',
            'override_tooltip' => ':status — :reason (:actor, :at)',
            'unknown_actor' => 'Unknown',
            'empty_state' => 'No periods found',
        ],

    ],

    'widgets' => [
        'attendance_overview' => [
            'pending_exceptions' => 'Pending exceptions',
            'pending_exceptions_waiting' => 'Waiting in the exception queue',
            'pending_exceptions_clear' => 'Queue is clear',
            'present_this_month' => 'Present this month',
            'absent_this_month' => 'Absent this month',
            'hours_logged_this_month' => 'Hours logged this month',
            'hours_breakdown' => ':payable payable · :oversight oversight',
            'devices_reporting' => 'Devices reporting',
            'devices_silent' => 'One or more silent for 2h+',
            'devices_all_active' => 'All active devices seen recently',
            'current_report' => "This month's report",
            'current_report_not_generated' => 'Not generated',
            'current_report_description' => 'Click to open Monthly Reports',
        ],
    ],
    'pdf' => [],

];
