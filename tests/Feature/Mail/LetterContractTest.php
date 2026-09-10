<?php

declare(strict_types=1);

/**
 * Every key a letter reaches for exists — copy keys, meta labels, route names.
 *
 * WHY THIS SUITE EXISTS
 * The mail layer was configured, credentialed and deployed, and four of its
 * letters could never be delivered. Not one of them failed loudly:
 *
 *   · SendAssignmentPublished labelled its detail strip with
 *     `(string) __('assignments.due')`. `assignments.due` is a GROUP, so the
 *     cast raised "Array to string conversion", which Laravel's error handler
 *     throws as an ErrorException, which the listener's own catch swallowed.
 *     NO participant, in ANY cohort, ever received an assignment-published
 *     letter — and the log recorded only the exception CLASS, never the reason.
 *   · SendNewDeviceLogin built its button with `route('profile.edit')`, a name
 *     that appears nowhere in routes/web.php. Same catch, same silence — on the
 *     one letter whose absence a victim cannot notice.
 *   · SendCertificateIssued and SendFinalProjectUnlocked labelled their strips
 *     with `certificates.serial` and `project.deadline`, neither of which
 *     exists. Those did not throw; they printed the raw ASCII key beside the
 *     value, inside an Arabic letter, on the message people forward to
 *     employers.
 *
 * Every one is the same shape as D-40, D-45, D-50, D-54, D-55 and D-61: a
 * contract between two files that nothing compares. The listener is valid PHP,
 * the lang file is a valid array, and only the NAME between them disagrees.
 *
 * The suite reads the listeners as TEXT rather than executing them, because the
 * bug lives in a string literal that a passing render test still would not
 * reach: the one render test in the mail suite used a hard-coded
 * 'CANARY-LABEL' => 'CANARY-VALUE' pair, so no listener's real meta was ever
 * rendered by anything.
 *
 * @see PRD §9.16.1 · CONSTITUTION.md Article 15, Article 21 · D-49, D-62
 */

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

/** Every locale the platform ships copy for. */
function letterLocales(): array
{
    return ['ar', 'en'];
}

/**
 * What each listener asks of the rest of the system, read from its source.
 *
 * @return list<array{file: string, line: int, kind: string, value: string}>
 */
function letterReferences(): array
{
    $found = [];

    foreach (File::allFiles(app_path('Listeners')) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $source = (string) File::get($file->getPathname());
        $name = $file->getFilename();

        $patterns = [
            // copyKey: 'emails.certificate_issued'
            'copyKey' => '/copyKey:\s*\'([\w.]+)\'/',
            // meta: ['assignments.deadline' => …] — the KEY, since D-62
            'meta' => '/meta:\s*\[\s*\'([\w.]+)\'\s*=>/',
            // route('profile')
            'route' => '/\broute\(\s*\'([\w.\-]+)\'/',
        ];

        foreach ($patterns as $kind => $pattern) {
            if (preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE) === 0) {
                continue;
            }

            foreach ($matches[1] as $index => $match) {
                $found[] = [
                    'file' => $name,
                    'line' => substr_count(substr($source, 0, (int) $matches[0][$index][1]), "\n") + 1,
                    'kind' => $kind,
                    'value' => (string) $match[0],
                ];
            }
        }
    }

    return $found;
}

