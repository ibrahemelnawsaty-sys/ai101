<?php

declare(strict_types=1);

/**
 * D-107 — extracting a clean, verified Zoom url from whatever a trainer or
 * coordinator pastes, without ever trusting the raw markup. No database, no
 * HTTP: ZoomRecordingUrl::extract() is a pure function of its input.
 *
 * @see App\Support\ZoomRecordingUrl · CONSTITUTION Article 24 · D-107
 */

use App\Support\ZoomRecordingUrl;

it('D-107: رابط زوم مباشر يُقبل ويُعاد كما هو', function (): void {
    expect(ZoomRecordingUrl::extract('https://zoom.us/rec/share/abc123'))
        ->toBe('https://zoom.us/rec/share/abc123');
});

it('D-107: نطاق فرعي إقليمي لزوم يُقبل', function (): void {
    expect(ZoomRecordingUrl::extract('https://us02web.zoom.us/rec/share/abc123'))
        ->toBe('https://us02web.zoom.us/rec/share/abc123');
});

it('D-107: كود دمج iframe باقتباس مزدوج يُستخرج منه الرابط فقط', function (): void {
    $embed = '<iframe src="https://us05web.zoom.us/rec/play/xyz789" width="640" height="360" allowfullscreen></iframe>';

    expect(ZoomRecordingUrl::extract($embed))->toBe('https://us05web.zoom.us/rec/play/xyz789');
});

it('D-107: كود دمج iframe باقتباس مفرد يُستخرج منه الرابط فقط', function (): void {
    $embed = "<iframe src='https://zoom.us/rec/play/singlequote'></iframe>";

    expect(ZoomRecordingUrl::extract($embed))->toBe('https://zoom.us/rec/play/singlequote');
});

it('D-107: رموز HTML في رابط الاستعلام تُفكّ قبل التحقق', function (): void {
    $embed = '<iframe src="https://zoom.us/rec/play/q?a=1&amp;b=2"></iframe>';

    expect(ZoomRecordingUrl::extract($embed))->toBe('https://zoom.us/rec/play/q?a=1&b=2');
});

it('D-107: نطاق ليس zoom.us يُرفض تمامًا', function (): void {
    expect(ZoomRecordingUrl::extract('https://evil.com/rec/share/abc'))->toBeNull();
});

it('D-107: نطاق مقلَّد بلاحقة zoom.us يُرفض (لا مطابقة نصية جزئية)', function (): void {
    expect(ZoomRecordingUrl::extract('https://zoom.us.evil.tld/rec/share/abc'))->toBeNull();
});

it('D-107: نطاق مقلَّد بادئته zoom.us يُرفض', function (): void {
    expect(ZoomRecordingUrl::extract('https://notzoom.us/rec/share/abc'))->toBeNull();
});

it('D-107: رابط http غير مشفَّر يُرفض، zoom.us أو غيره', function (): void {
    expect(ZoomRecordingUrl::extract('http://zoom.us/rec/share/abc'))->toBeNull();
});

it('D-107: مدخل فارغ أو بياض فقط يُرفض بلا استثناء', function (): void {
    expect(ZoomRecordingUrl::extract(''))->toBeNull()
        ->and(ZoomRecordingUrl::extract('   '))->toBeNull();
});

it('D-107: نص عشوائي بلا رابط يُرفض', function (): void {
    expect(ZoomRecordingUrl::extract('ليس رابطًا على الإطلاق'))->toBeNull();
});

it('D-107: كود دمج iframe بمصدر ليس زوم يُرفض رغم كونه iframe صالح الشكل', function (): void {
    expect(ZoomRecordingUrl::extract('<iframe src="https://evil.com/x"></iframe>'))->toBeNull();
});
