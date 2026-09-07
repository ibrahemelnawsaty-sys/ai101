<?php

declare(strict_types=1);

namespace App\Services\Storage;

use App\Exceptions\FileException;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Time\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Every file a user uploads passes through here, and nothing else writes to the
 * upload disks.
 *
 * The rules, all enforced on the server:
 *
 *  · the disk lives OUTSIDE the web root, so there is no URL to guess and no
 *    directory listing to walk (PROJECT-CONTRACT §11, art. 10)
 *  · the stored name is random and carries no part of the original name, so a
 *    guessed name cannot reach another participant's work (BR-22)
 *  · the type is sniffed from the file's own first bytes; the declared
 *    extension and the browser-supplied Content-Type are treated as hints from
 *    an untrusted source and never as evidence (art. 24)
 *  · when content sniffing is unavailable the upload is REFUSED rather than
 *    trusted - failing safe beats failing open (art. 7)
 *  · executables, scripts and anything that a browser would execute (html,
 *    svg, js, php ...) are refused outright, including inside a double
 *    extension such as `report.php.pdf`
 *  · the stored extension is derived from the sniffed type through a fixed
 *    map, so no user-supplied extension ever reaches the filesystem
 *  · downloads are served only through a signed link valid for fifteen
 *    minutes, minted after the permission check, never before
 *
 * @see BR-22, BR-27 · PRD §9.11.2, §12.5 · CONSTITUTION art. 22, art. 24
 */
final class PrivateFileService
{
    /** The disk outside the web root (config/filesystems.php). */
    public const DISK = 'private';

    /** Signed download links live fifteen minutes (art. 22). */
    public const SIGNED_URL_MINUTES = 15;

    /** Fallback ceiling per file, in kilobytes (PRD §9.11.2). */
    public const DEFAULT_MAX_KILOBYTES = 25600;

    /** Extension used when the sniffed type has no safe extension of its own. */
    public const NEUTRAL_EXTENSION = 'bin';

    /**
     * Sniffed type => the extension the stored copy is given. This map is the
     * only source of stored extensions; anything absent from it becomes `.bin`.
     *
     * The OOXML formats (docx, xlsx, pptx) are ZIP containers and are sniffed
     * as such by most builds of libmagic, which is why `application/zip` is on
     * the list and why the stored extension for them is `zip`. The original
     * name is kept in the database and is what the participant downloads as.
     *
     * @var array<string, string>
     */
    private const ALLOWED_TYPES = [
        'application/pdf' => 'pdf',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-powerpoint' => 'ppt',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.oasis.opendocument.text' => 'odt',
        'application/vnd.oasis.opendocument.spreadsheet' => 'ods',
        'application/vnd.oasis.opendocument.presentation' => 'odp',
        'application/zip' => 'zip',
        'text/plain' => 'txt',
        'text/csv' => 'csv',
        'text/markdown' => 'md',
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    /**
     * Sniffed types refused outright, whatever the extension claims.
     *
     * @var list<string>
     */
    private const FORBIDDEN_TYPES = [
        'application/x-dosexec',
        'application/x-msdownload',
        'application/x-executable',
        'application/x-sharedlib',
        'application/x-mach-binary',
        'application/vnd.microsoft.portable-executable',
        'application/x-elf',
        'application/x-httpd-php',
        'application/x-httpd-php-source',
        'application/x-php',
        'text/x-php',
        'text/x-shellscript',
        'application/x-sh',
        'application/x-csh',
        'application/x-perl',
        'text/x-perl',
        'text/x-python',
        'application/x-python-code',
        'application/java-archive',
        'application/x-java-applet',
        'application/javascript',
        'text/javascript',
        'text/html',
        'application/xhtml+xml',
        'image/svg+xml',
        'application/x-msi',
        'application/x-bat',
        'application/hta',
    ];

    /**
     * Extensions refused anywhere in the original file name, so `a.php.pdf`
     * and `a.pdf.exe` are both refused before anything is written.
     *
     * @var list<string>
     */
    private const FORBIDDEN_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phps', 'phtml', 'phar', 'pht',
        'exe', 'msi', 'com', 'scr', 'cpl', 'dll', 'so', 'dylib', 'bin', 'app',
        'bat', 'cmd', 'sh', 'bash', 'zsh', 'ksh', 'csh', 'ps1', 'psm1', 'vbs', 'vbe',
        'js', 'mjs', 'cjs', 'jse', 'wsf', 'wsh', 'hta', 'jar', 'class', 'war',
        'py', 'pyc', 'rb', 'pl', 'cgi', 'asp', 'aspx', 'jsp', 'jspx', 'cfm',
        'html', 'htm', 'xhtml', 'shtml', 'svg', 'svgz', 'xml', 'xsl', 'swf',
        'htaccess', 'htpasswd', 'ini', 'conf', 'lnk', 'reg', 'iso', 'img',
    ];

    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Validate, sniff and store one upload. Returns the row the caller writes
     * to the database - the original name is data, never a filesystem name.
     *
     * @return array{
     *     disk: string,
     *     path: string,
     *     original_name: string,
     *     mime_type: string,
     *     size_bytes: int,
     *     checksum: string
     * }
     *
     * @throws FileException
     */
    public function store(UploadedFile $file, string $directory, ?User $actor = null): array
    {
        if (! $file->isValid()) {
            throw FileException::unreadable();
        }

        $source = $file->getPathname();

        if ($source === '' || ! is_file($source) || ! is_readable($source)) {
            throw FileException::unreadable();
        }

        $size = (int) @filesize($source);

        if ($size <= 0) {
            throw FileException::emptyFile();
        }

        $maxBytes = $this->maxKilobytes() * 1024;

        if ($size > $maxBytes) {
            throw FileException::tooLarge((int) ceil($this->maxKilobytes() / 1024));
        }

        $originalName = $this->sanitizeOriginalName($file->getClientOriginalName());

        $this->guardExtensions($originalName);

        $mime = $this->sniff($source);

        $this->guardType($mime);

        $checksum = hash_file('sha256', $source);

        if ($checksum === false) {
            throw FileException::unreadable();
        }

        $disk = $this->disk();
        $folder = $this->sanitizeDirectory($directory);
        $storedName = Str::uuid()->toString().'.'.$this->extensionFor($mime);
        $path = $folder === '' ? $storedName : $folder.'/'.$storedName;

        // Article 8: the trail is written before the operation completes.
        $this->audit->record(
            action: AuditLogger::FILE_STORED,
            entityType: 'file',
            entityId: null,
            before: null,
            after: [
                'disk' => $disk,
                'path' => $path,
                'mime_type' => $mime,
                'size_bytes' => $size,
                'checksum' => $checksum,
            ],
            actorId: $actor === null ? null : (string) $actor->getKey(),
        );

        $stream = @fopen($source, 'rb');

        if ($stream === false) {
            throw FileException::unreadable();
        }

        $written = Storage::disk($disk)->put($path, $stream);

        if (is_resource($stream)) {
            fclose($stream);
        }

        if ($written === false) {
            throw FileException::unreadable();
        }

        return [
            'disk' => $disk,
            'path' => $path,
            'original_name' => $originalName,
            'mime_type' => $mime,
            'size_bytes' => $size,
            'checksum' => $checksum,
        ];
    }

