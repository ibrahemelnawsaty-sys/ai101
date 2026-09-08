<?php

/**
 * The training schedule. Mirrors lang/ar/schedule.php key for key.
 *
 * @see PRD §9.8 · CONSTITUTION art. 11
 */

return [

    'subtitle' => 'The weeks of the programme and their sessions, in Riyadh time',

    'view_switch' => 'Choose how to view the schedule',
    'view_accordion' => 'Grouped by week',
    'view_calendar' => 'Calendar view',

    'filter_week' => 'Week',
    'filter_type' => 'Session type',
    'filter_attendance' => 'Attendance status',

    'export_ics' => 'Export the schedule to your calendar',
    'export_pdf' => 'Download the schedule as PDF',
    'add_to_calendar' => 'Add to my calendar',

    'session_count' => '{0} no sessions|{1} one session|[2,*] :count sessions',
    'current_week' => 'Current week',
    'print_title' => 'Programme timetable',
    'printed_at' => 'Printed :at, Riyadh time',
    'table_caption' => 'Sessions of :week',
    'unscheduled_group' => 'Sessions outside the weeks',
    'session_details' => 'Session details',

    'col_date' => 'Day and date',
    'col_time' => 'Time',
    'col_title' => 'Title',
    'col_topic' => 'Topic',
    'col_trainer' => 'Trainer',
    'col_status' => 'Status',

    'cancelled_reason' => 'Reason for cancelling: :reason',
    'replacement_at' => 'Rescheduled to :when',

    'previous_week' => 'Previous week',
    'next_week' => 'Next week',
    'today' => 'Today',
    'no_sessions_that_day' => 'No sessions on this day',

    'timezone_note' => 'Every time shown is Riyadh time (Asia/Riyadh), and the server clock is the reference.',

    'empty_title' => 'The schedule has not been published yet',
    'empty_body' => 'It appears here as soon as the programme team approves it, and you are notified.',
    'week_empty_title' => 'No sessions this week',
    'week_empty_body' => 'No sessions have been added to this week yet.',
    'calendar_empty_title' => 'No sessions this week',
    'calendar_empty_body' => 'Move to another week with the arrows, or return to the current week.',
    'error_title' => 'The schedule could not be shown',
    'error_body' => 'Something went wrong while fetching it. Try again shortly — your dates are safe and unaffected.',

];
