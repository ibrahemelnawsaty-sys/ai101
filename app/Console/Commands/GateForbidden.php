<?php

declare(strict_types=1);

namespace App\Console\Commands;

/**
 * Gate G3 - the blacklist.
 *
 * One command per Article 13 item, in the article's own order. Items that have
 * a dedicated gate (time, Arabic, design values, yellow) are delegated to that
 * gate's scanner rather than reimplemented, so the two commands can never drift
 * apart. Items the article itself marks as human or test enforced are reported
 * as warnings with the location of the thing a reviewer must look at, and never
 * silently claimed as automated.
 *
 * Severity map:
 *   FAIL - items 1, 2, 3, 4, 5, 8, 9, 10, 11, 12, 13, 14
 *   WARN - items 6, 7, 15 (review or authorization test enforced)
 *
 * @see CONSTITUTION.md Article 13, Article 26 gate G3
 */
final class GateForbidden extends GateCommand
{
    protected $signature = 'gate:forbidden
        {--json : Emit findings as JSON instead of a table}
        {--path= : Scan a different repository root}';

    protected $description = 'G3: the Article 13 blacklist - zero matches allowed.';

    /** @var list<string> */
    private const SCAN_DIRECTORIES = ['app', 'bootstrap', 'config', 'database', 'resources', 'routes'];

    /** Directories that may carry build or deployment scripts. */
    private const SCRIPT_DIRECTORIES = ['.github', 'deploy', 'bootstrap', 'config'];

    private const SCRIPT_ROOT_FILES = ['composer.json', 'package.json', 'Makefile', '.gitignore', '.gitattributes'];

    /** Item 1: debug output left in the source. */
    private const DEBUG_PATTERN = '/(?<![\w$>:\\\\])\b(dd|ddd|dump|var_dump|print_r|ray)\s*\(/';

    private const CONSOLE_PATTERN = '/\bconsole\s*\.\s*(log|debug|info|warn|error|table|trace)\s*\(/';

    /** Item 8: mass assignment protection switched off. */
    private const UNGUARD_PATTERN = '/(?<![\w$>])(unguard\s*\(|forceFill\s*\(|\$guarded\s*=\s*\[\s*\])/';

    /** Item 9: raw SQL. */
    private const RAW_SQL_PATTERN = '/(?<![\w$])(DB::raw|DB::statement|DB::unprepared|whereRaw|orWhereRaw|selectRaw|havingRaw|orHavingRaw|orderByRaw|groupByRaw|joinRaw)\s*\(/';

    /** Item 10: credentials committed to the repository. */
    private const PRIVATE_KEY_PATTERN = '/-{5}BEGIN[A-Z ]{0,40}PRIVATE KEY-{5}/';

    private const CREDENTIAL_PATTERN = '/\b(password|passwd|secret|api_?key|access_?token|client_?secret|private_?key)\b\s*(?:=>|=|:)\s*([\'"])([^\'"\s]{12,})\2/i';

    /** Item 11: irreversible deletion of protected records. */
    private const HARD_DELETE_PATTERN = '/(?<![\w$])(forceDelete\s*\(|truncate\s*\(\s*\))/';

    /** Item 12: bypassing the git hooks. */
    private const NO_VERIFY_PATTERN = '/-{2}no-verify/';

    /** Item 14: env() outside config/, which config:cache silently empties. */
    private const ENV_PATTERN = '/(?<![\w$>:\\\\])\benv\s*\(/';

    /** Item 6: statics that read a table with no ownership scope. */
    private const UNSCOPED_PATTERN = '/(?<![\w$>:\\\\])([A-Z][A-Za-z0-9_]*)::(all|first|firstOrFail|find|findOrFail|get|pluck)\s*\(/';

    /**
     * Facades and helpers whose statics have nothing to do with tenant scope.
     *
     * @var list<string>
     */
    private const SCOPE_ALLOWLIST = [
        'App', 'Arr', 'Artisan', 'Auth', 'Blade', 'Bus', 'Cache', 'Config', 'Context', 'Cookie',
        'Crypt', 'DB', 'Date', 'Event', 'File', 'Gate', 'Hash', 'Http', 'Js', 'Lang', 'Log',
        'Mail', 'Notification', 'Number', 'Password', 'Process', 'Queue', 'RateLimiter', 'Redirect',
        'Request', 'Response', 'Route', 'Schema', 'Session', 'Storage', 'Str', 'URL', 'Validator',
        'View', 'Vite', 'Clock', 'Carbon', 'CarbonImmutable', 'Collection', 'self', 'static', 'parent',
    ];

