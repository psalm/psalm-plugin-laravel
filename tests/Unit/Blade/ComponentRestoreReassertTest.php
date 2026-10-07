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
    public static function assigningTemplates(): \Iterator
    {
        yield 'plain assignment' => ['@php $component = new Widget; @endphp'];
        yield 'raw php assignment' => ['<?php $component = null; ?>'];
        yield 'unset' => ['@php unset($component); @endphp'];
        yield 'short destructuring' => ['@php [$a, $component] = $pair; @endphp'];
        yield 'list destructuring' => ['@php list($a, $component) = $pair; @endphp'];
        yield 'foreach value' => ['@foreach ($items as $component) @endforeach'];
        yield 'foreach key value' => ['@foreach ($items as $key => $component) @endforeach'];
        yield 'foreach by reference' => ['@foreach ($items as &$component) @endforeach'];
    }

    #[Test]
    #[DataProvider('assigningTemplates')]
    public function a_template_that_writes_component_is_detected(string $source): void
    {
        $this->assertTrue(ComponentRestoreReassert::templateAssignsComponent($source));
    }

    /**
     * @return \Iterator<string, array{string}>
     */
    public static function readingTemplates(): \Iterator
    {
        yield 'read only' => ['{{ $component->id() }}'];
        yield 'comparison' => ['@php $same = $component == $other; @endphp'];
        yield 'strict comparison' => ['@if ($component === $other) x @endif'];
        yield 'isset' => ['@if (isset($component)) x @endif'];
        yield 'blade comment only' => ['{{-- $component = 1; --}}'];
        yield 'verbatim only' => ['@verbatim $component = 1; @endverbatim'];
        yield 'other variable' => ['@php $components = []; @endphp'];
        yield 'foreach over it' => ['@foreach ($component->items() as $item) @endforeach'];
    }

    #[Test]
    #[DataProvider('readingTemplates')]
    public function a_template_that_only_reads_component_is_not_flagged(string $source): void
    {
        $this->assertFalse(ComponentRestoreReassert::templateAssignsComponent($source));
    }
}
