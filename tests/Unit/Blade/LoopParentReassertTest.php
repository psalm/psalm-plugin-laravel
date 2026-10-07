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

    /** A whole compiled `@foreach` opener (after the `<?php `), `CompilesLoops::compileForeach()`. */
    private function push(string $list, string $alias): string
    {
        return '$__currentLoopData = ' . $list . '; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as ' . $alias . '): ' . self::PUSH;
    }

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
        $compiled = '<?php ' . $this->push('$a', '$x') . " ?>\n<?php endforeach; " . self::POP . " ?>\n";

        $this->assertSame($compiled, LoopParentReassert::apply($compiled));
    }

    #[Test]
    public function the_push_at_depth_two_gets_a_depth_two_frame_on_the_same_line(): void
    {
        $compiled = '<?php ' . $this->push('$a', '$x') . " ?>\n"
            . '<?php ' . $this->push('$b', '$y') . " ?>\n"
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
        $compiled = '<?php ' . $this->push('$a', '$x') . " ?>\n"
            . '<?php ' . $this->push('$b', '$y') . " ?>\n"
            . '<?php ' . $this->push('$c', '$z') . " ?>\n"
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
        $compiled = '<?php ' . $this->push('$a', '$x') . " ?>\n"
            . "<?php /* {$pair} */ ?>\n"
            . "<?php // {$pair}\n ?>\n"
            . "<?php \$s = '{$pair}'; \$t = \"{$pair}\"; ?>\n"
            . "{$pair}\n"
            . '<?php endforeach; ' . self::POP . " ?>\n";

        $this->assertSame($compiled, LoopParentReassert::apply($compiled));
    }

    /** A heredoc BEFORE a nested pair does not hide the real loops that follow it. */
    #[Test]
    public function a_preceding_heredoc_does_not_hide_the_real_nested_loops(): void
    {
        $compiled = "<?php \$h = <<<EOT\nplain \$text\nEOT;\n?>\n"
            . '<?php ' . $this->push('$a', '$x') . " ?>\n"
            . '<?php ' . $this->push('$b', '$y') . " ?>\n"
            . '<?php endforeach; ' . self::POP . " ?>\n"
            . '<?php endforeach; ' . self::POP . " ?>\n";

        $this->assertSame(1, \substr_count(LoopParentReassert::apply($compiled), '@var'));
    }

    #[Test]
    public function a_heredoc_or_backtick_string_containing_the_pattern_is_ignored(): void
    {
        $pair = self::PUSH . ' ' . self::POP;
        $compiled = '<?php ' . $this->push('$a', '$x') . " ?>\n"
            . "<?php \$h = <<<EOT\n{$pair}\nEOT;\n\$s = `{$pair}`; ?>\n"
            . '<?php endforeach; ' . self::POP . " ?>\n";

        $this->assertSame($compiled, LoopParentReassert::apply($compiled));
    }

    /** Likewise a preceding backtick string. */
    #[Test]
    public function a_preceding_backtick_string_does_not_hide_the_real_nested_loops(): void
    {
        $compiled = "<?php \$s = `ls`; ?>\n"
            . '<?php ' . $this->push('$a', '$x') . " ?>\n"
            . '<?php ' . $this->push('$b', '$y') . " ?>\n"
            . '<?php endforeach; ' . self::POP . " ?>\n"
            . '<?php endforeach; ' . self::POP . " ?>\n";

        $this->assertSame(1, \substr_count(LoopParentReassert::apply($compiled), '@var'));
    }

    /** Sync lock: the stub cannot reference the PHP constant, so pin that the two spellings agree. */
    #[Test]
    public function the_stub_return_type_spells_the_same_loop_fields(): void
    {
        $stub = (string) \file_get_contents(__DIR__ . '/../../../stubs/common/View/Concerns/ManagesLoops.phpstub');

        $this->assertStringContainsString(
            '@return ' . self::frame('object|null'),
            $stub,
        );
    }

    /** Codex review P2: author PHP that merely CONTAINS the statements, behind a branch, never runs them. */
    #[Test]
    public function author_php_that_imitates_the_statements_behind_a_branch_is_ignored(): void
    {
        $compiled = "<?php\nif (false) { " . self::PUSH . " }\n?>\n"
            . '<?php ' . $this->push('$a', '$x') . " ?>\n"
            . '<?php endforeach; ' . self::POP . " ?>\n"
            . "<?php\nif (false) { " . self::POP . " }\n?>\n";

        $this->assertStringNotContainsString('@var', LoopParentReassert::apply($compiled));
    }

    /** Codex review P2: nested interpolated strings defeat any single quote toggle; provenance does not care. */
    #[Test]
    public function statements_inside_nested_interpolated_strings_are_ignored(): void
    {
        $nested = '$s = "{$f->format("' . self::PUSH . '")}"; $t = "{$f->format("' . self::POP . '")}";';
        $compiled = '<?php ' . $this->push('$a', '$x') . " ?>\n"
            . "<?php {$nested} ?>\n"
            . '<?php endforeach; ' . self::POP . " ?>\n";

        $this->assertSame($compiled, LoopParentReassert::apply($compiled));
    }

    /** The loop expression and alias are author code: semicolons, nesting and strings in them must not end the match early. */
    #[Test]
    public function an_awkward_loop_expression_and_alias_still_count(): void
    {
        $compiled = self::compile(
            "@foreach (\$a as \$x)\n"
            . "@foreach (array_map(function (\$i) { \$j = ';)'; return \$i; }, \$x) as [\$k, \$v])\n"
            . "@foreach (\"{\$x['a']}\" as &\$w)\n@endforeach\n@endforeach\n@endforeach\n",
        );

        // Push at depth 2 and 3, pop back to depth 2.
        $this->assertSame(3, \substr_count(LoopParentReassert::apply($compiled), '@var'));
    }

    #[Test]
    public function a_comment_embedded_match_does_not_skew_the_depth_of_real_ones(): void
    {
        $compiled = '<?php ' . $this->push('$a', '$x') . " ?>\n"
            . '<?php /* ' . self::PUSH . " */ ?>\n"
            . '<?php ' . $this->push('$b', '$y') . " ?>\n"
            . '<?php endforeach; ' . self::POP . " ?>\n"
            . '<?php endforeach; ' . self::POP . " ?>\n";

        $applied = LoopParentReassert::apply($compiled);

        $this->assertSame(1, \substr_count($applied, '@var'));
        $this->assertStringContainsString('*/ ?>', $applied);
    }

    #[Test]
    public function an_unclosed_loop_is_returned_unchanged(): void
    {
        $compiled = '<?php ' . $this->push('$a', '$x') . " ?>\n<?php " . $this->push('$b', '$y') . " ?>\n";

        $this->assertSame($compiled, LoopParentReassert::apply($compiled));
    }

    #[Test]
    public function a_pop_without_a_push_is_returned_unchanged(): void
    {
        $compiled = '<?php endforeach; ' . self::POP . " ?>\n<?php " . $this->push('$a', '$x') . " ?>\n<?php " . $this->push('$b', '$y') . " ?>\n";

        $this->assertSame($compiled, LoopParentReassert::apply($compiled));
    }

    #[Test]
    public function content_without_loops_is_returned_unchanged(): void
    {
        $source = "<?php echo e(\$name); ?>\n";

        $this->assertSame($source, LoopParentReassert::apply($source));
    }
}
