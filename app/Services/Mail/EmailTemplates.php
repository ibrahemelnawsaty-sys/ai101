<?php

declare(strict_types=1);

namespace App\Services\Mail;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Translation\FileLoader;

/**
 * What the e-mail template editor may change, and the rules that keep a letter
 * from being broken by it (BR-31, D-136).
 *
 * WHAT IS EDITABLE
 * The SUBJECT and the BODY of a template, and nothing else. The heading, the
 * button, the security notes ("if this was not you", the link's lifetime) and
 * the links are fixed whatever anyone posts: they are what makes a
 * password-reset letter one, and the words a person is warned by.
 *
 * WHAT A TEXT MUST KEEP, AND MAY USE
 * Every live value its ORIGINAL carries (`:program`, `:name`, `:score`…) — the
 * letter would otherwise go out without the thing it exists to say. And no
 * value the original of that same field does not carry: the recipient would
 * read ":secret" in their inbox. Each sender passes its own values to each
 * field — the token letter gives `:program` to its subject and its body and
 * nothing else — so the only thing PROVEN to reach a field is what that field's
 * own original already uses. The rule asks for exactly that and allows exactly
 * that; a value a sibling field uses is not allowed here.
 *
 * THE ORIGINAL IS THE FILE
 * Defaults are read straight from lang/ through a private FileLoader, never
 * through the application translator: the translator already carries the
 * published overrides, and "the original" must mean the file. A text equal to
 * its original, or empty, is not an override — going back is the absence of a
 * row, so the letter follows the file again.
 *
 * @see BR-31, BR-36 · PRD §9.16, §9.18 · CONSTITUTION art. 5, art. 6, art. 22 · D-114, D-136
 */
final class EmailTemplates
{
    /** The translation group every template belongs to. */
    public const GROUP = 'emails';

    /** The fields a template's editor writes, in the order it shows them. */
    public const FIELDS = ['subject', 'body'];

    /** Longest text per field, checked before the database sees it. */
    public const MAX_LENGTH = ['subject' => 200, 'body' => 4000];

    /** Shared wording, not a template of its own. */
    private const NOT_A_TEMPLATE = ['common'];

    /** How Laravel finds a live value: a colon and a name, anywhere in the text. */
    private const PLACEHOLDER = '/:([A-Za-z_][A-Za-z0-9_]*)/';

    /** @var array<string, array<string, mixed>>|null locale => the file's group */
    private ?array $files = null;

    private readonly FileLoader $loader;

    public function __construct(?FileLoader $loader = null)
    {
        $this->loader = $loader ?? new FileLoader(new Filesystem, [lang_path()]);
    }

    /**
     * The templates that have something to edit, in the file's order.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        $keys = [];

        foreach (array_keys($this->group()) as $key) {
            if (is_string($key) && $this->fieldsOf($key) !== []) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * The editable fields this template's file copy actually has.
     *
     * @return list<string>
     */
    public function fieldsOf(string $template): array
    {
        if (in_array($template, self::NOT_A_TEMPLATE, true)) {
            return [];
        }

        $copy = $this->group()[$template] ?? null;

        if (! is_array($copy)) {
            return [];
        }

        return array_values(array_filter(
            self::FIELDS,
            static fn (string $field): bool => is_string($copy[$field] ?? null) && $copy[$field] !== '',
        ));
    }

    /** The file's text for one editable field, or '' when there is none. */
    public function defaultOf(string $template, string $field): string
    {
        return in_array($field, $this->fieldsOf($template), true)
            ? (string) $this->group()[$template][$field]
            : '';
    }

    /**
     * The live values this field's original carries — the ones a text must keep.
     *
     * @return list<string>
     */
    public function requiredIn(string $template, string $field): array
    {
        return $this->placeholdersIn($this->defaultOf($template, $field));
    }

    /**
     * The live values any field of the template's original copy uses — the ones
     * a PREVIEW is given samples for. It is not what a text may use: that is
     * requiredIn(), field by field.
     *
     * @return list<string>
     */
    public function allowedIn(string $template): array
    {
        $copy = $this->group()[$template] ?? null;

        if (! is_array($copy) || in_array($template, self::NOT_A_TEMPLATE, true)) {
            return [];
        }

        $names = [];

        foreach ($copy as $text) {
            if (is_string($text)) {
                array_push($names, ...$this->placeholdersIn($text));
            }
        }

        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }

    /**
     * Why a text may not be saved for this field, or null when it may.
     *
     * The order is the order of what is worth telling first: the field is not
     * editable · too long · drops a live value · adds one this field's original
     * does not carry. `names` are lower-case, sorted, as Laravel reads them.
     *
     * @return array{code: string, names: list<string>, max?: int}|null
     */
    public function problemWith(string $template, string $field, string $text): ?array
    {
        if (! in_array($field, $this->fieldsOf($template), true)) {
            return ['code' => 'field', 'names' => []];
        }

        $max = self::MAX_LENGTH[$field];

        if (mb_strlen($text) > $max) {
            return ['code' => 'long', 'names' => [], 'max' => $max];
        }

        $used = $this->placeholdersIn($text);

        $missing = array_values(array_diff($this->requiredIn($template, $field), $used));

        if ($missing !== []) {
            return ['code' => 'missing', 'names' => $missing];
        }

        $unknown = array_values(array_diff($used, $this->requiredIn($template, $field)));

        if ($unknown !== []) {
            return ['code' => 'unknown', 'names' => $unknown];
        }

        return null;
    }

    /**
     * Whether a text is a real override. Empty, or equal to the original
     * (ignoring the space around it), is "follow the file".
     */
    public function isOverride(string $template, string $field, ?string $text): bool
    {
        $text = trim((string) $text);

        return $text !== '' && $text !== trim($this->defaultOf($template, $field));
    }

    /**
     * The live values a text uses, lower-case, sorted, once each.
     *
     * @return list<string>
     */
    public function placeholdersIn(string $text): array
    {
        preg_match_all(self::PLACEHOLDER, $text, $found);

        $names = array_values(array_unique(array_map('strtolower', $found[1])));
        sort($names);

        return $names;
    }

    /**
     * The file's group, Arabic — the language every letter is sent in today.
     *
     * @return array<string, mixed>
     */
    private function group(): array
    {
        return $this->files ??= $this->loader->load('ar', self::GROUP);
    }
}
