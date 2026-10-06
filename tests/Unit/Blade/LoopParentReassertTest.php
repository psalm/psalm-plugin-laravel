<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Blade\LoopParentReassert;
use Psalm\LaravelPlugin\Blade\PreludeBuilder;

#[CoversClass(LoopParentReassert::class)]
final class LoopParentReassertTest extends TestCase
{
    /** `CompilesLoops::compileForeach()`'s tail, verified against a real compile. */
    private const PUSH = '$__env->incrementLoopIndices(); $loop = $__env->getLastLoop();';

    /** `CompilesLoops::compileEndforeach()`'s tail. */
    private const POP = '$__env->popLoop(); $loop = $__env->getLastLoop();';

    private function frame(string $parent): string
    {
        return '\stdClass&object{' . PreludeBuilder::LOOP_FIELDS . ', parent: ' . $parent . '}';
    }

    private function reassert(string $parent): string
    {
        return ' /** @var ' . $this->frame($parent) . ' $loop */';
    }

    private function compile(string $source): string
    {
        return (new BladeCompiler(new Filesystem(), \sys_get_temp_dir()))->compileString($source);
    }

    #[Test]
    public function a_single_loop_is_left_alone(): void
    {
        $compiled = '<?php foreach($a as $x): ' . self::PUSH . " ?>\n<?php endforeach; " . self::POP . " ?>\n";

        $this->assertSame($compiled, LoopParentReassert::apply($compiled));
    }

    #[Test]
    public function the_push_at_depth_two_gets_a_depth_two_frame_on_the_same_line(): void
    {
        $compiled = '<?php foreach($a as $x): ' . self::PUSH . " ?>\n"
            . '<?php foreach($b as $y): ' . self::PUSH . " ?>\n"
            . '<?php endforeach; ' . self::POP . " ?>\n"
            . '<?php endforeach; ' . self::POP . " ?>\n";

        $applied = LoopParentReassert::apply($compiled);

        $this->assertStringContainsString(
            self::PUSH . $this->reassert($this->frame('object|null')) . " ?>\n<?php endforeach;",
            $applied,
        );
        $this->assertSame(\substr_count($compiled, "\n"), \substr_count($applied, "\n"));
        $this->assertSame(\substr_count($compiled, '<?php'), \substr_count($applied, '<?php'));
    }

    /** A read after an inner `@endforeach` still sees the pop's own `$loop = getLastLoop()` reset. */
    #[Test]
    public function the_pop_back_to_depth_two_is_reasserted_but_the_pop_to_depth_one_is_not(): void
    {
        $compiled = '<?php foreach($a as $x): ' . self::PUSH . " ?>\n"
            . '<?php foreach($b as $y): ' . self::PUSH . " ?>\n"
            . '<?php foreach($c as $z): ' . self::PUSH . " ?>\n"
            . '<?php endforeach; ' . self::POP . " ?>\n"
            . '<?php endforeach; ' . self::POP . " ?>\n"
            . '<?php endforeach; ' . self::POP . " ?>\n";

        $applied = LoopParentReassert::apply($compiled);
        $depthTwo = $this->reassert($this->frame('object|null'));
        $depthThree = $this->reassert($this->frame($this->frame('object|null')));

        $this->assertSame(1, \substr_count($applied, self::POP . $depthTwo));
        $this->assertSame(1, \substr_count($applied, self::PUSH . $depthThree));
        $this->assertSame(3, \substr_count($applied, '@var'));
    }

    #[Test]
    public function a_real_nested_forelse_is_reasserted_after_the_push_and_the_bare_empty_pop(): void
    {
        $compiled = $this->compile("@forelse (\$a as \$x)\n@forelse (\$x as \$y)\n@forelse (\$y as \$z)\n{{ \$z }}\n@empty\n{{ \$loop->parent->iteration }}\n@endforelse\n@empty\n@endforelse\n@empty\n@endforelse\n");

        $applied = LoopParentReassert::apply($compiled);

        // Depth 2 push, depth 3 push, depth 3 -> 2 pop at the innermost bare @empty.
        $this->assertSame(3, \substr_count($applied, '@var'));
        $this->assertStringContainsString(
            self::PUSH . $this->reassert($this->frame('object|null')) . ' $__empty_',
            $applied,
        );
        $this->assertStringContainsString(
            self::POP . $this->reassert($this->frame('object|null')) . ' if ($__empty_',
            $applied,
        );
    }

