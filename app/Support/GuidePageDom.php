<?php

declare(strict_types=1);

namespace App\Support;

/**
 * A guide page, parsed the way a browser reads it (D-127) — for the checks run
 * when the general supervisor saves one.
 *
 * WHY A PARSER AND NOT PATTERNS
 * The first checks read the page with regular expressions, and an independent
 * review walked round every one of them: an unquoted attribute, a colour
 * written as an HTML entity (`&#103;old`), and a page whose unclosed <style>
 * made the pattern engine give up and report nothing — a check that fails
 * OPEN (Article 7). The parser decodes entities, reads unquoted attributes and
 * does not give up; when it cannot read a page at all, the page is refused.
 *
 * @see D-127 · CONSTITUTION Art. 7, Art. 14, Art. 24
 */
final class GuidePageDom
{
    /** The parsed page, or null when it cannot be read — which refuses it. */
    public static function load(string $html): ?\DOMXPath
    {
        if (trim($html) === '' || ! mb_check_encoding($html, 'UTF-8')) {
            return null;
        }

        $document = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);

        try {
            // The XML declaration tells libxml the bytes are UTF-8; without it
            // every Arabic letter is read as Latin-1.
            $loaded = $document->loadHTML(
                '<?xml encoding="UTF-8">'.$html,
                LIBXML_NONET | LIBXML_COMPACT | LIBXML_HTML_NODEFDTD | LIBXML_PARSEHUGE,
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $loaded ? new \DOMXPath($document) : null;
    }

    /**
     * The text of every node the query selects — attribute values decoded,
     * element text as written.
     *
     * @return list<string>
     */
    public static function values(\DOMXPath $xpath, string $query): array
    {
        $found = $xpath->query($query);

        if ($found === false) {
            return [];
        }

        $values = [];

        foreach ($found as $node) {
            $values[] = (string) $node->nodeValue;
        }

        return $values;
    }
}
