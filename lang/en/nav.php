<?php

/**
 * Sidebar and app-shell navigation labels. Mirrors lang/ar/nav.php.
 *
 * @see PRD §9.5.1, §9.5.2 · CONSTITUTION art. 16
 */

return [

    'sidebar_label' => 'Navigate your dashboard',
    'toggle_sidebar' => 'Collapse or expand the sidebar',
    'close_menu' => 'Close the menu',
    'badge_hint' => 'items waiting for you',

    /*
    | Breadcrumb labels. The error pages build a link list for a signed-out
    | visitor and need a name for the public home page (PRD §8).
    */

    'breadcrumb' => [
        'home' => 'Home page',
    ],

    'badges' => [
        'unread_messages' => 'unread messages',
        'unread_notifications' => 'unread notifications',
    ],

    'groups' => [
        'overview' => 'Overview',
        'program' => 'Programme',
        'work' => 'Work and grades',
        'communication' => 'Communication',
        'trainer' => 'Trainer tools',
        'admin' => 'Platform administration',
    ],

    'dashboard' => 'Home',
    'card' => 'Digital card',
    'journey' => 'My journey',
    'schedule' => 'Programme schedule',
    'attendance' => 'Attendance',
    'live' => 'Live sessions',
    'assignments' => 'Assignments',
    'resources' => 'Training pack',
    'final_project' => 'Final project',
    'grades' => 'Grades',
    'messages' => 'Messages',
    'certificate' => 'Certificate',
    'notifications' => 'Notifications',
    'profile' => 'My account',

    'admin' => [
        'dashboard' => 'Console',
        'programs' => 'Programmes',
        'cohorts' => 'Cohorts',
        'landing' => 'Landing page',
        'users' => 'Users',
        'registrations' => 'Registrations',
        'certificates' => 'Certificates',
        'reports' => 'Reports',
        'audit' => 'Audit log',
        'settings' => 'Platform settings',
    ],

    'trainer' => [
        'participants' => 'Cohort participants',
        'sessions' => 'Sessions',
        'attendance' => 'Attendance',
        'resources' => 'Resources',
        'assignments' => 'Assignments',
        'submissions' => 'Submissions and grading',
        'final_project' => 'Final project',
        'reports' => 'Cohort reports',
    ],

    'participant' => [
        'dashboard' => 'Home',
        'card' => 'Digital card',
        'journey' => 'My journey',
        'schedule' => 'Programme schedule',
        'attendance' => 'Attendance',
        'live' => 'Live sessions',
        'assignments' => 'Assignments',
        'resources' => 'Training pack',
        'final_project' => 'Final project',
        'grades' => 'Grades',
        'messages' => 'Messages',
        'certificate' => 'Certificate',
        'notifications' => 'Notifications',
        'profile' => 'My account',
    ],

    'current_cohort' => 'Your current cohort',
    'switch_cohort' => 'Switch to another cohort',

    'empty_title' => 'No sections available yet',
    'empty_body' => 'Your dashboard sections appear once you are enrolled in a cohort. Contact us if they are slow to appear.',

    /*
    | The app-shell chrome, addressed on its own by the layout components.
    */

    'chrome' => [
        'sidebar_label' => 'Sidebar',
        'toggle_sidebar' => 'Collapse or expand the sidebar',
        'open_menu' => 'Open the navigation menu',
        'close_menu' => 'Close the navigation menu',
        'badge_hint' => 'items that need your attention',
        'current_cohort' => 'Current cohort',
        'switch_cohort' => 'Switch cohort',
        'empty_title' => 'No sections available yet',
        'empty_body' => 'Your dashboard sections appear here once you are enrolled in a cohort.',
        'account' => 'My account',
        'logout' => 'Sign out',
        'footer_note' => 'All times are Riyadh time.',
    ],

];
