<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Blade\LineMapBuilder;

#[CoversClass(LineMapBuilder::class)]
final class LineMapBuilderTest extends TestCase
{
    #[Test]
    public function prelude_lines_map_to_zero(): void
    {
        $content = "prelude line 1\nprelude line 2\n<?php /* blade:1 */ ?>real\n";

        $map = LineMapBuilder::build($content, preludeLines: 2);

        $this->assertSame(0, $map[1]);
        $this->assertSame(0, $map[2]);
        $this->assertSame(1, $map[3]);
    }

    #[Test]
    public function marker_carries_forward_across_unmarked_lines(): void
    {
        $content = "<?php /* blade:5 */ ?>one\ntwo\nthree\n";

        $map = LineMapBuilder::build($content);

        $this->assertSame(5, $map[1]);
        $this->assertSame(5, $map[2]);
        $this->assertSame(5, $map[3]);
    }

    #[Test]
    public function last_marker_on_a_line_wins(): void
    {
        $content = "<?php /* blade:1 */ ?>foo<?php /* blade:9 */ ?>\n";

        $map = LineMapBuilder::build($content);

        $this->assertSame(9, $map[1]);
    }

    #[Test]
    public function no_prelude_lines_still_maps_from_line_one(): void
    {
        $content = "<?php /* blade:3 */ ?>hi\n";

        $map = LineMapBuilder::build($content, preludeLines: 0);

        $this->assertSame(3, $map[1]);
    }
}
