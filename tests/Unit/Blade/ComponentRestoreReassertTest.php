<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Blade\ComponentRestoreReassert;

#[CoversClass(ComponentRestoreReassert::class)]
final class ComponentRestoreReassertTest extends TestCase
{
    private const HASH_A = '5194778a3a7b899dcee5619d0610f5cf';

    private const HASH_B = 'd416460033be24881e789c8ed605b837';

    private const DECLARE = '<?php /** @var \App\Widget $component */ ?>';

    /** `CompilesComponents.php:69`'s save, verified against a real compile of a `<x-...>` tag. */
    private function save(string $hash): string
    {
        return "<?php if (isset(\$component)) { \$__componentOriginal{$hash} = \$component; } ?>";
    }

    /** `CompilesComponents.php:103-106`'s restore, same real compile. */
    private function restore(string $hash): string
    {
        return "<?php if (isset(\$__componentOriginal{$hash})): ?>\n"
            . "<?php \$component = \$__componentOriginal{$hash}; ?>\n"
            . "<?php unset(\$__componentOriginal{$hash}); ?>\n"
            . '<?php endif; ?>';
    }

    private function reasserted(string $restore, string $type): string
    {
        return \substr($restore, 0, -\strlen(' ?>')) . " /** @var {$type} \$component */ ?>";
    }

    #[Test]
    public function the_restore_endif_gets_the_declared_type_on_the_same_line(): void
    {
        $compiled = self::DECLARE . "\n" . $this->save(self::HASH_A) . "\nbody\n" . $this->restore(self::HASH_A) . "\nafter";

        $applied = ComponentRestoreReassert::apply($compiled);

        $this->assertSame(
            self::DECLARE . "\n" . $this->save(self::HASH_A) . "\nbody\n"
                . $this->reasserted($this->restore(self::HASH_A), '\App\Widget') . "\nafter",
            $applied,
        );
        $this->assertSame(\substr_count($compiled, "\n"), \substr_count($applied, "\n"));
    }

    /** The compiled restore's `endif; ?>` can be followed by template text on the same line. */
    #[Test]
    public function text_after_the_restore_on_its_line_is_preserved(): void
    {
        $compiled = self::DECLARE . $this->save(self::HASH_A) . $this->restore(self::HASH_A) . '<?php echo e($component->id()); ?>';

        $applied = ComponentRestoreReassert::apply($compiled);

        $this->assertStringEndsWith(
            "endif; /** @var \\App\\Widget \$component */ ?><?php echo e(\$component->id()); ?>",
            $applied,
        );
    }

    #[Test]
    public function the_type_is_emitted_verbatim_including_a_short_name(): void
    {
        $compiled = '<?php /** @var Widget $component */ ?>' . $this->save(self::HASH_A) . $this->restore(self::HASH_A);

        $this->assertStringEndsWith('endif; /** @var Widget $component */ ?>', ComponentRestoreReassert::apply($compiled));
    }

    #[Test]
    public function the_psalm_prefixed_tag_is_carried(): void
    {
        $compiled = '<?php /** @psalm-var \App\Widget $component */ ?>' . $this->save(self::HASH_A) . $this->restore(self::HASH_A);

        $this->assertStringEndsWith('endif; /** @var \App\Widget $component */ ?>', ComponentRestoreReassert::apply($compiled));
    }

    #[Test]
    public function the_nearest_preceding_declaration_wins(): void
    {
        $compiled = self::DECLARE
            . '<?php /** @var \App\Gadget $component */ ?>'
            . $this->save(self::HASH_A) . $this->restore(self::HASH_A);

        $this->assertStringEndsWith('endif; /** @var \App\Gadget $component */ ?>', ComponentRestoreReassert::apply($compiled));
    }

    #[Test]
    public function every_sequential_top_level_tag_is_reasserted(): void
    {
        $compiled = self::DECLARE
            . $this->save(self::HASH_A) . $this->restore(self::HASH_A) . "\n"
            . $this->save(self::HASH_A) . $this->restore(self::HASH_A);

        $this->assertSame(2, \substr_count(ComponentRestoreReassert::apply($compiled), 'endif; /** @var \App\Widget $component */ ?>'));
    }