    /**
     * A signed link to a download route, valid for fifteen minutes.
     *
     * The caller checks the permission first and mints the link second: this
     * method proves nothing about who may download, it only limits how long the
     * proof lasts (art. 22).
     *
     * @param  array<string, mixed>  $parameters
     *
     * @throws FileException
     */
    public function temporaryUrl(string $routeName, array $parameters, ?int $minutes = null): string
    {
        if (! Route::has($routeName)) {
            throw FileException::signedRouteMissing();
        }

        return URL::temporarySignedRoute($routeName, $this->expiresAt($minutes), $parameters);
    }

    /**
     * When a link minted now stops working.
     */
    public function expiresAt(?int $minutes = null): CarbonImmutable
    {
        return Clock::now()->addMinutes($this->ttlMinutes($minutes));
    }

    public function ttlMinutes(?int $minutes = null): int
    {
        if ($minutes !== null && $minutes > 0) {
            return min($minutes, $this->configuredTtlMinutes());
        }

        return $this->configuredTtlMinutes();
    }

    /**
     * @throws FileException
     */
    public function contents(string $path, ?string $disk = null): string
    {
        $safe = $this->assertSafePath($path);
        $storage = Storage::disk($disk ?? $this->disk());

        if (! $storage->exists($safe)) {
            throw FileException::notFound();
        }

        $contents = $storage->get($safe);

        if ($contents === null) {
            throw FileException::unreadable();
        }

        return $contents;
    }

    /**
     * The absolute path on the private disk, for streaming a download response.
     *
     * @throws FileException
     */
    public function absolutePath(string $path, ?string $disk = null): string
    {
        $safe = $this->assertSafePath($path);
        $storage = Storage::disk($disk ?? $this->disk());

        if (! $storage->exists($safe)) {
            throw FileException::notFound();
        }

        return $storage->path($safe);
    }

    public function exists(string $path, ?string $disk = null): bool
    {
        try {
            $safe = $this->assertSafePath($path);
        } catch (FileException) {
            return false;
        }

        return Storage::disk($disk ?? $this->disk())->exists($safe);
    }

