<?php

declare(strict_types=1);

/**
 * `D-131` — a participant's final-project hand-in was silently discarded by
 * PHP before Laravel ever ran, on every submission whose file was realistically
 * sized, because the live server's `upload_max_filesize`/`post_max_size` never
 * matched what `config/athar.php` promises. Both are `PHP_INI_PERDIR`: nothing
 * in PHP can raise them at runtime, so `public/.user.ini` is the only part of
 * the fix a test can hold in place — this guards that it exists, ships numbers
 * at least as large as the platform's own declared ceilings, and that the
 * deploy scripts that are supposed to carry it to the live document root still
 * do (the exact step that went missing the first time).
 *
 * @see PRD §9.11.2, §12.5 · D-121, D-124, D-131 · CONSTITUTION Articles 7, 10
 */

function userIniValues(string $path): array
{
    $ini = parse_ini_file($path, false, INI_SCANNER_RAW);

    expect($ini)->not->toBeFalse("{$path} is not valid ini");

    return $ini;
}

function iniBytes(string $value): int
{
    $value = trim($value);
    $unit = strtoupper(substr($value, -1));
    $number = (int) $value;

    return match ($unit) {
        'G' => $number * 1024 * 1024 * 1024,
        'M' => $number * 1024 * 1024,
        'K' => $number * 1024,
        default => (int) $value,
    };
}

it('D-131: public/.user.ini يرفع حدود الرفع الحقيقية على الاستضافة المشتركة إلى ما يَعِد به config/athar.php', function (): void {
    $path = base_path('public/.user.ini');

    expect(is_file($path))->toBeTrue('public/.user.ini غير موجود — الرفع يرتد لحدود الاستضافة الافتراضية الصغيرة');

    $ini = userIniValues($path);

    $perFileCeilingBytes = (int) config('athar.uploads.max_kilobytes') * 1024;
    $requestCeilingBytes = (int) config('athar.uploads.max_request_kilobytes') * 1024;
    $ticketCeilingBytes = (int) config('athar.support.max_files') * (int) config('athar.support.max_kilobytes') * 1024;

    expect(iniBytes($ini['upload_max_filesize'] ?? '0'))
        ->toBeGreaterThanOrEqual($perFileCeilingBytes)
        ->and(iniBytes($ini['post_max_size'] ?? '0'))
        ->toBeGreaterThanOrEqual(max($requestCeilingBytes, $ticketCeilingBytes))
        ->and((int) ($ini['max_file_uploads'] ?? 0))
        ->toBeGreaterThanOrEqual((int) config('athar.uploads.max_files'));
});

it('D-131: deploy/update.sh ينشر .user.ini إلى جذر الويب في كل نشرة — هذا هو الخطوة التي نُسيت أول مرة', function (): void {
    $script = (string) file_get_contents(base_path('deploy/update.sh'));

    expect($script)->toContain('public/.user.ini');
});

it('D-131: deploy/.htaccess لا يحجب الطلب قبل أن تصل Laravel صفحة الخطأ العربية — حدّ Apache فوق حدّ PHP لا تحته', function (): void {
    $htaccess = (string) file_get_contents(base_path('deploy/.htaccess'));

    expect(preg_match('/LimitRequestBody\s+(\d+)/', $htaccess, $m))->toBe(1);

    $apacheLimitBytes = (int) $m[1];
    $ini = userIniValues(base_path('public/.user.ini'));

    expect($apacheLimitBytes)->toBeGreaterThan(iniBytes($ini['post_max_size'] ?? '0'));
});