    #[Test]
    public function without_a_declaration_the_input_is_unchanged(): void
    {
        $compiled = $this->save(self::HASH_A) . $this->restore(self::HASH_A);

        $this->assertSame($compiled, ComponentRestoreReassert::apply($compiled));
    }

    #[Test]
    public function a_declaration_after_the_tag_does_not_apply_to_it(): void
    {
        $compiled = $this->save(self::HASH_A) . $this->restore(self::HASH_A) . self::DECLARE;

        $this->assertSame($compiled, ComponentRestoreReassert::apply($compiled));
    }

    /** At runtime the inner restore hands `$component` back to the OUTER tag, not the declared value. */
    #[Test]
    public function only_the_depth_zero_restore_is_reasserted(): void
    {
        $compiled = self::DECLARE
            . $this->save(self::HASH_A)
            . $this->save(self::HASH_B) . $this->restore(self::HASH_B)
            . $this->restore(self::HASH_A);

        $expected = self::DECLARE
            . $this->save(self::HASH_A)
            . $this->save(self::HASH_B) . $this->restore(self::HASH_B)
            . $this->reasserted($this->restore(self::HASH_A), '\App\Widget');

        $this->assertSame($expected, ComponentRestoreReassert::apply($compiled));
    }

    /** The shared hash makes the inner save overwrite the outer's, so the outer restore never fires. */
    #[Test]
    public function a_tag_whose_hash_is_saved_again_inside_it_is_skipped(): void
    {
        $compiled = self::DECLARE
            . $this->save(self::HASH_A)
            . $this->save(self::HASH_A) . $this->restore(self::HASH_A)
            . $this->restore(self::HASH_A);

        $this->assertSame($compiled, ComponentRestoreReassert::apply($compiled));
    }

    #[Test]
    public function a_sibling_after_a_collided_tag_is_still_reasserted(): void
    {
        $collided = $this->save(self::HASH_A) . $this->save(self::HASH_A) . $this->restore(self::HASH_A) . $this->restore(self::HASH_A);
        $sibling = $this->save(self::HASH_B) . $this->restore(self::HASH_B);

        $applied = ComponentRestoreReassert::apply(self::DECLARE . $collided . $sibling);

        $this->assertSame(
            self::DECLARE . $collided . $this->save(self::HASH_B) . $this->reasserted($this->restore(self::HASH_B), '\App\Widget'),
            $applied,
        );
    }

    #[Test]
    public function a_save_without_a_restore_leaves_the_input_unchanged(): void
    {
        $compiled = self::DECLARE . $this->save(self::HASH_A) . $this->save(self::HASH_B) . $this->restore(self::HASH_B);

        $this->assertSame($compiled, ComponentRestoreReassert::apply($compiled));
    }

    #[Test]
    public function a_restore_without_a_save_leaves_the_input_unchanged(): void
    {
        $compiled = self::DECLARE . $this->restore(self::HASH_A);

        $this->assertSame($compiled, ComponentRestoreReassert::apply($compiled));
    }

    #[Test]
    public function a_restore_closing_a_different_hash_leaves_the_input_unchanged(): void
    {
        $compiled = self::DECLARE . $this->save(self::HASH_A) . $this->restore(self::HASH_B);

        $this->assertSame($compiled, ComponentRestoreReassert::apply($compiled));
    }

    /** One malformed tag fails the whole walk closed: the stack depth of everything after it is unknown. */
    #[Test]
    public function an_unbalanced_tag_disables_reassert_for_the_other_tags_too(): void
    {
        $compiled = self::DECLARE
            . $this->save(self::HASH_A) . $this->restore(self::HASH_A)
            . $this->save(self::HASH_B);

        $this->assertSame($compiled, ComponentRestoreReassert::apply($compiled));
    }

    #[Test]
    public function a_restore_shaped_text_inside_an_author_comment_is_left_alone(): void
    {
        $compiled = self::DECLARE . $this->save(self::HASH_A) . $this->restore(self::HASH_A)
            . '<?php /* ' . $this->restore(self::HASH_B) . " */ ?>\n";

        $applied = ComponentRestoreReassert::apply($compiled);

        // Only the genuine restore got the suffix; the comment is byte-identical, and the forged
        // restore never reaches the stack (a forged one would unbalance it and decline everything).
        $this->assertStringContainsString("endif; /** @var \\App\\Widget \$component */ ?><?php /* ", $applied);
        $this->assertSame(1, \substr_count($applied, 'endif; /** @var'));
        $this->assertStringContainsString('<?php /* ' . $this->restore(self::HASH_B) . ' */ ?>', $applied);
    }

