<?php

declare(strict_types=1);

namespace App\Services\Storage;

/**
 * What a ZIP archive really is when it claims to be a Word document, a
 * workbook or a slide deck (D-121, security review).
 *
 * WHY
 * A .docx, .xlsx or .pptx is a ZIP archive. libmagic names one by the first
 * entries of the archive — an order the program that wrote it chose — so many
 * genuine documents come back as plain `application/zip`, and so does ANY
 * archive. A deck field that accepted `application/zip` for that reason would
 * take an archive of scripts renamed `deck.pptx`: the bytes would prove only
 * "this is a ZIP" (art. 24).
 *
 * So a ZIP-family answer is settled by the package's own structure: its
 * `[Content_Types].xml` must declare the main part of a document, a workbook
 * or a presentation, and that part must be in the archive. Anything else is
 * an archive and nothing more — including a package whose main part is
 * declared MACRO-ENABLED, which is none of the three (art. 7).
 *
 * Only the archive's directory and that one small part are read; nothing is
 * extracted, so an archive bomb costs nothing. When ZipArchive is missing
 * (ext-zip is a documented requirement, deploy/README.md) nothing can be
 * proven and the answer is a plain archive — a deck field then refuses rather
 * than trusts.
 *
 * @see FR-PROJ-10 · PRD §12.5 · D-17, D-121 · CONSTITUTION art. 7, art. 24
 */
final class OfficeOpenXml
{
    public const ZIP = 'application/zip';

    /**
     * Each OOXML type => its main part and the content type the package must
     * declare for it (ECMA-376).
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const PACKAGES = [
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => [
            'word/document.xml',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml',
        ],
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => [
            'ppt/presentation.xml',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml',
        ],
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => [
            'xl/workbook.xml',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml',
        ],
    ];

    /** A genuine `[Content_Types].xml` is a few kilobytes; a larger one is not read. */
    public const MAX_CONTENT_TYPES_BYTES = 262144;

    private const CONTENT_TYPES_PART = '[Content_Types].xml';

    private const CONTENT_TYPES_NAMESPACE = 'http://schemas.openxmlformats.org/package/2006/content-types';

    /** Whether a sniffed type is one the archive's structure settles. */
    public static function decides(string $mime): bool
    {
        return $mime === self::ZIP || array_key_exists($mime, self::PACKAGES);
    }

    /**
     * The type the archive's structure proves: one of the three OOXML types,
     * or `application/zip` for anything else — an archive that cannot be
     * opened included.
     */
    public static function typeOf(string $absolutePath): string
    {
        if (! class_exists(\ZipArchive::class)) {
            return self::ZIP;
        }

        $zip = new \ZipArchive;

        if ($zip->open($absolutePath, \ZipArchive::RDONLY) !== true) {
            return self::ZIP;
        }

        try {
            $declared = self::declaredParts($zip);

            foreach (self::PACKAGES as $type => [$part, $contentType]) {
                if (($declared[$part] ?? null) === $contentType && $zip->locateName($part, \ZipArchive::FL_NOCASE) !== false) {
                    return $type;
                }
            }

            return self::ZIP;
        } finally {
            $zip->close();
        }
    }

    /**
     * The package's declared parts: part name (lower case, no leading slash)
     * => its content type (lower case). Empty when the declaration is
     * missing, oversized, carries a DTD, or is not well-formed XML.
     *
     * A genuine declaration never carries a DTD, and without one there is no
     * entity to expand; it is parsed without the network, so it cannot reach
     * out either (art. 7).
     *
     * @return array<string, string>
     */
    private static function declaredParts(\ZipArchive $zip): array
    {
        $stat = $zip->statName(self::CONTENT_TYPES_PART, \ZipArchive::FL_NOCASE);

        if ($stat === false || $stat['size'] <= 0 || $stat['size'] > self::MAX_CONTENT_TYPES_BYTES) {
            return [];
        }

        $xml = $zip->getFromIndex($stat['index'], self::MAX_CONTENT_TYPES_BYTES);

        if (! is_string($xml) || $xml === '' || stripos($xml, '<!DOCTYPE') !== false) {
            return [];
        }

        $document = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            return [];
        }

        $parts = [];

        foreach ($document->getElementsByTagNameNS(self::CONTENT_TYPES_NAMESPACE, 'Override') as $override) {
            $name = strtolower(ltrim($override->getAttribute('PartName'), '/'));

            if ($name !== '') {
                $parts[$name] = strtolower(trim($override->getAttribute('ContentType')));
            }
        }

        return $parts;
    }
}
