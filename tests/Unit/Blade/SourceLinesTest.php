<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Blade\SourceLines;

#[CoversClass(SourceLines::class)]
final class SourceLinesTest extends TestCase
{
    #[Test]
    public function lf_only_source_splits_on_every_newline(): void
    {
        $this->assertSame(["a\n", "b\n", "c"], SourceLines::split("a\nb\nc"));
    }

    #[Test]
    public function crlf_only_source_keeps_each_pair_with_its_line(): void
    {
        $this->assertSame(["a\r\n", "b\r\n", "c"], SourceLines::split("a\r\nb\r\nc"));
    }

    /**
     * #1545 review: `token_get_all()`/PHP-Parser's own lexer counts a bare `\r` (not part of a
     * `\r\n` pair) as a line terminator — the SAME way `LineMapBuilder::build()` reads line numbers
     * off `token_get_all()`. Splitting only on `\n` under-counts these lines, so a shadow line
     * number computed from the split falls behind the token's own line number and the map ends
     * early; anything past that point reads as unmapped.
     */
    #[Test]
    public function bare_cr_source_splits_like_php_counts_lines(): void
    {
        $this->assertSame(["a\r", "b\r", "c"], SourceLines::split("a\rb\rc"));
    }

    #[Test]
    public function mixed_line_endings_each_split_on_their_own_terminator(): void
    {
        $this->assertSame(["a\r\n", "b\r", "c\n", "d"], SourceLines::split("a\r\nb\rc\nd"));
    }

    /**
     * @return \Iterator<int<0, max>, string> every case exercised by {@see self::every_split_round_trips()}
     */
    public static function sourceProvider(): \Iterator
    {
        yield 'lf' => ["a\nb\nc\n"];
        yield 'crlf' => ["a\r\nb\r\nc\r\n"];
        yield 'cr' => ["a\rb\rc\r"];
        yield 'mixed' => ["a\r\nb\rc\nd"];
        yield 'empty' => [''];
        yield 'no trailing newline' => ['a'];
    }

    /** Marker injection, line mapping and suppression injection all rewrite lines in place and rely on this. */
    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('sourceProvider')]
    public function every_split_round_trips(string $source): void
    {
        $this->assertSame($source, \implode('', SourceLines::split($source)));
    }
}
