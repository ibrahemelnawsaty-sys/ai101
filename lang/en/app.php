<?php

/**
 * Shared platform vocabulary. Mirrors lang/ar/app.php key for key.
 * RiyadhFormatter reads `days`, `months`, `meridiem`, `date_time` and
 * `time_range` from this file.
 *
 * @see PRD §11, §11.1 · CONSTITUTION art. 6, art. 11, art. 15
 */

return [

    'brand_name' => 'Athar Training Centre',
    'tagline' => 'From here the impact begins',

    'brand' => [
        'wordmark_alt' => 'Athar Training Centre logo',
        'mark_alt' => 'Athar mark',
    ],

    'days' => [
        'sunday' => 'Sunday',
        'monday' => 'Monday',
        'tuesday' => 'Tuesday',
        'wednesday' => 'Wednesday',
        'thursday' => 'Thursday',
        'friday' => 'Friday',
        'saturday' => 'Saturday',
    ],

    'months' => [
        'january' => 'January',
        'february' => 'February',
        'march' => 'March',
        'april' => 'April',
        'may' => 'May',
        'june' => 'June',
        'july' => 'July',
        'august' => 'August',
        'september' => 'September',
        'october' => 'October',
        'november' => 'November',
        'december' => 'December',
    ],

    'meridiem' => [
        'am' => 'AM',
        'pm' => 'PM',
    ],

    'date_time' => ':date · :time',
    'time_range' => ':from — :to',

    'riyadh_time_hint' => 'All times are Riyadh time, and the server clock is the reference.',

    'relative' => [
        'now' => 'Just now',
        'minutes' => '{1} a minute ago|[2,*] :count minutes ago',
        'hours' => '{1} an hour ago|[2,*] :count hours ago',
        'days' => '{1} yesterday|[2,*] :count days ago',
        'weeks' => '{1} a week ago|[2,*] :count weeks ago',
        'months' => '{1} a month ago|[2,*] :count months ago',
    ],

    'minutes' => '{1} minute|[2,*] minutes',
    'hours' => '{1} hour|[2,*] hours',

    'save_changes' => 'Save changes',
    'apply_filters' => 'Apply filters',
    'clear_filters' => 'Clear filters',
    'retry' => 'Try again',
    'back' => 'Back',
    'cancel' => 'Cancel',
    'edit' => 'Edit',
    'open' => 'Open',
    'show' => 'Show',
    'details' => 'Details',
    'view_all' => 'View all',
    'all' => 'All',
    'none' => 'None',
    'select' => 'Select',
    'copy' => 'Copy link',
    'copied' => 'Link copied',
    'share' => 'Share',
    'download' => 'Download',
    'export_excel' => 'Export to Excel',
    // `actions` is an array: the layouts read app.actions.skip_to_content and
    // app.actions.close, while a table's hidden action column reads
    // app.actions.label. The flat keys app.skip_to_content and app.close are
    // kept below so nothing that already reads them breaks.
    'actions' => [
        'label' => 'Actions',
        'skip_to_content' => 'Skip to content',
        'close' => 'Close',
    ],
    'status' => 'Status',
    'not_assigned' => 'Not assigned yet',
    'optional' => 'Optional',
    'skip_to_content' => 'Skip to content',

    'accessibility' => [
        'menu' => 'Menu',
        'open_menu' => 'Open the menu',
        'close_menu' => 'Close the menu',
        'user_menu' => 'Account menu',
        'notifications_bell' => 'Notifications',
        'progress' => 'Progress indicator',
        'loading_region' => 'Content loading',
        'status_region' => 'Status messages',
    ],
    'close' => 'Close',
    'delete' => 'Delete',
    'archive' => 'Archive',
    'view_details' => 'View details',
    'search_placeholder' => 'Search…',
    'no_search_results_title' => 'Nothing matches your search',
    'no_search_results' => 'Try another word, or clear the filters to see everything.',

    'common' => [
        'search_placeholder' => 'Search…',
    ],

    'ratio' => [
        'of' => ':count of :total',
        'percent' => ':value%',
        'score_of' => ':score of :max',
    ],

    'size' => [
        'kilobytes' => ':value KB',
        'megabytes' => ':value MB',
    ],

    'counts' => [
        'sessions' => '{0} no sessions|{1} one session|[2,*] :count sessions',
        'submissions' => '{0} no submissions|{1} one submission|[2,*] :count submissions',
        'participants' => '{0} no participants|{1} one participant|[2,*] :count participants',
        'seats' => '{0} no seats left|{1} one seat left|[2,*] :count seats left',
    ],

    'time' => [
        'now' => 'Now',
        'from' => 'From',
        'to' => 'To',
        'days_label' => 'days',
        'hours_label' => 'hours',
        'minutes_label' => 'minutes',
        'seconds_label' => 'seconds',
        'server_time_note' => 'The countdown runs on the server clock in Riyadh time, not on the clock of your device.',
    ],

    'states' => [
        'loading' => 'Loading…',
        'empty_title' => 'Nothing here yet',
        'empty_body' => 'Content will appear here as soon as it is available.',
        'error_title' => 'This section could not be shown',
        'error_body' => 'Something unexpected happened. Our team is on it. Try again shortly.',
    ],

    'saved' => 'Your changes have been saved.',
    'delete_confirm' => 'Delete this item? This action cannot be undone.',
    'leave_unsaved' => 'You have unsaved changes. Leave the page anyway?',

    'remaining' => [
        'none' => 'No deadline',
        'passed' => 'Deadline passed',
        'now' => 'Less than a minute left',
        'minutes' => '{1} 1 minute left|[2,*] :count minutes left',
        'hours' => '{1} 1 hour left|[2,*] :count hours left',
        'days' => '{1} 1 day left|[2,*] :count days left',
    ],

    'file_size' => [
        'bytes' => ':size bytes',
        'kb' => ':size KB',
        'mb' => ':size MB',
        'gb' => ':size GB',
    ],

    'unknown' => 'Unknown',

];
