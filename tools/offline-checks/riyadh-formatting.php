<?php

/**
 * Executes the real RiyadhFormatter against CONSTITUTION art. 15:
 * Arabic wording, Latin digits, 12-hour clock with صباحًا / مساءً,
 * and Asia/Riyadh display for UTC-stored instants.
 *
 * `__()` is stubbed by reading the project's own lang/ar files, so the Arabic
 * strings under test are the real ones, not fixtures.
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

    $GLOBALS['__lang'] = [];
    foreach (glob($root.'/lang/ar/*.php') ?: [] as $f) {
        $GLOBALS['__lang'][basename($f, '.php')] = require $f;
    }

    /** Minimal translator over the project's real lang/ar arrays. */
    function __(string $key, array $replace = []): string
    {
        $parts = explode('.', $key);
        $node = $GLOBALS['__lang'];
        foreach ($parts as $p) {
            if (! is_array($node) || ! array_key_exists($p, $node)) {
                return $key;
            }
            $node = $node[$p];
        }
        if (! is_string($node)) {
            return $key;
        }
        foreach ($replace as $k => $v) {
            $node = str_replace(':'.$k, (string) $v, $node);
        }

        return $node;
    }
}

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

namespace {

    use App\Services\Time\Clock;
    use App\Services\Time\RiyadhFormatter;

    $f = new RiyadhFormatter;
    $pass = 0;
    $fail = 0;

    $check = static function (string $what, string $got, string $expect) use (&$pass, &$fail): void {
        $ok = $got === $expect;
        $ok ? $pass++ : $fail++;
        printf("%-34s %-34s %s\n", $what, $got, $ok ? 'ok' : '<< FAIL expected: '.$expect);
    };

    // Sunday 18 October 2026, 19:00 Riyadh == 16:00 UTC.
    $eveningUtc = Clock::composeRiyadh('2026-10-18', '19:00:00');
    $morningUtc = Clock::composeRiyadh('2026-10-18', '09:05:00');
    $lateUtc = Clock::composeRiyadh('2026-10-18', '21:30:00');
    $noonUtc = Clock::composeRiyadh('2026-10-18', '12:00:00');
    $midnight = Clock::composeRiyadh('2026-10-18', '00:00:00');

    echo 'stored UTC for 19:00 Riyadh : '.$eveningUtc->format('Y-m-d H:i:s')."\n\n";
    printf("%-34s %-34s %s\n", 'case', 'output', 'verdict');
    echo str_repeat('-', 90), "\n";

    $check('date (Sunday)', $f->date($eveningUtc), 'الأحد 18 أكتوبر 2026');
    $check('time 19:00 -> evening', $f->time($eveningUtc), '7:00 مساءً');
    $check('time 09:05 -> morning', $f->time($morningUtc), '9:05 صباحًا');
    $check('time 21:30 -> evening', $f->time($lateUtc), '9:30 مساءً');
    $check('time 12:00 -> noon', $f->time($noonUtc), '12:00 مساءً');
    $check('time 00:00 -> midnight', $f->time($midnight), '12:00 صباحًا');
    $check('timeRange', $f->timeRange($eveningUtc, $lateUtc), '7:00 مساءً — 9:30 مساءً');

    // Article 15: Latin digits only, never Arabic-Indic.
    $all = $f->date($eveningUtc).$f->time($eveningUtc).$f->dateTime($eveningUtc);
    $hasArabicIndic = preg_match('/[\x{0660}-\x{0669}\x{06F0}-\x{06F9}]/u', $all) === 1;
    $hasArabicIndic ? $fail++ : $pass++;
    printf("%-34s %-34s %s\n", 'digits are Latin only', $hasArabicIndic ? 'Arabic-Indic found' : 'Latin', $hasArabicIndic ? '<< FAIL' : 'ok');

    // No raw lang key leaked through (a missing key returns the key itself).
    $leak = str_contains($all, 'app.');
    $leak ? $fail++ : $pass++;
    printf("%-34s %-34s %s\n", 'no unresolved lang key', $leak ? 'key leaked' : 'all resolved', $leak ? '<< FAIL' : 'ok');

    echo "\ndateTime(): ".$f->dateTime($eveningUtc)."\n";
    echo "\nRESULT: {$pass} passed, {$fail} failed\n";
    exit($fail === 0 ? 0 : 1);
}
