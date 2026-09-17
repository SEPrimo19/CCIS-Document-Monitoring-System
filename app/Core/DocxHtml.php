<?php

declare(strict_types=1);

namespace App\Core;

use DOMDocument;
use DOMElement;
use DOMNode;
use ZipArchive;

/**
 * Renders a .docx as HTML for the in-app viewer (FR-41).
 *
 * WHY THIS AND NOT A CONVERTER: no browser displays a Word file, this machine
 * has no LibreOffice and PHP here has no COM extension, so there is nothing to
 * convert with. Driving the installed Word through COM would also tie the
 * system to a server that has Microsoft Office on it, which is a poor property
 * for something meant to be deployed to a campus server and a worse one to
 * defend. A client-side renderer would mean shipping a third-party JavaScript
 * library, and the project's locked stack is vanilla JavaScript. So the
 * document is rendered here, from its own XML.
 *
 * WHAT IT RENDERS: paragraphs and heading levels, bold / italic / underline,
 * tables including merged cells and nesting, and embedded images. That covers
 * what this college's paperwork actually contains — syllabi, tables of
 * specification and teaching loads are mostly text and tables.
 *
 * WHAT IT DOES NOT: page geometry, columns, headers and footers, exact fonts
 * and spacing. It is a faithful reading of the CONTENT, not a pixel copy of the
 * page, and the screen says so. The original is always one click away.
 *
 * SAFETY. The .docx is user-supplied, so:
 *  - Only word/document.xml and the relationship file are parsed; nothing is
 *    ever extracted to disk.
 *  - Reads are capped, and an implausible declared size is refused, so a zip
 *    bomb cannot exhaust memory.
 *  - EVERY tag in the output is written by this class. Document text is only
 *    ever inserted through htmlspecialchars(), so nothing inside the file can
 *    become markup — there is no path from document content to an attribute or
 *    a tag name.
 *  - Images are not inlined. Each one becomes a URL back into the application,
 *    which re-checks who is asking before serving the bytes.
 *  - XML is parsed with network access disabled. External entity loading is
 *    off by default in the libxml this runs on, and LIBXML_NONET makes the
 *    intent explicit rather than inherited.
 */
final class DocxHtml
{
    private const W_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    private const R_NS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    private const REL_NS = 'http://schemas.openxmlformats.org/package/2006/relationships';

    private const MAX_XML_BYTES = 12 * 1024 * 1024;
    private const MAX_NODES = 20000;

    public const OK = 'ok';
    public const NOT_A_DOCX = 'not_a_docx';
    public const TOO_LARGE = 'too_large';
    public const NO_CONTENT = 'no_content';

    private int $nodeBudget = self::MAX_NODES;
    private bool $truncated = false;

    /** @var array<string,string> relationship id => media path inside the zip */
    private array $images = [];

    private int $fileId = 0;

    /**
     * @return array{status:string,html:string,truncated:bool}
     */
    public static function render(string $absolutePath, int $fileId): array
    {
        $self = new self();
        $self->fileId = $fileId;

        return $self->run($absolutePath);
    }

    /**
     * Relationship id => path inside the archive, for the media route.
     *
     * @return array<string,string>
     */
    public static function imageMap(string $absolutePath): array
    {
        $zip = new ZipArchive();

        if ($zip->open($absolutePath) !== true) {
            return [];
        }

        try {
            $rels = $zip->getFromName('word/_rels/document.xml.rels');
        } finally {
            $zip->close();
        }

        return is_string($rels) ? self::parseRels($rels) : [];
    }

    /**
     * @return array{status:string,html:string,truncated:bool}
     */
    private function run(string $absolutePath): array
    {
        $fail = static fn(string $s): array => ['status' => $s, 'html' => '', 'truncated' => false];

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

            $handle = $zip->getStream('word/document.xml');

            if ($handle === false) {
                return $fail(self::NOT_A_DOCX);
            }

            $xml = stream_get_contents($handle, self::MAX_XML_BYTES + 1);
            fclose($handle);

            $rels = $zip->getFromName('word/_rels/document.xml.rels');
        } finally {
            $zip->close();
        }

        if (!is_string($xml) || $xml === '') {
            return $fail(self::NO_CONTENT);
        }

        if (strlen($xml) > self::MAX_XML_BYTES) {
            return $fail(self::TOO_LARGE);
        }

