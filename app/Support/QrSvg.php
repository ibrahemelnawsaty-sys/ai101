<?php

declare(strict_types=1);

namespace App\Support;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\SvgWriter;

/**
 * A verification link as an inline SVG QR code — for the digital card and the
 * printed certificate alike, so both print at the printer's own resolution
 * rather than a bitmap's.
 *
 * No XML declaration and no fixed size, so it drops straight into a document
 * and takes the size its container gives it. A writer failure must not take
 * the document down with it: the card and the certificate are still valid
 * without their code, and the URL is printed beside it (art. 7, art. 17).
 *
 * @see PRD §9.6, §9.17 · BR-25 · D-57, D-81
 */
final class QrSvg
{
    public const SIZE = 180;

    public static function of(string $url, int $size = self::SIZE): string
    {
        if ($url === '') {
            return '';
        }

        try {
            return Builder::create()
                ->writer(new SvgWriter)
                ->writerOptions([
                    SvgWriter::WRITER_OPTION_EXCLUDE_XML_DECLARATION => true,
                    SvgWriter::WRITER_OPTION_EXCLUDE_SVG_WIDTH_AND_HEIGHT => true,
                ])
                ->data($url)
                ->errorCorrectionLevel(ErrorCorrectionLevel::Medium)
                ->size($size)
                ->margin(0)
                ->build()
                ->getString();
        } catch (\Throwable) {
            return '';
        }
    }
}