    #[Test]
    public function a_save_shaped_text_inside_an_author_comment_does_not_collide(): void
    {
        $compiled = self::DECLARE
            . $this->save(self::HASH_A)
            . '<?php /* ' . $this->save(self::HASH_A) . ' */ ?>'
            . $this->restore(self::HASH_A);

        $this->assertStringContainsString('endif; /** @var \App\Widget $component */ ?>', ComponentRestoreReassert::apply($compiled));
    }

    /** A declaration inside a tag body describes the child component, not the caller's `$component`. */
    #[Test]
    public function a_declaration_inside_a_tag_body_is_ignored(): void
    {
        $compiled = $this->save(self::HASH_A)
            . '<?php /** @var \App\Widget $component */ ?>'
            . $this->restore(self::HASH_A);

        $this->assertSame($compiled, ComponentRestoreReassert::apply($compiled));
    }

    /** After the tag closes, `$component` is the outer value again, so a later tag still saves the outer declaration. */
    #[Test]
    public function a_declaration_inside_a_body_does_not_replace_the_outer_one_for_a_later_tag(): void
    {
        $body = $this->save(self::HASH_A) . '<?php /** @var \App\Gadget $component */ ?>';
        $second = $this->save(self::HASH_B) . $this->restore(self::HASH_B);

        $applied = ComponentRestoreReassert::apply(self::DECLARE . $body . $this->restore(self::HASH_A) . $second);

        $this->assertSame(
            self::DECLARE . $body . $this->reasserted($this->restore(self::HASH_A), '\App\Widget')
                . $this->save(self::HASH_B) . $this->reasserted($this->restore(self::HASH_B), '\App\Widget'),
            $applied,
        );
    }

    /**
     * @return \Iterator<string, array{string}>
     */
    public static function uncarriableDocblocks(): \Iterator
    {
        yield 'nullable' => ['/** @var ?\App\Widget $component */'];
        yield 'union' => ['/** @var \App\Widget|\App\Gadget $component */'];
        yield 'null union' => ['/** @var \App\Widget|null $component */'];
        yield 'generic' => ['/** @var \Illuminate\Support\Collection<int, string> $component */'];
        yield 'literal null' => ['/** @var null $component */'];
        yield 'prose mention' => ['/** The $component is a \App\Widget here. */'];
        yield 'two mentions' => ["/**\n * @var \\App\\Widget \$component\n * @var \\App\\Gadget \$component\n */"];
        yield 'array shape' => ['/** @var array{id: int} $component */'];
    }

    /** The save is `isset()`-gated: a null declared `$component` is not restored, so the type is unsound there. */
    #[Test]
    #[DataProvider('uncarriableDocblocks')]
    public function a_docblock_this_pass_cannot_carry_is_not_reasserted(string $docblock): void
    {
        $compiled = "<?php {$docblock} ?>" . $this->save(self::HASH_A) . $this->restore(self::HASH_A);

        $this->assertSame($compiled, ComponentRestoreReassert::apply($compiled));
    }

    /** A later uncarriable docblock replaces the carried type, so the earlier plain one stops applying. */
    #[Test]
    public function an_uncarriable_docblock_resets_the_carried_type(): void
    {
        $compiled = self::DECLARE
            . '<?php /** @var ?\App\Widget $component */ ?>'
            . $this->save(self::HASH_A) . $this->restore(self::HASH_A);

        $this->assertSame($compiled, ComponentRestoreReassert::apply($compiled));
    }

    #[Test]
    public function a_docblock_for_another_variable_is_ignored(): void
    {
        $compiled = '<?php /** @var \App\Gadget $other */ ?>' . self::DECLARE
            . $this->save(self::HASH_A) . $this->restore(self::HASH_A);

        $this->assertStringEndsWith('endif; /** @var \App\Widget $component */ ?>', ComponentRestoreReassert::apply($compiled));
    }