    /**
     * True when the name at $offset is being DECLARED, not called.
     *
     * A policy that answers `public function forceDelete(): bool { return false; }`
     * is the guard against item 11, not a breach of it. The same holds for a
     * method named dump(), unguard() or env() on a value object.
     */
    private static function isDeclaration(string $code, int $offset): bool
    {
        $window = substr($code, max(0, $offset - 32), min(32, $offset));

        return preg_match('/\bfunction\s+&?\s*$/', $window) === 1;
    }

    public function handle(): int
    {
        $files = $this->collectFiles(self::SCAN_DIRECTORIES, ['php', 'blade', 'css', 'js']);

        foreach ($files as $file) {
            $this->filesScanned++;
            $contents = $this->read($file);
            $kind = self::kindOf($file);

            $this->itemOneDebugOutput($file, $kind, $contents);
            $this->delegatedItems($file, $contents);

            if ($kind === 'php' || $kind === 'blade') {
                $this->itemSixUnscopedQueries($file, $kind, $contents);
                $this->itemSevenMissingFormRequest($file, $kind, $contents);
                $this->itemEightMassAssignment($file, $kind, $contents);
                $this->itemNineRawSql($file, $kind, $contents);
                $this->itemTenCredentials($file, $contents);
                $this->itemElevenHardDeletes($file, $kind, $contents);
                $this->itemFourteenEnvCalls($file, $kind, $contents);
            }

            if ($kind === 'blade') {
                $this->itemThirteenBladeLogic($file, $contents);
            }

            if (str_starts_with($file, 'database/migrations/')) {
                $this->itemFifteenColumnDrops($file, $contents);
            }
        }

        $this->itemTenRepositorySecrets();
        $this->itemTwelveNoVerify();

        return $this->renderReport('G3', 'FORBIDDEN - the Article 13 blacklist', 'zero FAIL matches across app, resources, routes, config and database');
    }

    // -------------------------------------------------------------- item 1

    private function itemOneDebugOutput(string $file, string $kind, string $contents): void
    {
        if (! in_array($kind, ['php', 'blade', 'js'], true)) {
            return;
        }

        $code = self::sanitizeSource($contents, $kind);

        if ($kind !== 'js') {
            foreach (self::matches(self::DEBUG_PATTERN, $code) as $match) {
                if (self::isDeclaration($code, $match['offset'])) {
                    continue;
                }

                $this->addFail(
                    $file,
                    self::lineAt($code, $match['offset']),
                    'A13-01-DEBUG',
                    'debug output '.trim($match['text']).' must not reach the repository'
                );
            }
        }

        foreach (self::matches(self::CONSOLE_PATTERN, $code) as $match) {
            $this->addFail(
                $file,
                self::lineAt($code, $match['offset']),
                'A13-01-CONSOLE',
                'browser console output '.trim($match['text']).' must not reach the repository'
            );
        }
    }

    // ------------------------------------------------- items 2, 3, 4 and 5

    /**
     * Items with a dedicated gate share that gate's scanner, so G3 and the
     * specialised gate can never disagree about what is forbidden.
     */
    private function delegatedItems(string $file, string $contents): void
    {
        foreach (GateClock::violations($file, $contents) as $found) {
            $this->addFinding($found['severity'], $file, $found['line'], 'A13-02-'.$found['rule'], $found['detail']);
        }

        foreach (GateI18n::arabicViolations($file, $contents) as $found) {
            $this->addFinding($found['severity'], $file, $found['line'], 'A13-03-'.$found['rule'], $found['detail']);
        }

        foreach (GateTokens::literalViolations($file, $contents) as $found) {
            $this->addFinding($found['severity'], $file, $found['line'], 'A13-04-'.$found['rule'], $found['detail']);
        }

        foreach (GateTokens::yellowViolations($file, $contents) as $found) {
            $this->addFinding($found['severity'], $file, $found['line'], 'A13-05-'.$found['rule'], $found['detail']);
        }
    }

