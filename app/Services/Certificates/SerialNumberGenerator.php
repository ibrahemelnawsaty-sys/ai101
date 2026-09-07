<?php

declare(strict_types=1);

namespace App\Services\Certificates;

use App\Exceptions\CertificateException;
use App\Models\Certificate;
use App\Models\Cohort;
use App\Services\Time\Clock;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Certificate serial numbers and verification codes.
 *
 *   ATHAR-AI101-2026-0001
 *   ^     ^     ^    ^
 *   |     |     |    sequence within the programme and year, four digits
 *   |     |     issuing year, Riyadh calendar
 *   |     programme short code, read from configuration (BR-36)
 *   fixed platform prefix
 *
 * The next sequence is read under a row lock inside a transaction, and the
 * unique index on serial_number is the final authority: allocate() retries on
 * a duplicate rather than trusting the read.
 *
 * @see BR-26, BR-36 · D-07, D-31 · PRD §9.17 · CONTRACT §8
 */
final class SerialNumberGenerator
{
    public const PREFIX = 'ATHAR';

    public const SEQUENCE_LENGTH = 4;

    public const SEPARATOR = '-';

    private const MAX_ATTEMPTS = 5;

    private const VERIFY_RANDOM_BYTES = 12;

    private const VERIFY_SIGNATURE_LENGTH = 8;

    private const SQLSTATE_INTEGRITY_VIOLATION = '23000';

    private const MYSQL_DUPLICATE_ENTRY = 1062;

    private const SQLITE_CONSTRAINT = 19;

    /**
     * The next free serial for the cohort's programme, in the current year.
     *
     * OPEN DECISION, declared rather than assumed silently (D-31): neither
     * the PRD nor the contract says whether the year segment is the year of
     * issue or the year of the cohort, nor whether the counter runs per cohort,
     * per programme-year or platform wide. This implementation counts per
     * programme and year of ISSUE, which is the reading that matches the sample
     * `ATHAR-AI101-2026-0001` and never reuses a number. If the owner rules
     * otherwise, only prefixFor() and the query below change.
     */
    public function next(Cohort $cohort): string
    {
        return $this->nextSerial($this->programCode(), $this->currentYear());
    }

    /**
     * Alias kept for callers that read better as "generate".
     */
    public function generate(Cohort $cohort): string
    {
        return $this->next($cohort);
    }

    /**
     * The next free serial for an explicit programme code and year.
     */
    public function nextSerial(string $programCode, int $year): string
    {
        $prefix = $this->prefixFor($programCode, $year);

        /** @var string $serial */
        $serial = DB::transaction(function () use ($prefix): string {
            // Ordering by length first, then lexically: a plain string sort
            // puts '9999' after '10000' and would hand out 10000 twice once the
            // counter passes four digits.
            $last = Certificate::query()
                ->where('serial_number', 'like', $this->escapeLike($prefix).'%')
                ->orderByRaw('LENGTH(serial_number) DESC')
                ->orderByDesc('serial_number')
                ->lockForUpdate()
                ->value('serial_number');

            $sequence = is_string($last)
                ? (int) substr($last, strlen($prefix))
                : 0;

            return $prefix.$this->pad($sequence + 1);
        });

        return $serial;
    }

    /**
     * Allocate a serial and persist the certificate with it, retrying when a
     * concurrent issuer wins the race for the same number.
     *
     * The callback receives the serial and must create the row; whatever it
     * returns is returned here.
     *
     * @template TReturn
     *
     * @param  callable(string): TReturn  $persist
     * @return TReturn
     *
     * @throws CertificateException when no free serial could be claimed
     */
    public function allocate(Cohort $cohort, callable $persist): mixed
    {
        $programCode = $this->programCode();
        $year = $this->currentYear();

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $serial = $this->nextSerial($programCode, $year);

            try {
                return $persist($serial);
            } catch (QueryException $e) {
                if (! $this->isUniqueViolation($e) || $attempt === self::MAX_ATTEMPTS) {
                    throw CertificateException::serialGenerationFailed();
                }
            }
        }

        throw CertificateException::serialGenerationFailed();
    }

    /**
     * A long random, signed verification code. It is never derived from the
     * user identifier, and the signature lets an invalid code be rejected
     * before any database lookup (PRD §9.17, CONTRACT §8).
     */
    public function verifyCode(): string
    {
        try {
            $random = strtoupper(bin2hex(random_bytes(self::VERIFY_RANDOM_BYTES)));
        } catch (Throwable) {
            throw CertificateException::serialGenerationFailed();
        }

        return $random.$this->signature($random);
    }

    /**
     * Cheap structural check before hitting the database.
     */
    public function isWellFormedVerifyCode(string $code): bool
    {
        $expectedLength = (self::VERIFY_RANDOM_BYTES * 2) + self::VERIFY_SIGNATURE_LENGTH;

        if (strlen($code) !== $expectedLength) {
            return false;
        }

        $random = substr($code, 0, self::VERIFY_RANDOM_BYTES * 2);
        $signature = substr($code, self::VERIFY_RANDOM_BYTES * 2);

        if (preg_match('/^[0-9A-F]+$/', $random) !== 1) {
            return false;
        }

        return hash_equals($this->signature($random), $signature);
    }

    /**
     * `ATHAR-AI101-2026-`
     */
    public function prefixFor(string $programCode, int $year): string
    {
        return self::PREFIX
            .self::SEPARATOR.$this->normalizeCode($programCode)
            .self::SEPARATOR.$year
            .self::SEPARATOR;
    }

    /**
     * The programme short code, from configuration — never written in code.
     */
    public function programCode(): string
    {
        $configured = config('athar.program.code');

        return $this->normalizeCode(is_string($configured) && $configured !== '' ? $configured : 'AI101');
    }

    private function currentYear(): int
    {
        return (int) Clock::riyadh()->format('Y');
    }

    private function pad(int $sequence): string
    {
        return str_pad((string) $sequence, self::SEQUENCE_LENGTH, '0', STR_PAD_LEFT);
    }

    private function normalizeCode(string $code): string
    {
        $normalized = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code));

        return $normalized === '' ? 'AI101' : $normalized;
    }

    private function signature(string $random): string
    {
        $key = (string) config('app.key', '');

        return strtoupper(substr(hash_hmac('sha256', $random, $key), 0, self::VERIFY_SIGNATURE_LENGTH));
    }

    private function escapeLike(string $value): string
    {
        return addcslashes($value, '%_\\');
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        if ((string) $e->getCode() === self::SQLSTATE_INTEGRITY_VIOLATION) {
            return true;
        }

        $driverCode = $e->errorInfo[1] ?? null;

        return $driverCode === self::MYSQL_DUPLICATE_ENTRY || $driverCode === self::SQLITE_CONSTRAINT;
    }
}
