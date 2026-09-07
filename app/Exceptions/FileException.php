<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Private file storage failures.
 *
 * @see PRD §12.5 · PRD §11.1 · CONSTITUTION art. 24
 */
final class FileException extends DomainException
{
    public static function tooLarge(int $maxMegabytes): self
    {
        return new self('errors.file.too_large', ['max' => $maxMegabytes], 422);
    }

    public static function emptyFile(): self
    {
        return new self('errors.file.empty', [], 422);
    }

    public static function unreadable(): self
    {
        return new self('errors.file.unreadable', [], 422);
    }

    /** The real MIME type sniffed from the content is not on the allowlist. */
    public static function mimeNotAllowed(): self
    {
        return new self('errors.file.mime_not_allowed', [], 422);
    }

    /** Executables, scripts and double extensions are refused outright. */
    public static function executableRejected(): self
    {
        return new self('errors.file.executable_rejected', [], 422);
    }

    /** Fail safe: without content sniffing we refuse rather than trust the extension. */
    public static function sniffUnavailable(): self
    {
        return new self('errors.file.sniff_unavailable', [], 500);
    }

    public static function notFound(): self
    {
        return new self('errors.file.not_found', [], 404);
    }

    public static function invalidPath(): self
    {
        return new self('errors.file.invalid_path', [], 422);
    }

    public static function signedRouteMissing(): self
    {
        return new self('errors.file.signed_route_missing', [], 500);
    }
}