    /**
     * Remove a stored file. The database row that references it is the caller's
     * responsibility; nothing here deletes a user, an attendance, an evaluation
     * or a certificate (art. 13, rule 11).
     *
     * @throws FileException
     */
    public function delete(string $path, ?User $actor = null, ?string $disk = null): bool
    {
        $safe = $this->assertSafePath($path);
        $target = $disk ?? $this->disk();

        $this->audit->record(
            action: AuditLogger::FILE_DELETED,
            entityType: 'file',
            entityId: null,
            before: ['disk' => $target, 'path' => $safe],
            after: null,
            actorId: $actor === null ? null : (string) $actor->getKey(),
        );

        return Storage::disk($target)->delete($safe);
    }

    /**
     * The sniffed type of a file already on disk - used when re-verifying a
     * stored file before serving it.
     *
     * @throws FileException
     */
    public function sniffStored(string $path, ?string $disk = null): string
    {
        return $this->sniff($this->absolutePath($path, $disk));
    }

    /**
     * @return list<string>
     */
    public function allowedMimeTypes(): array
    {
        return array_keys(self::ALLOWED_TYPES);
    }

    public function disk(): string
    {
        $configured = config('athar.uploads.disk');

        return is_string($configured) && $configured !== '' ? $configured : self::DISK;
    }

    public function maxKilobytes(): int
    {
        $configured = config('athar.uploads.max_kilobytes');

        return is_int($configured) && $configured > 0 ? $configured : self::DEFAULT_MAX_KILOBYTES;
    }

    // ----------------------------------------------------------- the guards

    /**
     * Read the real type from the file's own bytes.
     *
     * @throws FileException
     */
    private function sniff(string $absolutePath): string
    {
        if (! function_exists('finfo_open')) {
            // Fail safe: without sniffing there is no evidence, and an
            // extension is not evidence (art. 7).
            throw FileException::sniffUnavailable();
        }

        $finfo = @finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo === false) {
            throw FileException::sniffUnavailable();
        }

        $mime = @finfo_file($finfo, $absolutePath);
        finfo_close($finfo);

        if (! is_string($mime) || $mime === '') {
            throw FileException::sniffUnavailable();
        }

        return strtolower(explode(';', $mime)[0]);
    }

    /**
     * @throws FileException
     */
    private function guardType(string $mime): void
    {
        if (in_array($mime, self::FORBIDDEN_TYPES, true)) {
            throw FileException::executableRejected();
        }

        if (! array_key_exists($mime, self::ALLOWED_TYPES)) {
            throw FileException::mimeNotAllowed();
        }
    }

    /**
     * Every dot-separated segment of the name is checked, not only the last
     * one: `report.php.pdf` is refused here.
     *
     * @throws FileException
     */
    private function guardExtensions(string $originalName): void
    {
        $segments = array_slice(explode('.', strtolower($originalName)), 1);

        foreach ($segments as $segment) {
            if (in_array(trim($segment), self::FORBIDDEN_EXTENSIONS, true)) {
                throw FileException::executableRejected();
            }
        }
    }

    private function extensionFor(string $mime): string
    {
        return self::ALLOWED_TYPES[$mime] ?? self::NEUTRAL_EXTENSION;
    }

    /**
     * The name shown to the participant. Path separators, control characters
     * and directory traversal are removed; the result is data only and never
     * touches the filesystem.
     */
    private function sanitizeOriginalName(string $name): string
    {
        $base = basename(str_replace('\\', '/', $name));
        $clean = preg_replace('/[\x00-\x1F\x7F]/u', '', $base);
        $clean = is_string($clean) ? trim($clean) : '';
        $clean = str_replace(['..', '/', '\\'], '', $clean);

        return $clean === '' ? 'file' : mb_substr($clean, 0, 255);
    }

    /**
     * @throws FileException
     */
    private function sanitizeDirectory(string $directory): string
    {
        $trimmed = trim(str_replace('\\', '/', $directory), '/');

        if ($trimmed === '') {
            return '';
        }

        if (preg_match('#^[A-Za-z0-9_\-/]+$#', $trimmed) !== 1 || str_contains($trimmed, '..')) {
            throw FileException::invalidPath();
        }

        return $trimmed;
    }

    /**
     * @throws FileException
     */
    private function assertSafePath(string $path): string
    {
        $trimmed = trim(str_replace('\\', '/', $path), '/');

        if ($trimmed === '' || str_contains($trimmed, '..') || str_contains($trimmed, "\0")) {
            throw FileException::invalidPath();
        }

        if (preg_match('#^[A-Za-z0-9_\-./]+$#', $trimmed) !== 1) {
            throw FileException::invalidPath();
        }

        return $trimmed;
    }

    private function configuredTtlMinutes(): int
    {
        $configured = config('athar.security.signed_url_ttl_minutes');

        return is_int($configured) && $configured > 0 ? $configured : self::SIGNED_URL_MINUTES;
    }
}