    #[Test]
    public function a_shadow_without_any_component_bookkeeping_is_returned_untouched(): void
    {
        $compiled = self::DECLARE . "\n<?php echo e(\$component->id()); ?>";

        $this->assertSame($compiled, ComponentRestoreReassert::apply($compiled));
    }

    /**
     * @return \Iterator<string, array{string}>
     */
    public static function unprovenTemplates(): \Iterator
    {
        yield 'plain assignment' => ['@php $component = new Widget; @endphp'];
        yield 'raw php assignment' => ['<?php $component = null; ?>'];
        yield 'null coalescing assignment' => ['@php $component ??= new Widget; @endphp'];
        yield 'compound assignment' => ['@php $component .= "x"; @endphp'];
        yield 'unset' => ['@php unset($component); @endphp'];
        yield 'upper case unset' => ['@php UNSET($component); @endphp'];
        yield 'upper case variable' => ['@php $Component = new Widget; @endphp'];
        yield 'short destructuring' => ['@php [$a, $component] = $pair; @endphp'];
        yield 'offset destructuring' => ['@php [$o[\'x\'], $component] = $pair; @endphp'];
        yield 'list destructuring' => ['@php list($a, $component) = $pair; @endphp'];
        yield 'foreach value' => ['@foreach ($items as $component) @endforeach'];
        yield 'foreach key value' => ['@foreach ($items as $key => $component) @endforeach'];
        yield 'foreach by reference' => ['@foreach ($items as &$component) @endforeach'];
        yield 'foreach short destructuring' => ['@foreach ($items as [$k, $component]) @endforeach'];
        yield 'foreach list destructuring' => ['@foreach ($items as list($k, $component)) @endforeach'];
        yield 'reference alias' => ['@php $ref = &$component; @endphp'];
        yield 'catch variable' => ['@php try { f(); } catch (\Exception $component) {} @endphp'];
        yield 'bare echo' => ['{{ $component }}'];
        yield 'function argument' => ['@php f($component); @endphp'];
        yield 'by reference out parameter' => ['@php preg_match(\'/x/\', \'x\', $component); @endphp'];
        yield 'variable variable' => ['@php $$component = 1; @endphp'];
        yield 'isset with several arguments' => ['@php isset($component, $other); @endphp'];
        yield 'variable variable read' => ['{{ $$component->id() }}'];
        yield 'verbatim body' => ['@verbatim <?php $component = new Widget; ?> @endverbatim'];
        yield 'one write among reads' => ['{{ $component->id() }} @php $component = null; @endphp {{ $component->id() }}'];
    }

    #[Test]
    #[DataProvider('unprovenTemplates')]
    public function a_template_with_an_unproven_component_use_is_not_a_reassert_candidate(string $source): void
    {
        $this->assertFalse(ComponentRestoreReassert::templateOnlyReadsComponent($source));
    }

    /**
     * @return \Iterator<string, array{string}>
     */
    public static function readingTemplates(): \Iterator
    {
        yield 'no mention' => ['<x-alert />'];
        yield 'property read' => ['{{ $component->id() }}'];
        yield 'nullsafe read' => ['{{ $component?->id() }}'];
        yield 'method chain on a new line' => ["{{ \$component\n    ->id() }}"];
        yield 'comparison' => ['@php $same = $component == $other; @endphp'];
        yield 'strict comparison' => ['@if ($component === $other) x @endif'];
        yield 'not identical' => ['@if ($component !== null) x @endif'];
        yield 'not equal' => ['@if ($component != null) x @endif'];
        yield 'instanceof' => ['@if ($component instanceof Widget) x @endif'];
        yield 'null coalescing read' => ['{{ $component ?? "none" }}'];
        yield 'isset' => ['@if (isset($component)) x @endif'];
        yield 'empty' => ['@if (empty($component)) x @endif'];
        yield 'isset directive' => ['@isset($component) x @endisset'];
        yield 'empty directive' => ['@empty($component) x @endempty'];
        yield 'single line docblock' => ['<?php /** @var Widget $component */ ?>'];
        yield 'psalm docblock' => ['<?php /** @psalm-var \\App\\Widget $component */ ?>'];
        yield 'multi line docblock' => ["<?php\n/**\n * @var \\App\\Widget \$component\n */\n?>"];
        yield 'blade comment only' => ['{{-- $component = 1; --}}'];
        yield 'other variable' => ['@php $components = []; @endphp'];
        yield 'foreach over a property' => ['@foreach ($component->items() as $item) @endforeach'];
    }

