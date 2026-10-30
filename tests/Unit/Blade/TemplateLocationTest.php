<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Blade\TemplateLocation;

#[CoversClass(TemplateLocation::class)]
final class TemplateLocationTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function lineEndings(): iterable
    {
        yield 'LF' => ["\n"];
        yield 'bare CR' => ["\r"];
    }

    #[Test]
    #[DataProvider('lineEndings')]
    public function locates_the_requested_line_under_every_line_ending(string $eol): void
    {
        $source = 'first' . $eol . 'second' . $eol . 'third';

        $location = TemplateLocation::atLine('/app/view.blade.php', 'view.blade.php', $source, 2);

        $this->assertNotNull($location);
        $this->assertSame(
            'second',
            \substr($source, $location->raw_file_start, $location->raw_file_end - $location->raw_file_start + 1),
        );
    }

    #[Test]
    public function skips_leading_and_trailing_horizontal_whitespace(): void
    {
        $source = "<div>\n    \t<input id=\"{{ strtolower(\$shape) }}\">  \t\n</div>";

        $location = TemplateLocation::atLine('/app/view.blade.php', 'view.blade.php', $source, 2);

        $this->assertNotNull($location);
        $this->assertSame('<', $source[$location->raw_file_start]);
        $this->assertSame(
            '<input id="{{ strtolower($shape) }}">',
            \substr($source, $location->raw_file_start, $location->raw_file_end - $location->raw_file_start + 1),
        );
    }

    #[Test]
    public function keeps_the_whole_line_when_it_is_blank(): void
    {
        $source = "a\n \t \nb";

        $location = TemplateLocation::atLine('/app/view.blade.php', 'view.blade.php', $source, 2);

        $this->assertNotNull($location);
        $this->assertSame(
            " \t ",
            \substr($source, $location->raw_file_start, $location->raw_file_end - $location->raw_file_start + 1),
        );
    }

    #[Test]
    public function returns_null_past_the_last_line(): void
    {
        $this->assertNull(TemplateLocation::atLine('/app/view.blade.php', 'view.blade.php', "only\n", 3));
    }
}
