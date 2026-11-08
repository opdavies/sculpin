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

namespace Sculpin\Core\Tests\Output;

use PHPUnit\Framework\TestCase;
use Sculpin\Core\Output\HtmlMinifier;

final class HtmlMinifierTest extends TestCase
{
    private HtmlMinifier $minifier;

    protected function setUp(): void
    {
        $this->minifier = new HtmlMinifier();
    }

    public function testCollapsesWhitespaceAndTrimsAtBlockBoundaries(): void
    {
        $html = "<div>\n    <p>Two   spaces</p>\n  </div>\n";

        $this->assertSame('<div><p>Two spaces</p></div>', $this->minifier->minify($html));
    }

    public function testKeepsWhitespaceBetweenInlineElements(): void
    {
        $html = '<span>Alpha</span> <span>Beta</span>';

        $this->assertSame($html, $this->minifier->minify($html));
    }

    public function testRemovesRegularComments(): void
    {
        $html = "<div>\n  <!-- internal note -->\n  <p>content</p>\n</div>";

        $this->assertSame('<div><p>content</p></div>', $this->minifier->minify($html));
    }

    public function testKeepsConditionalCommentsVerbatim(): void
    {
        $html = '<div><!--[if IE]><p>legacy</p><![endif]--></div>';

        $this->assertSame($html, $this->minifier->minify($html));
    }

    public function testKeepsPreformattedContentVerbatim(): void
    {
        $html = "<pre>\n  one   two\n</pre>";

        $this->assertSame($html, $this->minifier->minify($html));
    }

    public function testKeepsScriptContentVerbatim(): void
    {
        $html = "<body>\n<script>\n  var x = 1;  // comment   here\n</script>\n</body>";

        $this->assertSame(
            "<body><script>\n  var x = 1;  // comment   here\n</script></body>",
            $this->minifier->minify($html)
        );
    }

    public function testCollapsesWhitespaceInAttributes(): void
    {
        $html = "<a href=\"/x\"  title=\"A   B\">\n  Link\n</a>";

        $this->assertSame(
            "<a href=\"/x\" title=\"A B\"> Link\n</a>",
            $this->minifier->minify($html)
        );
    }

    public function testCollapsesListItems(): void
    {
        $html = "<ul>\n  <li>\n    A\n  </li>\n  <li> B  C </li>\n</ul>";

        $this->assertSame(
            '<ul><li>A</li><li>B C</li></ul>',
            $this->minifier->minify($html)
        );
    }
}
