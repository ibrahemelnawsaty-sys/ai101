<?php

declare(strict_types=1);

namespace App\Support;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\PngWriter;

/**
 * A link as a PNG QR code, for a letter (D-122).
 *
 * A letter cannot carry the inline SVG a page uses (QrSvg): most mail clients
 * drop SVG, and a remote image is blocked by default in many of them. A PNG
 * embedded in the message itself is the one form they all show. A writer
 * failure must not stop the letter: it still carries the code in text and the
 * link as its button (art. 7, art. 17).
 *
 * @see PRD §9.16 · D-122
 */
final class QrPng
{
    public const SIZE = 240;

    public static function of(string $url, int $size = self::SIZE): string
    {
        if ($url === '') {
            return '';
        }

        try {
            return Builder::create()
                ->writer(new PngWriter)
                ->data($url)
                ->errorCorrectionLevel(ErrorCorrectionLevel::Medium)
                ->size($size)
                ->margin(8)
                ->build()
                ->getString();
        } catch (\Throwable) {
            return '';
        }
    }
}