    #[Test]
    public function an_empty_with_an_argument_is_not_a_pop(): void
    {
        $compiled = $this->compile("@foreach (\$a as \$x)\n@foreach (\$x as \$y)\n@if (\$y)\n@empty(\$y)\n@endif\n@endforeach\n@endforeach\n");

        // `@empty($y)` compiles to `if(empty(...))`, so depth ends at zero and only the inner push is asserted.
        $this->assertSame(1, \substr_count(LoopParentReassert::apply($compiled), '@var'));
    }

    #[Test]
    public function a_for_loop_does_not_count_as_a_loop_frame(): void
    {
        $compiled = $this->compile("@for (\$i = 0; \$i < 2; \$i++)\n@foreach (\$a as \$x)\n{{ \$x }}\n@endforeach\n@endfor\n");

        $this->assertSame($compiled, LoopParentReassert::apply($compiled));
    }

    #[Test]
    public function the_nested_type_is_capped(): void
    {
        $source = '';
        for ($i = 0; $i < 12; $i++) {
            $source .= "@foreach (\$a{$i} as \$x{$i})\n";
        }

        $source .= \str_repeat("@endforeach\n", 12);
        $applied = LoopParentReassert::apply($this->compile($source));

        \preg_match_all('~/\*\* @var (.+?) \$loop \*/~', $applied, $docblocks);

        // Depth 12 asserts a frame nested 8 deep, not 12.
        $this->assertSame(8, \max(\array_map(
            static fn(string $type): int => \substr_count($type, '\stdClass&object{'),
            $docblocks[1],
        )));
    }

    /**
     * Real compiler output only: a PHP comment, a string, or inline text that spells the same
     * statements is the author's, and the suffix's own comment closer would corrupt it.
     */
    #[Test]
    public function matches_inside_a_comment_or_a_string_are_ignored(): void
    {
        // Each fake region holds a push AND its pop, so an ungated pass would stay balanced and rewrite it.
        $pair = self::PUSH . ' ' . self::POP;
        $compiled = '<?php foreach($a as $x): ' . self::PUSH . " ?>\n"
            . "<?php /* {$pair} */ ?>\n"
            . "<?php // {$pair}\n ?>\n"
            . "<?php \$s = '{$pair}'; \$t = \"{$pair}\"; ?>\n"
            . "{$pair}\n"
            . '<?php endforeach; ' . self::POP . " ?>\n";

        $this->assertSame($compiled, LoopParentReassert::apply($compiled));
    }

    #[Test]
    public function a_comment_embedded_match_does_not_skew_the_depth_of_real_ones(): void
    {
        $compiled = '<?php foreach($a as $x): ' . self::PUSH . " ?>\n"
            . '<?php /* ' . self::PUSH . " */ ?>\n"
            . '<?php foreach($b as $y): ' . self::PUSH . " ?>\n"
            . '<?php endforeach; ' . self::POP . " ?>\n"
            . '<?php endforeach; ' . self::POP . " ?>\n";

        $applied = LoopParentReassert::apply($compiled);

        $this->assertSame(1, \substr_count($applied, '@var'));
        $this->assertStringContainsString('*/ ?>', $applied);
    }

    #[Test]
    public function an_unclosed_loop_is_returned_unchanged(): void
    {
        $compiled = '<?php foreach($a as $x): ' . self::PUSH . " ?>\n<?php foreach(\$b as \$y): " . self::PUSH . " ?>\n";

        $this->assertSame($compiled, LoopParentReassert::apply($compiled));
    }

    #[Test]
    public function a_pop_without_a_push_is_returned_unchanged(): void
    {
        $compiled = '<?php endforeach; ' . self::POP . " ?>\n<?php foreach(\$a as \$x): " . self::PUSH . " ?>\n<?php foreach(\$b as \$y): " . self::PUSH . " ?>\n";

        $this->assertSame($compiled, LoopParentReassert::apply($compiled));
    }

    #[Test]
    public function content_without_loops_is_returned_unchanged(): void
    {
        $source = "<?php echo e(\$name); ?>\n";

        $this->assertSame($source, LoopParentReassert::apply($source));
    }
}
