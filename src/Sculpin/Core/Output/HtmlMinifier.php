<?php

declare(strict_types=1);

/*
 * This file is a part of Sculpin.
 *
 * (c) Dragonfly Development Inc.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Sculpin\Core\Output;

/**
 * A conservative, dependency-free HTML minifier for generated documents.
 *
 * The transformations are chosen so that rendering is unaffected:
 *
 * - runs of two or more whitespace characters collapse to one space, and
 * - whitespace that abuts a block-level element's opening or closing tag
 *   is removed, because it is not rendered there: collapsible spaces at
 *   the start or end of a line box are dropped by the browser.
 *
 * Whitespace between inline-level elements is deliberately kept as a
 * single space, because that space is rendered and may be meaningful.
 *
 * The contents of `script`, `style`, `pre` and `textarea` elements are
 * protected verbatim - their whitespace is significant - as are
 * conditional comments and any comment that carries markup. Elements are
 * matched with regular expressions rather than a DOM parser to keep the
 * minifier usable inside generate runs without introducing
 * libxml-dependent edge cases; the known limitation is that a `>` inside
 * a quoted attribute value can confuse tag recognition, which is
 * vanishingly rare in generated output.
 */
final class HtmlMinifier
{
    /**
     * Element names at whose boundaries rendered whitespace collapses to
     * nothing, including void elements that end a text line (br, hr) and
     * non-rendering structural elements (base, meta, link, title,
     * template, noscript).
     *
     * @see https://html.spec.whatwg.org/multipage/rendering.html#invisible-and-immediate-user-facing-whitespace
     */
    private const string BLOCK_TAGS = 'base|blockquote|body|br|caption|col'
        .'|colgroup|dd|details|div|dl|dt|fieldset|figcaption|figure|footer'
        .'|form|address|article|aside|header|main|nav|section|h[1-6]|head'
        .'|hr|html|li|link|meta|noscript|ol|p|summary|table|tbody|td'
        .'|template|tfoot|th|thead|title|tr|ul';

    private const string PROTECTED_ELEMENTS = 'pre|textarea|script|style';

    /**
     * @param array<int, string>
     */
    private array $protected = [];

    public function minify(string $html): string
    {
        if (!str_contains($html, '<')) {
            return $html;
        }

        $this->protected = [];

        // Conditional comments carry markup and may nest other elements;
        // protect them before anything else touches them.
        $html = preg_replace_callback(
            '/<!--\[if[^\]>]*\]>.*?<!\[endif\]-->/s',
            fn (array $match): string => $this->protect($match[0]),
            $html
        ) ?? $html;

        // The contents of these elements are whitespace-sensitive and are
        // executed or displayed verbatim; protect the contents while the
        // tags themselves stay in the flow, so block-boundary trimming
        // still applies around them.
        $html = preg_replace_callback(
            '#(<('.self::PROTECTED_ELEMENTS.')\\b[^>]*>)(.*?)(</\\2\\s*>)#is',
            fn (array $match): string => $match[1].$this->protect($match[3]).$match[4],
            $html
        ) ?? $html;

        // Remove HTML comments. Anything that has to survive has already
        // been protected above.
        $html = preg_replace('/<!--.*?-->/s', '', $html) ?? $html;

        // Collapse runs of two or more whitespace characters - including
        // the newlines and indentation the templates emit - to one space.
        $html = trim((string) preg_replace('/\s{2,}/s', ' ', $html));

        $block = '(?:'.self::BLOCK_TAGS.')';

        // Whitespace immediately before a block-level opening or closing
        // tag ends a line box, and whitespace immediately after one starts
        // it; neither is rendered.
        $html = (string) preg_replace(
            '/\s+(<\/?'.$block.'(?:\s[^<>]*)?\s*\/?>)/i',
            '\1',
            $html
        );

        $html = (string) preg_replace(
            '/(<\/?'.$block.'(?:\s[^<>]*)?\s*\/?>)\s+/i',
            '\1',
            $html
        );

        foreach ($this->protected as $index => $chunk) {
            $html = str_replace("\x1A".$index."\x1A", $chunk, $html);
        }

        return $html;
    }

    /**
     * Replace a chunk of the document with a placeholder token so that
     * later passes cannot touch its contents.
     */
    private function protect(string $chunk): string
    {
        $this->protected[] = $chunk;

        return "\x1A".(count($this->protected) - 1)."\x1A";
    }
}
