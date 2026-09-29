<?php

declare(strict_types=1);

namespace App\Services\Messages;

use App\Exceptions\FileException;
use App\Models\Thread;
use App\Models\User;
use App\Services\Storage\PrivateFileService;
use Illuminate\Http\UploadedFile;

/**
 * The files a message carries (D-136): at most three, each at most ten
 * megabytes — the numbers the composer has always promised, read from
 * config/athar.php (BR-36) — from the platform's closed list of formats (D-121).
 *
 * Every file goes through PrivateFileService: the type is sniffed from the
 * bytes whatever the name says, a program named `notes.pdf` is refused, video
 * is refused (it is an opt-in of the support ticket alone), the file is stored
 * outside the web root under a random name, and it leaves only through a
 * signed link after the thread's policy is asked again (art. 24).
 *
 * The limits are asked here as well as by the form request: this is the last
 * door before the disk (art. 5). One refused file refuses the message, and the
 * files already written for it are removed, so nothing sits on disk that no
 * message points at.
 *
 * @see BR-22, BR-36 · FR-MSG-06 · PRD §9.13.2, §12.5 · D-17, D-121, D-136
 */
final class MessageAttachments
{
    private const DEFAULT_MAX_FILES = 3;

    private const DEFAULT_MAX_KILOBYTES = 10240;

    public function __construct(private readonly PrivateFileService $files) {}

    public static function maxFiles(): int
    {
        $configured = config('athar.messages.max_files');

        return is_int($configured) && $configured > 0 ? $configured : self::DEFAULT_MAX_FILES;
    }

    public static function maxKilobytes(): int
    {
        $configured = config('athar.messages.max_kilobytes');

        return is_int($configured) && $configured > 0 ? $configured : self::DEFAULT_MAX_KILOBYTES;
    }

    public static function maxMegabytes(): int
    {
        return (int) ceil(self::maxKilobytes() / 1024);
    }

    /**
     * Store each upload and hand back the descriptors the message row keeps.
     *
     * @param  list<UploadedFile>  $uploads
     * @return list<array{disk: string, path: string, original_name: string, mime_type: string, size_bytes: int, checksum: string}>
     *
     * @throws FileException
     */
    public function store(array $uploads, Thread $thread, User $actor): array
    {
        if (count($uploads) > self::maxFiles()) {
            throw FileException::tooMany(self::maxFiles());
        }

        $stored = [];

        try {
            foreach ($uploads as $upload) {
                if ((int) $upload->getSize() > self::maxKilobytes() * 1024) {
                    throw FileException::tooLarge(self::maxMegabytes());
                }

                $stored[] = $this->files->store($upload, 'messages/'.$thread->getKey(), $actor);
            }
        } catch (\Throwable $failure) {
            $this->discard($stored, $actor);

            throw $failure;
        }

        return $stored;
    }

    /**
     * Remove files stored for a message whose row was never written.
     *
     * @param  list<array<string, mixed>>  $descriptors
     */
    public function discard(array $descriptors, User $actor): void
    {
        foreach ($descriptors as $descriptor) {
            try {
                $this->files->discardRolledBack($descriptor, $actor);
            } catch (FileException) {
                // Nothing left to remove; the trail already says so.
            }
        }
    }
}
