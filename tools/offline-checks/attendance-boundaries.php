<?php

/**
 * Executes the real AttendanceWindow against the full boundary table from
 * PROJECT-CONTRACT §6 / PRD §9.9.3, at ±1 second around every edge.
 *
 * The full Laravel autoloader is unavailable here, so the few classes involved
 * are required directly and the Session is a stand-in exposing getAttribute().
 * The logic under test is the project's own file, unmodified.
 */

declare(strict_types=1);

namespace {
    $root = 'c:/Users/b.maher/Downloads/wesal/LARAVEL';
    spl_autoload_register(static function (string $class) use ($root): void {
        if (str_starts_with($class, 'Carbon\\')) {
            $p = $root.'/vendor/nesbot/carbon/src/'.str_replace('\\', '/', $class).'.php';
            if (is_file($p)) {
                require $p;
            }

            return;
        }
        if (str_starts_with($class, 'App\\')) {
            $p = $root.'/app/'.str_replace('\\', '/', substr($class, 4)).'.php';
            if (is_file($p)) {
                require $p;
            }
        }
    });
}

/** Interfaces Carbon type-hints against; not installed, and not exercised here. */

namespace Psr\Clock {
    if (! interface_exists('Psr\Clock\ClockInterface')) {
        interface ClockInterface
        {
            public function now(): \DateTimeImmutable;
        }
    }
}

namespace Symfony\Component\Clock {
    if (! interface_exists('Symfony\Component\Clock\ClockInterface')) {
        interface ClockInterface extends \Psr\Clock\ClockInterface
        {
            public function sleep(float|int $seconds): void;

            public function withTimeZone(\DateTimeZone|string $timezone): static;

            public function now(): \DateTimeImmutable;
        }
    }
}

/**
 * A stand-in declared under the real name, so the class under test keeps its
 * strict App\Models\Session type hint and is not modified for the harness.
 * AttendanceWindow only ever calls getAttribute() on it.
 */

namespace App\Models {
    class Session
    {
        /** @param array<string,mixed> $a */
        public function __construct(private array $a = []) {}

        public function getAttribute(string $k): mixed
        {
            return $this->a[$k] ?? null;
        }
    }
}

namespace {

    use App\Services\Attendance\AttendanceWindow;
    use Carbon\CarbonImmutable;

    // A session on Sunday 18 October 2026, 19:00–21:30 Riyadh (the seeded timetable).
    $session = new App\Models\Session([
        'date' => '2026-10-18',
        'start_time' => '19:00:00',
        'end_time' => '21:30:00',
        'status' => 'scheduled',
    ]);

    $w = new AttendanceWindow;
    $S = $w->startsAt($session);     // 16:00 UTC
    $E = $w->endsAt($session);       // 18:30 UTC

    $at = static fn (CarbonImmutable $base, int $sec): CarbonImmutable => $base->addSeconds($sec);

    /** [label, instant, expected canCheckIn, expected classify|null, expected canCheckOut] */
    $cases = [
        ['S-30m-1s', $at($S, -1801), false, null,      false],
        ['S-30m',    $at($S, -1800), true,  'present', false],
        ['S-30m+1s', $at($S, -1799), true,  'present', false],
        ['S-1s',     $at($S, -1),    true,  'present', false],
        ['S',        $at($S, 0),     true,  'present', false],
        ['S+30m-1s', $at($S, 1799),  true,  'present', false],
        ['S+30m',    $at($S, 1800),  true,  'present', false],
        ['S+30m+1s', $at($S, 1801),  true,  'late',    false],
        ['E-30m-1s', $at($E, -1801), true,  'late',    false],
        ['E-30m',    $at($E, -1800), true,  'late',    true],
        ['E-1s',     $at($E, -1),    true,  'late',    true],
        ['E',        $at($E, 0),     true,  'late',    true],
        ['E+1s',     $at($E, 1),     false, null,      true],
        ['E+30m-1s', $at($E, 1799),  false, null,      true],
        ['E+30m',    $at($E, 1800),  false, null,      true],
        ['E+30m+1s', $at($E, 1801),  false, null,      false],
    ];

    $pass = 0;
    $fail = 0;
    printf("session S=%s  E=%s (UTC)\n", $S->format('Y-m-d H:i:s'), $E->format('Y-m-d H:i:s'));
    printf("%-11s | %-14s | %-16s | %s\n", 'moment', 'check-in', 'classify', 'check-out');
    echo str_repeat('-', 66), "\n";

    foreach ($cases as [$label, $t, $expIn, $expCls, $expOut]) {
        $gotIn = $w->canCheckIn($session, $t);
        $gotOut = $w->canCheckOut($session, $t);
        $gotCls = $gotIn ? $w->classify($session, $t)->value : null;

        $okIn = $gotIn === $expIn;
        $okOut = $gotOut === $expOut;
        $okCls = $expCls === null ? true : $gotCls === $expCls;
        $ok = $okIn && $okOut && $okCls;
        $ok ? $pass++ : $fail++;

        printf(
            "%-11s | %-14s | %-16s | %-9s %s\n",
            $label,
            ($gotIn ? 'open' : 'closed').($okIn ? '' : ' WRONG'),
            ($gotCls ?? '—').($okCls ? '' : ' WRONG(exp '.$expCls.')'),
            ($gotOut ? 'open' : 'closed').($okOut ? '' : ' WRONG'),
            $ok ? 'ok' : '<< FAIL',
        );
    }

    // BR-01/BR-04: a cancelled session refuses both, at every instant.
    $cancelled = new App\Models\Session([
        'date' => '2026-10-18', 'start_time' => '19:00:00',
        'end_time' => '21:30:00', 'status' => 'cancelled',
    ]);
    $mid = $at($S, 600);
    $cIn = $w->canCheckIn($cancelled, $mid);
    $cOut = $w->canCheckOut($cancelled, $at($E, -600));
    $cancelOk = $cIn === false && $cOut === false;
    $cancelOk ? $pass++ : $fail++;
    echo str_repeat('-', 66), "\n";
    printf("cancelled session refuses check-in and check-out: %s\n", $cancelOk ? 'ok' : '<< FAIL');

    echo "\nRESULT: {$pass} passed, {$fail} failed\n";
    exit($fail === 0 ? 0 : 1);
}
