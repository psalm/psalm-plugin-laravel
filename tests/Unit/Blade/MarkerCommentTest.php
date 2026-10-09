<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Blade\MarkerComment;

#[CoversClass(MarkerComment::class)]
final class MarkerCommentTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function adversarialSources(): iterable
    {
        yield 'plain' => ["<div>{{ \$x }}</div>\n"];
        yield 'names the prefix stem' => ["@php\n\$x = 'blade:';\n@endphp\n"];
        yield 'embeds a marker comment' => ["@php\n/* blade:2 */ \$x = 1;\n@endphp\n"];
        yield 'embeds another template prefix' => ['x' . MarkerComment::prefixFor("<div>x</div>\n") . "1\n"];
    }

    /**
     * The invariant {@see MarkerComment::strip()} rests on, and the one the line map rests on in the
     * other direction: nothing an author wrote can BE the prefix. The `while` loop inside
     * `prefixFor()` is what makes it total — a source that happened to contain its own hash would
     * otherwise break it, which is a fixed point no test can construct.
     */
    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('adversarialSources')]
    public function a_prefix_is_absent_from_the_source_it_was_derived_from(string $source): void
    {
        $this->assertStringNotContainsString(MarkerComment::prefixFor($source), $source);
    }

    #[Test]
    public function stripping_removes_markers_and_leaves_author_comments_alone(): void
    {
        $source = "@php\nstrlen(\n  '/* blade:2 */',\n);\n@endphp\n";
        $prefix = MarkerComment::prefixFor($source);

        $this->assertSame(
            "strlen(\n  '/* blade:2 */',\n)",
            MarkerComment::strip("strlen(\n  /* {$prefix}3 */ '/* blade:2 */',\n)", $prefix),
        );
    }

    #[Test]
    public function only_a_whole_marker_comment_token_reads_back_as_a_source_line(): void
    {
        $this->assertSame(7, MarkerComment::sourceLine([\T_COMMENT, '/* blade:7 */', 1], 'blade:'));
        $this->assertNull(MarkerComment::sourceLine([\T_COMMENT, '/* blade:7 */ trailing', 1], 'blade:'));
        $this->assertNull(MarkerComment::sourceLine([\T_COMMENT, '/* blade:seven */', 1], 'blade:'));
        $this->assertNull(MarkerComment::sourceLine([\T_DOC_COMMENT, '/* blade:7 */', 1], 'blade:'));
        $this->assertNull(MarkerComment::sourceLine('(', 'blade:'));
    }
}