    // -------------------------------------------------------------- item 6

    /**
     * A static read inside a controller is where an unscoped query normally
     * hides. This is a heuristic pointing a reviewer at a line, not a proof,
     * so it warns: the binding proof is the authorization test suite (G9).
     */
    private function itemSixUnscopedQueries(string $file, string $kind, string $contents): void
    {
        if (! str_starts_with($file, 'app/Http/Controllers/')) {
            return;
        }

        $code = self::sanitizeSource($contents, $kind);

        foreach (self::matches(self::UNSCOPED_PATTERN, $code) as $match) {
            $class = $match['groups'][1] ?? '';

            if (in_array($class, self::SCOPE_ALLOWLIST, true)) {
                continue;
            }

            $this->addWarn(
                $file,
                self::lineAt($code, $match['offset']),
                'A13-06-SCOPE',
                trim($match['text']).' reads a table directly; prove the query is restricted to the actor scope'
            );
        }
    }

    // -------------------------------------------------------------- item 7

    /**
     * A controller action typed against the generic request cannot have been
     * validated by a FormRequest. Warn and point at the line; the authorization
     * test suite is what actually blocks the merge.
     */
    private function itemSevenMissingFormRequest(string $file, string $kind, string $contents): void
    {
        if (! str_starts_with($file, 'app/Http/Controllers/')) {
            return;
        }

        $code = self::sanitizeSource($contents, $kind);

        foreach (self::matches('/\bfunction\s+\w+\s*\([^)]*\bRequest\s+\$/', $code) as $match) {
            $this->addWarn(
                $file,
                self::lineAt($code, $match['offset']),
                'A13-07-REQUEST',
                'action typed against the generic Request; a state changing endpoint needs a FormRequest and a Policy'
            );
        }
    }

    // -------------------------------------------------------------- item 8

    private function itemEightMassAssignment(string $file, string $kind, string $contents): void
    {
        $code = self::sanitizeSource($contents, $kind);

        foreach (self::matches(self::UNGUARD_PATTERN, $code) as $match) {
            if (self::isDeclaration($code, $match['offset'])) {
                continue;
            }

            $this->addFail(
                $file,
                self::lineAt($code, $match['offset']),
                'A13-08-UNGUARD',
                trim($match['text']).' disables mass assignment protection'
            );
        }
    }

    // -------------------------------------------------------------- item 9

    /**
     * Raw SQL is only forbidden when the statement itself carries a variable:
     * a bound parameter list is fine, an interpolated fragment is not. String
     * bodies are therefore preserved here, so interpolation stays visible.
     */
    private function itemNineRawSql(string $file, string $kind, string $contents): void
    {
        if (self::isGateSource($file)) {
            return;
        }

        $code = self::sanitizeSource($contents, $kind, false);

        foreach (self::matches(self::RAW_SQL_PATTERN, $code) as $match) {
            $open = $match['offset'] + strlen($match['text']) - 1;
            $argument = self::firstArgument($code, $open);

            if (! str_contains($argument, '$')) {
                continue;
            }

            $this->addFail(
                $file,
                self::lineAt($code, $match['offset']),
                'A13-09-RAW-SQL',
                trim($match['text']).') carries a variable in the statement; bind the parameter instead'
            );
        }
    }

    // ------------------------------------------------------------- item 10

    private function itemTenCredentials(string $file, string $contents): void
    {
        if (self::isGateSource($file)) {
            return;
        }

        foreach (self::matches(self::PRIVATE_KEY_PATTERN, $contents) as $match) {
            $this->addFail(
                $file,
                self::lineAt($contents, $match['offset']),
                'A13-10-PRIVATE-KEY',
                'a private key block is committed to the repository'
            );
        }

        // config/ reads its values through env(), and fixtures may carry fakes.
        $isFixture = str_starts_with($file, 'config/')
            || str_starts_with($file, 'tests/')
            || str_starts_with($file, 'database/factories/')
            || str_starts_with($file, 'database/seeders/');

        foreach (self::matches(self::CREDENTIAL_PATTERN, $contents) as $match) {
            $name = strtolower($match['groups'][1] ?? 'credential');

            $this->addFinding(
                $isFixture ? self::WARN : self::FAIL,
                $file,
                self::lineAt($contents, $match['offset']),
                'A13-10-SECRET',
                'literal value assigned to "'.$name.'"; secrets belong in .env outside the web root'
            );
        }
    }