it('D-62: كل تسمية في شريط التفاصيل تُحَلّ إلى نصّ في كل لغة', function (): void {
    $offenders = [];

    foreach (letterReferences() as $reference) {
        if ($reference['kind'] !== 'meta') {
            continue;
        }

        foreach (letterLocales() as $locale) {
            $label = __($reference['value'], [], $locale);

            // An array means the key names a GROUP: casting it to string is the
            // warning that silently killed the assignment letter. A label equal
            // to its own key means nothing translated it, and the reader sees
            // the key.
            $broken = ! is_string($label) || $label === '' || $label === $reference['value'];

            if ($broken) {
                $offenders[] = sprintf(
                    '%s:%d [%s] meta label "%s" resolves to %s',
                    $reference['file'],
                    $reference['line'],
                    $locale,
                    $reference['value'],
                    is_array($label) ? 'a GROUP (the cast that threw)' : 'its own key',
                );
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('D-62: لا مستمع يَحُلّ تسمية شريط التفاصيل بنفسه — المفتاح يُمرَّر خامًا', function (): void {
    // WITHOUT THIS CASE THE ONE ABOVE PASSES VACUOUSLY. It matches a quoted
    // literal key; the broken code passed an EXPRESSION —
    // `meta: [(string) __('assignments.due') => $event->dueAt]` — which the
    // pattern simply does not see, so it reported "0 labels checked" and went
    // green on the very code it exists to catch. Measured, not assumed.
    //
    // Resolution belongs in AtharLetter::metaRows(), which drops a label that
    // comes back as a group or as its own key. A listener that resolves its own
    // label puts that value beyond every guard in the layer.
    $offenders = [];

    foreach (File::allFiles(app_path('Listeners')) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $source = (string) File::get($file->getPathname());

        if (preg_match_all('/meta:\s*\[(.*?)=>/s', $source, $matches, PREG_OFFSET_CAPTURE) === 0) {
            continue;
        }

        foreach ($matches[1] as $index => $match) {
            if (preg_match('/^\s*\'[\w.]+\'\s*$/', (string) $match[0]) === 1) {
                continue;
            }

            $offenders[] = sprintf(
                '%s:%d — meta key is an expression, not a translation key: %s',
                $file->getFilename(),
                substr_count(substr($source, 0, (int) $matches[0][$index][1]), "\n") + 1,
                trim((string) $match[0]),
            );
        }
    }

    expect($offenders)->toBe([]);
});

it('D-62: كل كتلة نصّ يستعملها مستمع تحوي أسطرها الأربعة الإلزامية', function (): void {
    // AtharLetter always renders subject, preheader, heading and body. A group
    // missing any of them renders an empty slot: `announcement` has no `body`,
    // so the letter would be a heading and a button with nothing between them.
    $required = ['subject', 'preheader', 'heading', 'body'];
    $offenders = [];

    foreach (letterReferences() as $reference) {
        if ($reference['kind'] !== 'copyKey') {
            continue;
        }

        foreach (letterLocales() as $locale) {
            foreach ($required as $line) {
                $key = $reference['value'].'.'.$line;
                $text = __($key, [], $locale);

                if (! is_string($text) || $text === '' || $text === $key) {
                    $offenders[] = sprintf(
                        '%s:%d [%s] %s is missing',
                        $reference['file'],
                        $reference['line'],
                        $locale,
                        $key,
                    );
                }
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('D-62: كل اسم مسار يبنيه مستمع موجود فعلًا في جدول المسارات', function (): void {
    // `route('profile.edit')` throws RouteNotFoundException, and the listener's
    // own catch swallowed it — so the new-device security letter could never be
    // sent, and nothing said so.
    $offenders = [];

    foreach (letterReferences() as $reference) {
        if ($reference['kind'] !== 'route') {
            continue;
        }

        if (! Route::has($reference['value'])) {
            $offenders[] = sprintf(
                '%s:%d — route("%s") does not exist',
                $reference['file'],
                $reference['line'],
                $reference['value'],
            );
        }
    }

    expect($offenders)->toBe([]);
});

it('المادة 15: ملفا الرسائل متطابقان في المفاتيح بين العربية والإنجليزية', function (): void {
    $flatten = static function (array $rows, string $prefix = '') use (&$flatten): array {
        $keys = [];

        foreach ($rows as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            $keys = is_array($value)
                ? array_merge($keys, $flatten($value, $path))
                : array_merge($keys, [$path]);
        }

        return $keys;
    };

    $ar = $flatten(require lang_path('ar/emails.php'));
    $en = $flatten(require lang_path('en/emails.php'));

    expect(array_values(array_diff($ar, $en)))->toBe([])
        ->and(array_values(array_diff($en, $ar)))->toBe([]);
});

it('D-62: لا مهمّتان مجدولتان تتقاسمان القفل نفسه', function (): void {
    // `attendance:reconcile` was registered in bootstrap/app.php AND in
    // routes/console.php, with the same expression and the same mutex name.
    // withoutOverlapping prevents CONCURRENCY, not repetition: the second run
    // takes the mutex the moment the first releases it, so the BR-08/BR-09
    // sweep ran twice back to back every fifteen minutes. Nothing compared the
    // two files.
    $schedule = app(Illuminate\Console\Scheduling\Schedule::class);

    $mutexes = collect($schedule->events())
        ->map(static fn ($event): string => $event->mutexName())
        ->all();

    expect($mutexes)->toBe(array_values(array_unique($mutexes)));
});
