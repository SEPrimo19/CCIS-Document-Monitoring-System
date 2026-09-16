<?php

declare(strict_types=1);

namespace App\Core;

use ZipArchive;

/**
 * Plain-text extraction from a .docx, for the in-app viewer (FR-41).
 *
 * WHY THIS EXISTS: no browser renders a Word document, this deployment has no
 * conversion library, and handing staff compliance documents to an external
 * preview service is not a trade this system makes. Most of the college's
 * paperwork is Word, so a viewer that could only say "download to read" would
 * have answered the request for almost nothing. This reads the document's text
 * so the Secretary can read it in place and decide; the formatted original is
 * always one click away, and the screen says plainly that this is a text
 * extract rather than a faithful rendering.
 *
 * WHAT IT DELIBERATELY IS NOT: a renderer. Tables flatten, images vanish,
 * numbering and styling are dropped. Presenting it as anything more would be
 * worse than not having it.
 *
 * SAFETY. A .docx is a ZIP of XML supplied by a user, so:
 *  - Only `word/document.xml` is read; no other entry is touched and nothing is
 *    ever extracted to disk.
 *  - The entry is refused if its declared uncompressed size is implausible, and
 *    the read is capped, so a zip bomb cannot exhaust memory.
 *  - The text is pulled with a regex over the run elements rather than an XML
 *    parse. That looks cruder, and it is deliberate: it removes the XXE surface
 *    entirely rather than relying on remembering the right libxml flags.
 *  - The result is plain text and is escaped by the view, so nothing inside the
 *    document can become markup.
 */
final class DocxText
{
    /** Largest document.xml this will read, uncompressed. */
    private const MAX_XML_BYTES = 12 * 1024 * 1024;

    /** Largest amount of text handed back to a page. */
    private const MAX_TEXT_CHARS = 200000;

    public const OK = 'ok';
    public const NOT_A_DOCX = 'not_a_docx';
    public const TOO_LARGE = 'too_large';
    public const NO_TEXT = 'no_text';

    /**
     * @return array{status:string,text:string,truncated:bool}
     */
    public static function extract(string $absolutePath): array
    {
        $fail = static fn(string $status): array => ['status' => $status, 'text' => '', 'truncated' => false];

        if (!is_file($absolutePath) || !is_readable($absolutePath)) {
            return $fail(self::NOT_A_DOCX);
        }

        $zip = new ZipArchive();

        if ($zip->open($absolutePath) !== true) {
            return $fail(self::NOT_A_DOCX);
        }

        try {
            $stat = $zip->statName('word/document.xml');

            if ($stat === false) {
                return $fail(self::NOT_A_DOCX);
            }

            if (isset($stat['size']) && (int) $stat['size'] > self::MAX_XML_BYTES) {
                return $fail(self::TOO_LARGE);
            }

            // Streamed and capped rather than getFromName(), so a lying header
            // cannot make this read more than the cap regardless.
            $handle = $zip->getStream('word/document.xml');

            if ($handle === false) {
                return $fail(self::NOT_A_DOCX);
            }

            $xml = stream_get_contents($handle, self::MAX_XML_BYTES + 1);
            fclose($handle);
        } finally {
            $zip->close();
        }

        if (!is_string($xml) || $xml === '') {
            return $fail(self::NO_TEXT);
        }

        if (strlen($xml) > self::MAX_XML_BYTES) {
            return $fail(self::TOO_LARGE);
        }

        return self::textFromXml($xml);
    }

    /**
     * @return array{status:string,text:string,truncated:bool}
     */
    private static function textFromXml(string $xml): array
    {
        // Paragraph and line breaks first, so the structure survives as
        // newlines once the tags are gone.
        $xml = preg_replace('~<w:p[ >]~', "\n<w:p ", $xml) ?? $xml;
        $xml = str_replace(['<w:br/>', '<w:br />', '</w:p>'], "\n", $xml);
        // A tab inside a run is real content, not markup.
        $xml = str_replace(['<w:tab/>', '<w:tab />'], "\t", $xml);

        // The text runs themselves.
        preg_match_all('~<w:t(?:\s[^>]*)?>(.*?)</w:t>~s', $xml, $matches);

        if (empty($matches[1])) {
            return ['status' => self::NO_TEXT, 'text' => '', 'truncated' => false];
        }

        // Rebuild in document order: keep the newlines that sat between runs.
        $parts = preg_split('~<w:t(?:\s[^>]*)?>.*?</w:t>~s', $xml) ?: [];
        $out = '';

        foreach ($matches[1] as $i => $run) {
            $between = $parts[$i] ?? '';
            // Only the newlines injected above survive from between the runs.
            $out .= str_repeat("\n", substr_count($between, "\n"));
            $out .= html_entity_decode($run, ENT_QUOTES | ENT_XML1, 'UTF-8');
        }

        $out = preg_replace('~\n{3,}~', "\n\n", $out) ?? $out;
        $out = trim($out);

        if ($out === '') {
            return ['status' => self::NO_TEXT, 'text' => '', 'truncated' => false];
        }

        $truncated = mb_strlen($out) > self::MAX_TEXT_CHARS;

        if ($truncated) {
            $out = mb_substr($out, 0, self::MAX_TEXT_CHARS);
        }

        return ['status' => self::OK, 'text' => $out, 'truncated' => $truncated];
    }
}
