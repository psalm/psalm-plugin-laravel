<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Blade\TemplateSnippetMatcher;

#[CoversClass(TemplateSnippetMatcher::class)]
final class TemplateSnippetMatcherTest extends TestCase
{
    #[Test]
    public function an_identical_snippet_occurs(): void
    {
        $this->assertTrue(TemplateSnippetMatcher::occursIn(
            "foo('a', 'b')",
            "<div>\n  foo('a', 'b')\n</div>\n",
        ));
    }

    #[Test]
    public function differing_line_breaks_between_arguments_still_occur(): void
    {
        // A generated block reformats an argument list onto its own lines; the comparison must
        // ignore that rather than requiring byte-identical whitespace.
        $this->assertTrue(TemplateSnippetMatcher::occursIn(
            "foo('a',\n'b',\n'c')",
            "<div>\n  foo('a', 'b', 'c')\n</div>\n",
        ));
    }

    #[Test]
    public function a_multi_line_snippet_is_found_against_the_whole_source_not_one_line(): void
    {
        // Guards the "match the whole source, never one line" rule directly: a per-line
        // comparison would find neither half of a snippet split across two template lines.
        $snippet = "foo('a',\n  'b')";
        $source = "<div>\n  foo('a',\n  'b')\n</div>\n";

        $this->assertTrue(TemplateSnippetMatcher::occursIn($snippet, $source));
    }

    #[Test]
    public function an_absent_snippet_does_not_occur(): void
    {
        $this->assertFalse(TemplateSnippetMatcher::occursIn(
            "bar('a', 'b', 'c')",
            "<div>\n  foo('a', 'b')\n</div>\n",
        ));
    }
}