    /**
     * A repository must not be able to carry the environment file at all.
     */
    private function itemTenRepositorySecrets(): void
    {
        $ignore = $this->read('.gitignore');

        if ($ignore === '') {
            $this->addFail('.gitignore', 0, 'A13-10-GITIGNORE', 'no .gitignore: the environment file is not protected');

            return;
        }

        if (preg_match('/^\s*\/?\.env\s*$/m', $ignore) !== 1) {
            $this->addFail('.gitignore', 0, 'A13-10-GITIGNORE', '.gitignore does not ignore the .env file');
        }
    }

    // ------------------------------------------------------------- item 11

    private function itemElevenHardDeletes(string $file, string $kind, string $contents): void
    {
        if (str_starts_with($file, 'database/seeders/') || str_starts_with($file, 'tests/')) {
            return;
        }

        $code = self::sanitizeSource($contents, $kind);

        foreach (self::matches(self::HARD_DELETE_PATTERN, $code) as $match) {
            if (self::isDeclaration($code, $match['offset'])) {
                continue;
            }

            $this->addFail(
                $file,
                self::lineAt($code, $match['offset']),
                'A13-11-HARD-DELETE',
                trim($match['text']).' destroys records; users, attendance, evaluations and certificates are never hard deleted'
            );
        }
    }

    // ------------------------------------------------------------- item 12

    private function itemTwelveNoVerify(): void
    {
        $files = $this->collectFiles(self::SCRIPT_DIRECTORIES, ['sh', 'yaml', 'json']);

        foreach (self::SCRIPT_ROOT_FILES as $root) {
            if ($this->exists($root)) {
                $files[] = $root;
            }
        }

        foreach (array_unique($files) as $file) {
            $contents = $this->read($file);

            foreach (self::matches(self::NO_VERIFY_PATTERN, $contents) as $match) {
                $this->addFail(
                    $file,
                    self::lineAt($contents, $match['offset']),
                    'A13-12-NO-VERIFY',
                    'the git hooks must never be bypassed'
                );
            }
        }
    }

    // ------------------------------------------------------------- item 13

    /**
     * Blade renders, it does not decide. A raw PHP island that branches, walks
     * a relation or touches a static is business logic in the wrong layer.
     */
    private function itemThirteenBladeLogic(string $file, string $contents): void
    {
        $code = self::sanitizeSource($contents, 'blade');

        foreach (self::matches('/@php\b(.*?)(?:@endphp|\z)/s', $code) as $match) {
            $body = $match['groups'][1] ?? '';
            $hasLogic = preg_match('/\b(if|foreach|for|while|switch|function)\s*\(|::|->/', $body) === 1;

            $this->addFinding(
                $hasLogic ? self::FAIL : self::WARN,
                $file,
                self::lineAt($code, $match['offset']),
                'A13-13-BLADE-PHP',
                $hasLogic
                    ? 'a @php island carries business logic; move it to a controller, a service or a view model'
                    : 'a @php island in a template; prefer passing the value in from the controller'
            );
        }
    }

    // ------------------------------------------------------------- item 14

    private function itemFourteenEnvCalls(string $file, string $kind, string $contents): void
    {
        if (str_starts_with($file, 'config/')) {
            return;
        }

        $code = self::sanitizeSource($contents, $kind);

        foreach (self::matches(self::ENV_PATTERN, $code) as $match) {
            if (self::isDeclaration($code, $match['offset'])) {
                continue;
            }

            $this->addFail(
                $file,
                self::lineAt($code, $match['offset']),
                'A13-14-ENV',
                'env() outside config/ returns null once config:cache has run; read it from config()'
            );
        }
    }

    // ------------------------------------------------------------- item 15

    private function itemFifteenColumnDrops(string $file, string $contents): void
    {
        $code = self::sanitizeSource($contents, 'php');

        foreach (self::matches('/(?<![\w$])dropColumn\s*\(/', $code) as $match) {
            $this->addWarn(
                $file,
                self::lineAt($code, $match['offset']),
                'A13-15-DROP-COLUMN',
                'dropping a column needs a documented backup and a human sign off before merge'
            );
        }
    }
}