    #[Test]
    #[DataProvider('readingTemplates')]
    public function a_template_that_only_reads_component_is_a_reassert_candidate(string $source): void
    {
        $this->assertTrue(ComponentRestoreReassert::templateOnlyReadsComponent($source));
    }

    #[Test]
    public function a_declaration_after_a_closed_block_is_carried_again(): void
    {
        $compiled = '<?php if ($a): ?><?php endif; ?>' . self::DECLARE . self::save(self::HASH_A) . self::restore(self::HASH_A);

        $this->assertStringEndsWith('endif; /** @var \App\Widget $component */ ?>', ComponentRestoreReassert::apply($compiled));
    }

    #[Test]
    public function a_declaration_after_a_closed_brace_block_is_carried_again(): void
    {
        $compiled = '<?php if ($a) { f(); } ?>' . self::DECLARE . self::save(self::HASH_A) . self::restore(self::HASH_A);

        $this->assertStringEndsWith('endif; /** @var \App\Widget $component */ ?>', ComponentRestoreReassert::apply($compiled));
    }

    /** A `}` inside an interpolated string is string text, not a closing brace. */
    #[Test]
    public function a_brace_inside_an_encapsed_string_does_not_change_the_block_depth(): void
    {
        $compiled = '<?php echo "$a}"; ?>' . self::DECLARE . self::save(self::HASH_A) . self::restore(self::HASH_A);

        $this->assertStringEndsWith('endif; /** @var \App\Widget $component */ ?>', ComponentRestoreReassert::apply($compiled));
    }

    #[Test]
    public function a_tag_inside_a_block_is_reasserted_from_a_declaration_before_it(): void
    {
        $compiled = self::DECLARE . '<?php if ($a): ?>' . self::save(self::HASH_A) . self::restore(self::HASH_A) . '<?php endif; ?>';

        $this->assertStringContainsString('endif; /** @var \App\Widget $component */ ?><?php endif; ?>', ComponentRestoreReassert::apply($compiled));
    }

    /**
     * @return \Iterator<string, array{string}>
     */
    public static function nestedDeclarations(): \Iterator
    {
        $gadget = '/** @var \App\Gadget $component */';

        yield 'alternative if arm' => ["<?php if (\$a): ?><?php {$gadget} ?><?php endif; ?>"];
        yield 'else arm' => ["<?php if (\$a): ?><?php else: ?><?php {$gadget} ?><?php endif; ?>"];
        yield 'elseif arm' => ["<?php if (\$a): ?><?php elseif (\$b): ?><?php {$gadget} ?><?php endif; ?>"];
        yield 'foreach body' => ["<?php foreach (\$xs as \$x): ?><?php {$gadget} ?><?php endforeach; ?>"];
        yield 'for body' => ["<?php for (\$i = 0; \$i < 2; \$i++): ?><?php {$gadget} ?><?php endfor; ?>"];
        yield 'while body' => ["<?php while (\$a): ?><?php {$gadget} ?><?php endwhile; ?>"];
        yield 'switch case' => ["<?php switch (\$a): ?><?php case 1: ?><?php {$gadget} ?><?php endswitch; ?>"];
        yield 'brace if' => ["<?php if (\$a) { {$gadget} } ?>"];
        yield 'closure body' => ["<?php \$f = function (\$c) { {$gadget} return 1; }; ?>"];
        yield 'nested parens in the condition' => ["<?php if (f(g(\$a)) && (\$b)): ?><?php {$gadget} ?><?php endif; ?>"];
        yield 'interpolated brace before it' => ["<?php echo \"{\$a}\"; if (\$a): ?><?php {$gadget} ?><?php endif; ?>"];
    }

    /** The declaration is not in force at the tag, so it must not be carried AND must reset the one that was. */
    #[Test]
    #[DataProvider('nestedDeclarations')]
    public function a_declaration_at_block_depth_above_zero_resets_the_carried_type(string $block): void
    {
        $compiled = self::DECLARE . $block . self::save(self::HASH_A) . self::restore(self::HASH_A);

        $this->assertSame($compiled, ComponentRestoreReassert::apply($compiled));
    }
}
