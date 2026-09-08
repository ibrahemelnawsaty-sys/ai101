<?php

/**
 * Two data-integrity checks that need no database:
 *  1. every seeded journey unlock_rule is a value JourneyEvaluator recognises
 *  2. every trans_choice string in lang/ar is well formed for Laravel's parser
 */

declare(strict_types=1);

$root = 'c:/Users/b.maher/Downloads/wesal/LARAVEL';
$pass = 0;
$fail = 0;

/* ---------- 1. journey unlock_rule vocabulary ---------- */
$src = (string) file_get_contents($root.'/app/Services/Journey/JourneyEvaluator.php');
preg_match_all("/public const RULE_[A-Z_]+ = '([a-z_]+)';/", $src, $m);
$known = $m[1];
sort($known);
echo 'JourneyEvaluator recognises: '.implode(', ', $known)."\n\n";

$json = json_decode((string) file_get_contents($root.'/database/seeders/data/ai101-content.json'), true);
$found = [];
$walk = static function (mixed $n) use (&$walk, &$found): void {
    if (! is_array($n)) {
        return;
    }
    foreach ($n as $k => $v) {
        if ($k === 'unlock_rule' && is_string($v)) {
            $found[] = $v;
        } else {
            $walk($v);
        }
    }
};
$walk($json);

printf("%-28s %-10s %s\n", 'seeded unlock_rule', 'count', 'verdict');
echo str_repeat('-', 60), "\n";
foreach (array_count_values($found) as $rule => $count) {
    $ok = in_array($rule, $known, true);
    $ok ? $pass++ : $fail++;
    printf("%-28s %-10d %s\n", $rule, $count, $ok ? 'ok' : '<< FAIL not a known rule');
}
printf("\n%d journey steps seeded, %d distinct rules\n\n", count($found), count(array_unique($found)));

/* ---------- 2. trans_choice strings well formed ---------- */
$bad = [];
$total = 0;
$scan = static function (array $arr, string $prefix) use (&$scan, &$bad, &$total): void {
    foreach ($arr as $k => $v) {
        $key = $prefix === '' ? (string) $k : $prefix.'.'.$k;
        if (is_array($v)) {
            $scan($v, $key);

            continue;
        }
        if (! is_string($v) || ! str_contains($v, '|')) {
            continue;
        }
        $total++;
        foreach (explode('|', $v) as $seg) {
            $seg = trim($seg);
            // A choice segment is either {n}, [a,b] / [a,*], or a bare fallback.
            if (preg_match('/^\{\s*-?\d+\s*\}/u', $seg)) {
                continue;
            }
            if (preg_match('/^\[\s*-?\d+\s*,\s*(\*|-?\d+)\s*\]/u', $seg)) {
                continue;
            }
            $bad[] = $key.'  ->  '.mb_substr($v, 0, 70);
            break;
        }
    }
};
foreach (glob($root.'/lang/ar/*.php') ?: [] as $f) {
    $scan((array) require $f, basename($f, '.php'));
}

echo "trans_choice strings scanned: {$total}\n";
if ($bad === []) {
    $pass++;
    echo "all segments well formed  ok\n";
} else {
    $fail++;
    echo 'MALFORMED ('.count($bad)."):\n";
    foreach (array_slice($bad, 0, 12) as $b) {
        echo '  '.$b."\n";
    }
}

echo "\nRESULT: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
