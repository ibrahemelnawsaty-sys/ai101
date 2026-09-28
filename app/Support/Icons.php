<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The map from a screen CONCEPT to a sprite id — the one place that says which
 * drawing means what (D-127, PROJECT-CONTRACT §17).
 *
 * A concept keeps one glyph everywhere it appears: a rail item, a status pill,
 * a button. Before this map the platform drew "error" and "warning" with the
 * same triangle, "dashboard" and "collapse the rail" with the same panel, the
 * settings item with a padlock, and "attach", "upload" and "escalate" with the
 * same arrow — a glyph whose meaning changed with the screen is a glyph nobody
 * can learn.
 *
 * Values are BARE ids ("attendance", not "i-attendance"): <x-ui.icon> alone
 * adds the prefix (§17). The sprite (partials/icon-sprite.blade.php) draws
 * them; IconSpriteContractTest fails if a constant here names an id it does
 * not define, or if two concepts share one drawing by accident.
 *
 * @see PROJECT-CONTRACT §17 · PRD §5.7 · CONSTITUTION Articles 6, 18
 */
final class Icons
{
    // ---- places: what a rail item opens ---------------------------------
    public const HOME = 'home';

    public const DASHBOARD = 'dashboard';

    public const DIGITAL_CARD = 'card';

    public const JOURNEY = 'route';

    public const SCHEDULE = 'cal';

    /** Attendance, whoever looks: the participant's own record and the staff roster. */
    public const ATTENDANCE = 'attendance';

    public const LIVE = 'live';

    public const RECORDING = 'recording';

    public const ASSIGNMENTS = 'file';

    public const SUBMISSIONS = 'submissions';

    public const FINAL_PROJECT = 'presentation';

    public const GRADES_AND_REPORTS = 'chart';

    public const CERTIFICATE = 'badge';

    public const RESOURCES = 'folder';

    public const MESSAGES = 'chat';

    public const SUPPORT = 'help';

    public const PROGRAMS = 'library';

    public const COHORTS = 'cohort';

    public const REGISTRATIONS = 'user-plus';

    public const BROADCASTS = 'broadcast';

    public const AUDIT = 'shield';

    public const USERS = 'users';

    public const LANDING = 'globe';

    public const SETTINGS = 'settings';

    // ---- chrome ----------------------------------------------------------
    public const SIDEBAR_COLLAPSE = 'sidebar-collapse';

    public const MENU = 'menu';

    public const CLOSE = 'x';

    public const SEARCH = 'search';

    public const FILTER = 'filter';

    public const LOGIN = 'login';

    public const LOGOUT = 'logout';

    public const NOTIFICATIONS = 'bell';

    // ---- state: what a pill, toast or empty state says --------------------
    public const SUCCESS = 'check';

    public const WARNING = 'warn';

    public const ERROR = 'error';

    public const INFO = 'info';

    public const LOCKED = 'lock';

    public const UNLOCKED = 'unlock';

    public const PENDING = 'clock';

    // ---- actions: what a button does -------------------------------------
    public const ADD = 'plus';

    public const EDIT = 'pencil';

    public const MORE = 'more';

    public const DELETE = 'trash';

    public const UNDO = 'undo';

    public const REFRESH = 'refresh';

    public const COPY = 'copy';

    public const SHARE = 'share';

    public const PRINT = 'printer';

    public const OPEN_EXTERNAL = 'external';

    public const UPLOAD = 'upload';

    public const DOWNLOAD = 'download';

    public const SEND = 'send';

    public const ATTACH = 'attach';

    public const ESCALATE = 'escalate';

    public const ARCHIVE = 'archive';

    public const PREVIEW = 'eye';

    /**
     * Every concept id, in declaration order, for the contract test and for a
     * caller that needs to validate a value it did not write.
     *
     * @return array<string, string> constant name => bare sprite id
     */
    public static function all(): array
    {
        /** @var array<string, string> $constants */
        $constants = (new \ReflectionClass(self::class))->getConstants();

        return $constants;
    }
}
