<?php

/*
|--------------------------------------------------------------------------
| Athar platform constants
|--------------------------------------------------------------------------
|
| PROJECT-CONTRACT.md §1: every identity value below is read from here, and
| here reads it from the environment. Nothing in this table may be written
| into a controller, a model or a Blade file (BR-36).
|
| The Arabic-bearing entries carry no default on purpose. Arabic text inside a
| .php file is forbidden platform-wide (Constitution art. 13, rule 3, enforced
| by gate G5), so the values live in .env / .env.example instead. A fresh
| checkout that copies .env.example gets them; a deployment that forgets them
| gets an empty string, which is visible immediately rather than silently
| wrong.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Identity
    |--------------------------------------------------------------------------
    */

    'program_name' => env('ATHAR_PROGRAM_NAME', ''),

    'program_short_name' => env('ATHAR_PROGRAM_SHORT_NAME', 'AI 101'),

    /*
    | The programme code that appears inside a certificate serial number
    | (ATHAR-AI101-2026-0001). Letters and digits only - no spaces, because the
    | serial is parsed on the hyphens (PRD §9.17, BR-36).
    */

    'program' => [
        'code' => env('ATHAR_PROGRAM_CODE', 'AI101'),

        /*
         | Whether the programme is free of charge.
         |
         | Defaults to FALSE, and deliberately so: the Schema.org graph published
         | with every public page carries `isAccessibleForFree`, and a wrong
         | `true` there tells every search engine that a programme the centre
         | sells costs nothing. That was live. A default of false means the worst
         | an unset variable can do is understate a discount, never advertise a
         | paid programme as free.
         */
        'is_free' => (bool) env('ATHAR_PROGRAM_IS_FREE', false),

        /*
         | Whether the programme is delivered entirely remotely. Drives the hero
         | badge; a centre that moves into a room turns it off and the badge goes
         | with it, rather than a sentence being edited out of a template.
         */
        'is_remote' => (bool) env('ATHAR_PROGRAM_IS_REMOTE', true),
    ],

    'platform_name' => env('ATHAR_PLATFORM_NAME', ''),

    'tagline' => env('ATHAR_TAGLINE', ''),

    /*
    |--------------------------------------------------------------------------
    | Contact and domains
    |--------------------------------------------------------------------------
    |
    | `email` is the address shown to visitors. The address the platform sends
    | FROM is config('mail.from.address') and they are not the same thing while
    | the reply-to question is open - see the note in config/mail.php.
    |
    */

    'domain' => env('ATHAR_DOMAIN', 'ai.wareed.vip'),

    'center_domain' => env('ATHAR_CENTER_DOMAIN', 'athar-dev.edu.sa'),

    'email' => env('ATHAR_EMAIL', 'contact@athar-dev.edu.sa'),

    // Digits only, international format, no '+' and no separators: the value
    // is pasted straight into a wa.me link.
    'whatsapp' => env('ATHAR_WHATSAPP', '966582090332'),

    /*
    |--------------------------------------------------------------------------
    | Time
    |--------------------------------------------------------------------------
    |
    | Storage is UTC and display is Asia/Riyadh. These two entries exist so
    | that scheduled tasks and report headers can name the display zone; the
    | authority on both remains App\Services\Time\Clock (art. 11, BR-07).
    |
    */

    'display_timezone' => env('ATHAR_DISPLAY_TIMEZONE', 'Asia/Riyadh'),

    'storage_timezone' => 'UTC',

    /*
    |--------------------------------------------------------------------------
    | Locales
    |--------------------------------------------------------------------------
    |
    | Read by App\Http\Middleware\SetLocaleAndDirection. Arabic is the
    | reference and the fallback; the English tree exists but is not complete,
    | so `supported` is what decides whether a locale may be selected at all.
    |
    */

    'locales' => [
        'default' => 'ar',
        'supported' => ['ar'],
        'rtl' => ['ar'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Attendance
    |--------------------------------------------------------------------------
    |
    | Which statuses count towards the attendance rate. Deliberately left null:
    | App\Support\AttendanceCounting carries the documented default and this
    | key exists only so an approved decision can override it in one place,
    | without editing code (BR-08, BR-09, BR-26).
    |
    */

    'attendance' => [
        'counted_as_attended' => null,

        /*
        | How many days back `attendance:reconcile` revisits on each run. The
        | job runs every fifteen minutes, so anything older than a couple of
        | days has already been settled; the bound is what keeps one pass
        | inside a single PHP execution slot on shared hosting (art. 10).
        */
        'reconcile_lookback_days' => 3,

        /*
        | How often the trainer's live roster asks for fresh cells while a
        | session is running (PRD §9.9.7). It stops by itself when the session
        | ends, and a hidden tab does not ask. Zero switches it off.
        */
        'roster_poll_seconds' => (int) env('ROSTER_POLL_SECONDS', 20),
    ],

    /*
    |--------------------------------------------------------------------------
    | Messages (PRD §9.13.2)
    |--------------------------------------------------------------------------
    |
    | How often an open conversation asks for new messages. A hidden tab does
    | not ask at all. Fifteen seconds is four requests a minute per open
    | thread — sized for shared hosting, where every request is a PHP process.
    | Zero switches live updates off; the page then refreshes on reload only.
    |
    */

    'messages' => [
        'poll_seconds' => (int) env('MESSAGES_POLL_SECONDS', 15),
    ],

    /*
    |--------------------------------------------------------------------------
    | Assignment reminders (PRD §9.11.3)
    |--------------------------------------------------------------------------
    |
    | How long after one "remind who has not submitted" the button refuses the
    | next. It exists so a double click cannot write to the cohort twice; the
    | length is a temporary assumption awaiting the owner's sign-off (D-68).
    |
    */

    'assignments' => [
        'reminder_cooldown_minutes' => (int) env('ASSIGNMENT_REMINDER_COOLDOWN_MINUTES', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Uploads
    |--------------------------------------------------------------------------
    |
    | The disk lives outside the web root and the real type is sniffed from the
    | file's own bytes by App\Services\Storage\PrivateFileService; the values
    | here only bound the size and name the disk (PRD §9.11.2, §12.5).
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Invitations (D-63)
    |--------------------------------------------------------------------------
    |
    | An account created for someone by an administrator signs in once with a
    | temporary password and must replace it immediately.
    |
    | `temp_password_days` is why the temporary password is temporary: without
    | an expiry it is a permanent password sitting in an inbox, and inboxes are
    | forwarded, shared and breached. Seven days is long enough for a trainee
    | who is travelling and short enough that a forgotten invitation stops being
    | a live credential. Nobody is stranded when it lapses -- password recovery
    | works, and the letter says so.
    |
    | `import_max_rows` bounds one upload. Every row costs a bcrypt hash inside
    | a single web request, and shared hosting will cut the request off rather
    | than let it run long; the limit is what fits with room to spare, not what
    | the machine can manage on a quiet day.
    |
    */
    'invitations' => [
        'temp_password_days' => (int) env('ATHAR_INVITE_TEMP_PASSWORD_DAYS', 7),
        'import_max_rows' => (int) env('ATHAR_INVITE_IMPORT_MAX_ROWS', 200),
    ],

    'uploads' => [
        'disk' => env('ATHAR_UPLOAD_DISK', 'private'),
        'max_kilobytes' => 25600,
        'max_files' => 5,
        'allowed_extensions' => [
            'pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx',
            'txt', 'md', 'csv', 'zip', 'png', 'jpg', 'jpeg', 'webp',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Route names referenced from services
    |--------------------------------------------------------------------------
    |
    | A service that needs to link somewhere reads the route NAME from here and
    | checks Route::has() before building the link, so a job never dies because
    | a route has not been declared yet.
    |
    | The value MUST match a name declared in routes/web.php. The trainer area
    | has no per-session screen: the sessions board is `trainer.sessions` and it
    | selects a session from the query string. Naming a route that does not
    | exist made Route::has() false and the BR-09 notification link null every
    | time, silently.
    |
    */

    'routes' => [
        /*
         * Where an incomplete-attendance notice (BR-09) sends the trainer.
         *
         * `trainer.attendance`, not `trainer.sessions`. The notice carries
         * ?session=<id>, and SessionController never reads that key — it
         * renders a paginated board of up to fifty sessions with nothing saying
         * which one the notice was about. AttendanceController does read it
         * (line 361). D-62 replaced a route name that did not exist with one
         * that exists and discards the identifier, so Route::has() passed and
         * nothing errored (D-65).
         */
        'trainer_session' => 'trainer.attendance',
    ],

    /*
    |--------------------------------------------------------------------------
    | Account preview (impersonation)
    |--------------------------------------------------------------------------
    |
    | The strongest permission on the platform. Thirty minutes, then the
    | preview ends by itself (BR-33, BR-34, BR-35 - PRD §4.5.2).
    |
    */

    'impersonation' => [
        'actor_session_key' => 'athar.impersonator_id',
        'max_minutes' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Security
    |--------------------------------------------------------------------------
    */

    'security' => [
        // Extra Content-Security-Policy directives appended by
        // App\Http\Middleware\SecurityHeaders. Empty by design: everything the
        // platform needs is self-hosted, fonts included (PRD §13.1).
        'csp_extra' => [],

        // Temporary signed URLs for private downloads (PRD §12.5).
        'signed_url_ttl_minutes' => 15,
    ],

    /*
    |--------------------------------------------------------------------------
    | Certificates
    |--------------------------------------------------------------------------
    |
    | Serial format ATHAR-AI101-2026-0001 (PRD §9.17): prefix, four-digit year,
    | then a zero-padded counter within that year.
    |
    */

    'certificate' => [
        'serial_prefix' => env('ATHAR_CERTIFICATE_PREFIX', 'ATHAR-AI101'),
        'serial_sequence_length' => 4,
    ],

];
