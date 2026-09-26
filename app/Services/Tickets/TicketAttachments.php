<?php

declare(strict_types=1);

namespace App\Services\Tickets;

use App\Exceptions\FileException;
use App\Models\SupportTicket;
use App\Models\SupportTicketAttachment;
use App\Models\User;
use App\Services\Storage\PrivateFileService;
use Illuminate\Http\UploadedFile;

/**
 * The files a support ticket carries (D-124): pictures and videos, at most
 * three on one line of the ticket, each at most ten megabytes — the owner's
 * numbers, read from config/athar.php (BR-36).
 *
 * Every file goes through PrivateFileService: the type is sniffed from the
 * bytes and must be one of the six below whatever the name says, it is stored
 * outside the web root under a random name, and it leaves only through a
 * signed link after the policy is asked again (art. 24). Video is an opt-in
 * type there: the tickets ask for it by name, and nothing else on the platform
 * accepts one.
 *
 * @see D-124 · D-17 · PRD §12.5 · CONSTITUTION art. 22, art. 24
 */
final class TicketAttachments
{
    /** @var array<string, string> sniffed type => kind */
    public const TYPES = [
        'image/png' => SupportTicketAttachment::KIND_IMAGE,
        'image/jpeg' => SupportTicketAttachment::KIND_IMAGE,
        'image/webp' => SupportTicketAttachment::KIND_IMAGE,
        'video/mp4' => SupportTicketAttachment::KIND_VIDEO,
        'video/quicktime' => SupportTicketAttachment::KIND_VIDEO,
        'video/webm' => SupportTicketAttachment::KIND_VIDEO,
    ];

    /** The file picker's `accept`: a hint for the browser, never the check. */
    public const ACCEPT = '.png,.jpg,.jpeg,.webp,.mp4,.mov,.webm,image/png,image/jpeg,image/webp,video/mp4,video/quicktime,video/webm';

    private const DEFAULT_MAX_FILES = 3;

    private const DEFAULT_MAX_KILOBYTES = 10240;

    public function __construct(private readonly PrivateFileService $files) {}

    public static function maxFiles(): int
    {
        $configured = config('athar.support.max_files');

        return is_int($configured) && $configured > 0 ? $configured : self::DEFAULT_MAX_FILES;
    }

    public static function maxKilobytes(): int
    {
        $configured = config('athar.support.max_kilobytes');

        return is_int($configured) && $configured > 0 ? $configured : self::DEFAULT_MAX_KILOBYTES;
    }

    public static function maxMegabytes(): int
    {
        return (int) ceil(self::maxKilobytes() / 1024);
    }

    /**
     * Store each upload and hand back what the attachment rows need. The
     * limits are asked again here, not only by the form request: this is the
     * last door before the disk (art. 5).
     *
     * @param  list<UploadedFile>  $uploads
     * @return list<array{disk: string, path: string, original_name: string, mime_type: string, size_bytes: int, checksum: string, kind: string}>
     *
     * @throws FileException
     */
    public function store(array $uploads, SupportTicket $ticket, User $actor): array
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

                $descriptor = $this->files->store(
                    $upload,
                    'support/'.$ticket->getKey(),
                    $actor,
                    array_keys(self::TYPES),
                );

                $stored[] = $descriptor + ['kind' => self::TYPES[$descriptor['mime_type']]];
            }
        } catch (\Throwable $failure) {
            // One refused file refuses the line: the ones already written go,
            // so nothing sits on disk that no row points at.
            $this->discard($stored, $actor);

            throw $failure;
        }

        return $stored;
    }

    /**
     * Remove files stored for a line whose transaction then rolled back.
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
