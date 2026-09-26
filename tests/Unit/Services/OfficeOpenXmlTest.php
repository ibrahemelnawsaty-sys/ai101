<?php

declare(strict_types=1);

/**
 * App\Services\Storage\OfficeOpenXml — a ZIP is a Word document, a workbook or
 * a slide deck only when its package says so; anything else is an archive
 * (D-121, security review: an archive of scripts renamed `deck.pptx` was
 * accepted into the presentation field). Every branch, for G8.
 *
 * @see FR-PROJ-10 · PRD §12.5 · D-17, D-121 · CONSTITUTION art. 7, art. 24
 */

use App\Services\Storage\OfficeOpenXml;
use App\Services\Storage\PrivateFileService;
use Illuminate\Support\Facades\Storage;

const OOXML_DECK = 'application/vnd.openxmlformats-officedocument.presentationml.presentation';
const OOXML_DECK_MAIN = 'application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml';

/** The bytes written to a temporary file, for the class that reads paths. */
function ooxmlFile(string $bytes): string
{
    $path = (string) tempnam(sys_get_temp_dir(), 'ooxml');
    file_put_contents($path, $bytes);

    register_shutdown_function(static function () use ($path): void {
        if (is_file($path)) {
            unlink($path);
        }
    });

    return $path;
}

it('D-121: حزمة عرض ومستند وجدول حقيقية تُعرف بنوعها من بنيتها', function (): void {
    $packages = [
        OOXML_DECK => ['ppt/presentation.xml' => OOXML_DECK_MAIN],
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => [
            'word/document.xml' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml',
        ],
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => [
            'xl/workbook.xml' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml',
        ],
    ];

    foreach ($packages as $type => $declared) {
        expect(OfficeOpenXml::typeOf(ooxmlFile(ooxmlBytes($declared))))->toBe($type);
    }

    // Part names are case-insensitive in a package (OPC).
    $shouting = zipBytes([
        '[Content_Types].xml' => '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Override PartName="/PPT/Presentation.XML" ContentType="'.strtoupper(OOXML_DECK_MAIN).'"/></Types>',
        'ppt/presentation.xml' => '<root/>',
    ]);

    expect(OfficeOpenXml::typeOf(ooxmlFile($shouting)))->toBe(OOXML_DECK);
});

it('D-121: أرشيف لا يعلن جزء المستند الرئيس — أو يعلنه بلا الجزء، أو بماكرو — أرشيفٌ لا أكثر', function (): void {
    $cases = [
        'scripts renamed .pptx' => zipBytes(['run.js' => 'CANARY', 'invoice.lnk' => 'CANARY']),
        'declared, part missing' => zipBytes([
            '[Content_Types].xml' => '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                .'<Override PartName="/ppt/presentation.xml" ContentType="'.OOXML_DECK_MAIN.'"/></Types>',
        ]),
        'part present, nothing declared' => zipBytes([
            '[Content_Types].xml' => '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>',
            'ppt/presentation.xml' => '<root/>',
        ]),
        'macro-enabled deck' => ooxmlBytes(['ppt/presentation.xml' => 'application/vnd.ms-powerpoint.presentation.macroEnabled.main+xml']),
        'declaration in another namespace' => zipBytes([
            '[Content_Types].xml' => '<?xml version="1.0"?><Types xmlns="urn:not-a-package">'
                .'<Override PartName="/ppt/presentation.xml" ContentType="'.OOXML_DECK_MAIN.'"/></Types>',
            'ppt/presentation.xml' => '<root/>',
        ]),
    ];

    foreach ($cases as $case => $bytes) {
        expect(OfficeOpenXml::typeOf(ooxmlFile($bytes)))->toBe(OfficeOpenXml::ZIP, $case);
    }
});

it('D-121: إعلان فيه DTD أو غير سليم أو أكبر من السقف لا يُقرأ، وما ليس أرشيفًا لا يُفتح', function (): void {
    $main = '<Override PartName="/ppt/presentation.xml" ContentType="'.OOXML_DECK_MAIN.'"/>';
    $types = static fn (string $inside, string $before = ''): string => '<?xml version="1.0"?>'.$before
        .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'.$inside.'</Types>';

    $cases = [
        'dtd' => $types($main, '<!DOCTYPE Types [<!ENTITY x "y">]>'),
        'broken xml' => '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'.$main,
        'oversized' => $types($main.'<!--'.str_repeat('x', OfficeOpenXml::MAX_CONTENT_TYPES_BYTES).'-->'),
        'empty' => '',
    ];

    foreach ($cases as $case => $declaration) {
        $bytes = zipBytes(['[Content_Types].xml' => $declaration, 'ppt/presentation.xml' => '<root/>']);

        expect(OfficeOpenXml::typeOf(ooxmlFile($bytes)))->toBe(OfficeOpenXml::ZIP, $case);
    }

    // The same package with a sound declaration IS a deck — the cases above
    // fail on the declaration alone.
    $sound = zipBytes(['[Content_Types].xml' => $types($main), 'ppt/presentation.xml' => '<root/>']);

    expect(OfficeOpenXml::typeOf(ooxmlFile($sound)))->toBe(OOXML_DECK)
        ->and(OfficeOpenXml::typeOf(ooxmlFile('%PDF-1.4 not an archive')))->toBe(OfficeOpenXml::ZIP)
        ->and(OfficeOpenXml::decides('application/zip'))->toBeTrue()
        ->and(OfficeOpenXml::decides(OOXML_DECK))->toBeTrue()
        ->and(OfficeOpenXml::decides('application/pdf'))->toBeFalse()
        ->and(OfficeOpenXml::decides('image/png'))->toBeFalse();
});

it('D-121: خدمة التخزين تسمّي العرض الحقيقي باسمه وامتداده، والأرشيف يبقى أرشيفًا مقبولًا حيث تقبله المنصة', function (): void {
    Storage::fake('private');
    $service = app(PrivateFileService::class);

    $deck = $service->store(fakeUpload('deck.pptx', 'pptx'), 'final-projects/t');
    $archive = $service->store(fakeUpload('bundle.zip', 'zip'), 'submissions/t');

    expect($deck['mime_type'])->toBe(OOXML_DECK)
        ->and($deck['path'])->toEndWith('.pptx')
        ->and($deck['original_name'])->toBe('deck.pptx')
        ->and($archive['mime_type'])->toBe('application/zip')
        ->and($archive['path'])->toEndWith('.zip');
});
