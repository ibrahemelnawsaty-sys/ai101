<?php

/**
 * The "Roles and permissions" page (D-133) and the confirmation before a role
 * changes. Who may do what is read from the router (App\Support\RoleCapabilities);
 * this file holds the words.
 *
 * @see D-133, D-117 · PRD §4
 */

return [

    'title' => 'Roles and permissions',
    'subtitle' => 'What each role can do on the platform — read only.',
    'how_to_change' => 'An account\'s role is changed from the account\'s own screen, where a written reason is required and the change is recorded in the audit log.',
    'roles_title' => 'Roles',
    'scope_label' => 'Reach',
    'matrix_title' => 'Permission matrix',
    'matrix_intro' => '"Yes" means the role reaches this screen or action. What it sees there is set by the reach written on its card, and the server decides on every request; this page explains, it is not the source of a permission. The columns are the role on the account: someone who is a trainer in one cohort and a trainee in another holds both roles\' abilities, each in its own cohort.',

    'matrix' => [
        'capability' => 'Permission',
        'yes' => 'Yes',
        'no' => 'No',
    ],

    'roles' => [
        'participant' => [
            'summary' => 'Follows their programme: schedule, attendance, assignments, final project, grades and certificate; talks to their team and asks for technical support.',
            'scope' => 'Their own data only; nothing that belongs to another trainee.',
        ],
        'trainer' => [
            'summary' => 'Trains their cohort: records attendance, grades submissions, uploads training-kit material, and reads the sessions and assignments.',
            'scope' => 'The cohorts assigned to them only.',
        ],
        'coordinator' => [
            'summary' => 'Organises their cohort: writes the session schedule, its links and locations, records attendance, decides excuse requests and handles the technical-support tickets that reach them.',
            'scope' => 'The cohorts assigned to them only.',
        ],
        'admin' => [
            'summary' => 'Runs the programme: programmes, cohorts, registration requests, certificates, the final project, reports and the audit log; defines cohort assignments and writes to trainees.',
            'scope' => 'Every cohort.',
        ],
        'system_admin' => [
            'summary' => 'Runs the platform itself: accounts, their roles and previews, the landing-page content and the platform settings.',
            'scope' => 'No cohort; the accounts and the platform only.',
        ],
    ],

    'areas' => [
        'learn' => 'Following the programme',
        'run' => 'Running a cohort',
        'grade' => 'Work and grades',
        'program' => 'Running the programme',
        'system' => 'The platform and accounts',
        'contact' => 'Contact and support',
    ],

    'notes' => [
        'grade_submissions' => 'A supervisor seated as a cohort\'s trainer records them too, in that cohort only.',
        'revise_grades' => 'A supervisor seated as a cohort\'s trainer revises them too, in that cohort only.',
    ],

    'capabilities' => [
        'view_schedule' => 'Reading the session schedule',
        'live_sessions' => 'Live sessions',
        'check_in_self' => 'Checking themselves in and out',
        'submit_assignments' => 'Handing in assignments',
        'final_project' => 'The final project and handing it in',
        'see_grades' => 'Reading their own grades',
        'own_certificate' => 'Their own certificate',
        'own_card' => 'Their digital card',
        'training_kit' => 'The training kit',
        'take_attendance' => 'Recording attendance and correcting it by hand with a written reason',
        'checkin_code' => 'The session self-check-in code',
        'decide_excuses' => 'Deciding absence and lateness excuse requests',
        'read_sessions' => 'Reading the sessions and their join links',
        'write_schedule' => 'Creating, editing and cancelling sessions',
        'cohort_trainees' => 'The cohort\'s trainees and their files',
        'upload_kit' => 'Uploading training-kit material',
        'read_assignments' => 'Reading the cohort\'s assignments',
        'define_assignments' => 'Defining the weekly assignments and their terms',
        'read_submissions' => 'Reading and downloading submissions',
        'grade_submissions' => 'Recording marks and writing feedback',
        'revise_grades' => 'Revising a mark after it was recorded, with a written reason',
        'cohort_reports' => 'Cohort reports',
        'manage_programs' => 'Managing programmes',
        'manage_cohorts' => 'Managing cohorts, their capacity and their trainers and coordinators',
        'registrations' => 'Registration requests',
        'certificates_screen' => 'The certificates screen',
        'final_project_settings' => 'Final-project settings and opening it',
        'broadcasts' => 'Bulk messages to trainees',
        'platform_reports' => 'Platform reports',
        'audit_log' => 'The audit log',
        'manage_accounts' => 'Accounts: create, edit and suspend',
        'change_roles' => 'Changing any account\'s role',
        'preview_accounts' => 'Previewing an account as its owner sees it',
        'landing_page' => 'The landing-page content',
        'platform_settings' => 'The platform settings and e-mail templates',
        'roles_page' => 'This roles and permissions page',
        'internal_messaging' => 'Internal messaging',
        'support_area' => 'Technical support: the ticket list',
        'open_ticket' => 'Opening a technical-support ticket',
        'handle_tickets' => 'Finishing the handling of a technical-support ticket',
    ],

    'change' => [
        'title' => 'Confirm the role change',
        'description' => 'The change applies at once and is recorded in the audit log with the reason you wrote.',
        'current' => 'Current role',
        'new' => 'New role',
        'meaning' => 'What the new role can do',
        'unchanged' => 'You have not chosen a new role.',
        'confirm' => 'Confirm the role change',
    ],

];