        $this->images = is_string($rels) ? self::parseRels($rels) : [];

        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $doc->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            return $fail(self::NOT_A_DOCX);
        }

        $bodies = $doc->getElementsByTagNameNS(self::W_NS, 'body');

        if ($bodies->length === 0) {
            return $fail(self::NO_CONTENT);
        }

        $html = $this->blocks($bodies->item(0));

        if (trim(strip_tags($html)) === '' && !str_contains($html, '<img')) {
            return $fail(self::NO_CONTENT);
        }

        return ['status' => self::OK, 'html' => $html, 'truncated' => $this->truncated];
    }

    /**
     * Relationship ids that point at an image inside the package.
     *
     * @return array<string,string>
     */
    private static function parseRels(string $xml): array
    {
        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $ok = $doc->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$ok) {
            return [];
        }

        $map = [];

        foreach ($doc->getElementsByTagNameNS(self::REL_NS, 'Relationship') as $rel) {
            /** @var DOMElement $rel */
            $target = $rel->getAttribute('Target');
            $id = $rel->getAttribute('Id');

            // Only media inside the package, and never a path that climbs out
            // of it: the Target comes from the uploaded file.
            if ($id === '' || $target === '' || str_contains($target, '..')) {
                continue;
            }

            $normalised = 'word/' . ltrim(str_replace('\\', '/', $target), '/');

            if (str_starts_with($normalised, 'word/media/')) {
                $map[$id] = $normalised;
            }
        }

        return $map;
    }

    /** Render the block-level children of a container. */
    private function blocks(?DOMNode $parent): string
    {
        if ($parent === null) {
            return '';
        }

        $out = '';

        foreach ($parent->childNodes as $node) {
            if (!$node instanceof DOMElement || $node->namespaceURI !== self::W_NS) {
                continue;
            }

            if ($this->nodeBudget-- <= 0) {
                $this->truncated = true;
                break;
            }

            if ($node->localName === 'p') {
                $out .= $this->paragraph($node);
            } elseif ($node->localName === 'tbl') {
                $out .= $this->table($node);
            } elseif ($node->localName === 'sdt') {
                // A content control wraps real content in sdtContent.
                foreach ($node->childNodes as $child) {
                    if ($child instanceof DOMElement && $child->localName === 'sdtContent') {
                        $out .= $this->blocks($child);
                    }
                }
            }
        }

        return $out;
    }

    private function paragraph(DOMElement $p): string
    {
        $inner = $this->inline($p);

        if (trim(strip_tags($inner)) === '' && !str_contains($inner, '<img')) {
            return '';
        }

        $style = '';

        foreach ($p->getElementsByTagNameNS(self::W_NS, 'pStyle') as $s) {
            /** @var DOMElement $s */
            $style = strtolower($s->getAttribute('w:val') ?: $s->getAttributeNS(self::W_NS, 'val'));
            break;
        }

        // Word heading levels become h3..h6: the page already owns h1 and h2,
        // so starting at h3 keeps the document's structure nested inside the
        // page's rather than competing with it.
        if (preg_match('~^heading([1-9])~', $style, $m) === 1) {
            $level = min(6, 2 + (int) $m[1]);

            return sprintf('<h%d class="dv-h">%s</h%d>', $level, $inner, $level);
        }

        if ($style === 'title') {
            return '<h3 class="dv-title">' . $inner . '</h3>';
        }

        $isList = $p->getElementsByTagNameNS(self::W_NS, 'numPr')->length > 0;
        $class = $isList ? 'dv-p dv-li' : 'dv-p';

        return '<p class="' . $class . '">' . $inner . '</p>';
    }

    private function table(DOMElement $tbl): string
    {
        $rows = '';

        foreach ($tbl->childNodes as $tr) {
            if (!$tr instanceof DOMElement || $tr->localName !== 'tr') {
                continue;
            }

            if ($this->nodeBudget-- <= 0) {
                $this->truncated = true;
                break;
            }

            $cells = '';

            foreach ($tr->childNodes as $tc) {
                if (!$tc instanceof DOMElement || $tc->localName !== 'tc') {
                    continue;
                }

                $span = 1;
                $skip = false;

                foreach ($tc->getElementsByTagNameNS(self::W_NS, 'gridSpan') as $gs) {
                    /** @var DOMElement $gs */
                    $span = max(1, (int) ($gs->getAttribute('w:val') ?: $gs->getAttributeNS(self::W_NS, 'val')));
                    break;
                }

                // A vertically merged continuation cell has no content of its
                // own; rendering it would push the real cells out of line.
                foreach ($tc->getElementsByTagNameNS(self::W_NS, 'vMerge') as $vm) {
                    /** @var DOMElement $vm */
                    $val = $vm->getAttribute('w:val') ?: $vm->getAttributeNS(self::W_NS, 'val');
                    if ($val === '' || strtolower($val) === 'continue') {
                        $skip = true;
                    }
                    break;
                }

                if ($skip) {
                    continue;
                }

                $content = $this->blocks($tc);
                $attr = $span > 1 ? ' colspan="' . $span . '"' : '';
                $cells .= '<td' . $attr . '>' . ($content !== '' ? $content : '&nbsp;') . '</td>';
            }

            if ($cells !== '') {
                $rows .= '<tr>' . $cells . '</tr>';
            }
        }

        if ($rows === '') {
            return '';
        }

        // Its own scroll container: a wide table must not force the whole page
        // sideways on a narrow screen.
        return '<div class="dv-table-wrap"><table class="dv-table">' . $rows . '</table></div>';
    }

    /** Runs, hyperlinks and images inside a paragraph. */
    private function inline(DOMNode $parent): string
    {
        $out = '';

        foreach ($parent->childNodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }

            if ($node->localName === 'r') {
                $out .= $this->run_($node);
            } elseif ($node->localName === 'hyperlink') {
                // Rendered as plain emphasis, never as an <a href>: the target
                // comes from the uploaded file and this page will not turn a
                // user-supplied string into a live link.
                $inner = $this->inline($node);
                if ($inner !== '') {
                    $out .= '<span class="dv-link">' . $inner . '</span>';
                }
            } elseif (in_array($node->localName, ['smartTag', 'sdtContent', 'ins'], true)) {
                $out .= $this->inline($node);
            }
        }

        return $out;
    }

    private function run_(DOMElement $r): string
    {
        $bold = false;
        $italic = false;
        $underline = false;

        foreach ($r->getElementsByTagNameNS(self::W_NS, 'rPr') as $rPr) {
            foreach ($rPr->childNodes as $prop) {
                if (!$prop instanceof DOMElement) {
                    continue;
                }
                if ($prop->localName === 'b') {
                    $bold = self::onOff($prop);
                } elseif ($prop->localName === 'i') {
                    $italic = self::onOff($prop);
                } elseif ($prop->localName === 'u') {
                    $val = $prop->getAttribute('w:val') ?: $prop->getAttributeNS(self::W_NS, 'val');
                    $underline = $val !== '' && strtolower($val) !== 'none';
                }
            }
            break;
        }

        $text = '';

        foreach ($r->childNodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }

            switch ($node->localName) {
                case 't':
                    // The ONLY place document text enters the output, and it is
                    // escaped on the way in.
                    $text .= htmlspecialchars($node->textContent, ENT_QUOTES, 'UTF-8');
                    break;
                case 'tab':
                    $text .= '<span class="dv-tab"></span>';
                    break;
                case 'br':
                    $text .= '<br>';
                    break;
                case 'drawing':
                case 'pict':
                    $text .= $this->image($node);
                    break;
            }
        }

        if ($text === '') {
            return '';
        }

        if ($underline) {
            $text = '<u>' . $text . '</u>';
        }
        if ($italic) {
            $text = '<em>' . $text . '</em>';
        }
        if ($bold) {
            $text = '<strong>' . $text . '</strong>';
        }

        return $text;
    }

    /** `<w:b/>` means on; `<w:b w:val="0"/>` means off. */
    private static function onOff(DOMElement $el): bool
    {
        $val = $el->getAttribute('w:val') ?: $el->getAttributeNS(self::W_NS, 'val');

        return !in_array(strtolower($val), ['0', 'false', 'off'], true);
    }

    private function image(DOMElement $node): string
    {
        foreach ($node->getElementsByTagNameNS('http://schemas.openxmlformats.org/drawingml/2006/main', 'blip') as $blip) {
            /** @var DOMElement $blip */
            $rid = $blip->getAttributeNS(self::R_NS, 'embed');

            if ($rid === '' || !isset($this->images[$rid])) {
                continue;
            }

            // The id is checked against the map above, so only a relationship
            // this document actually declares can reach the URL — and it is
            // rawurlencoded regardless.
            return sprintf(
                '<img class="dv-img" src="%s" alt="" loading="lazy">',
                htmlspecialchars(url('/documents/' . $this->fileId . '/media/' . rawurlencode($rid)), ENT_QUOTES)
            );
        }

        return '';
    }
}
