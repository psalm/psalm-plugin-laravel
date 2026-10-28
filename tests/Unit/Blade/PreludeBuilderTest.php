<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use PhpParser\Node\Stmt\Nop;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Blade\PreludeBuilder;

#[CoversClass(PreludeBuilder::class)]
final class PreludeBuilderTest extends TestCase
{
    #[Test]
    public function component_outside_tag_bookkeeping_is_not_declared_as_a_class(): void
    {
        // Laravel never passes `$component` as view data (only ManagesComponents::componentData()'s
        // slot/data merge reaches a view); only a tag's compiled save types it (#1873).
        $prelude = (new PreludeBuilder())->build('<?php if (isset($component)) {} ?>', '');

        $this->assertStringNotContainsString('Illuminate\View\Component ', $prelude);
        $this->assertStringContainsString('@var mixed $component */', $prelude);
    }

    #[Test]
    public function attributes_and_slot_are_not_declared_outside_a_component_view(): void
    {
        $prelude = (new PreludeBuilder())->build(
            '<?php if (isset($attributes)) {} ?>',
            '<div>plain caller template, no component markers</div>',
        );

        $this->assertStringNotContainsString('ComponentAttributeBag', $prelude);
        $this->assertStringContainsString('@var mixed $attributes */', $prelude);
    }

    #[Test]
    public function props_declares_attributes_nullable_and_slot_when_mentioned(): void
    {
        $prelude = (new PreludeBuilder())->build(
            '<?php echo 1; ?>',
            "@props(['type' => 'info'])\n{{ \$attributes }} {{ \$slot }}",
        );

        $this->assertStringContainsString('@var ?\Illuminate\View\ComponentAttributeBag $attributes */', $prelude);
        $this->assertStringContainsString('@var \Illuminate\View\ComponentSlot $slot */', $prelude);
    }

    #[Test]
    public function aware_declares_attributes_non_null(): void
    {
        // compileAware() emits no `$attributes` assignment of its own (Component::data() /
        // AnonymousComponent::data() already guarantee the key), so it is never nullable.
        $prelude = (new PreludeBuilder())->build('<?php echo 1; ?>', "@aware(['type'])\n{{ \$attributes }}");

        $this->assertStringContainsString('@var \Illuminate\View\ComponentAttributeBag $attributes */', $prelude);
        $this->assertStringNotContainsString('?\Illuminate\View\ComponentAttributeBag', $prelude);
    }

    #[Test]
    public function a_bare_attributes_mention_without_props_declares_attributes_non_null(): void
    {
        $prelude = (new PreludeBuilder())->build('<?php echo 1; ?>', '{{ $attributes->class(["x"]) }}');

        $this->assertStringContainsString('@var \Illuminate\View\ComponentAttributeBag $attributes */', $prelude);
        $this->assertStringNotContainsString('?\Illuminate\View\ComponentAttributeBag', $prelude);
    }

    #[Test]
    public function a_bare_slot_mention_alone_declares_only_slot(): void
    {
        // The @component-directive render path agrees with <x-*>: both hand a ComponentSlot
        // (ManagesComponents::componentData()), so a lone `$slot` mention is enough either way.
        $prelude = (new PreludeBuilder())->build('<?php echo 1; ?>', '<div>{{ $slot }}</div>');

        $this->assertStringContainsString('@var \Illuminate\View\ComponentSlot $slot */', $prelude);
        $this->assertStringNotContainsString('ComponentAttributeBag', $prelude);
    }

    #[Test]
    public function a_longer_identifier_is_not_mistaken_for_the_attributes_or_slot_mention(): void
    {
        // `str_contains($source, '$attributes')`/`'$slot'` would match inside `$attributesFoo` and
        // `$slots_count` too — a longer identifier, not a mention of the ambient name itself — which
        // would flip isComponentView() to true on a plain page that merely happens to declare one.
        $prelude = (new PreludeBuilder())->build(
            '<?php echo 1; ?>',
            '<?php $attributesFoo = []; $slots_count = 0; ?>',
        );

        $this->assertStringNotContainsString('ComponentAttributeBag', $prelude);
        $this->assertStringNotContainsString('ComponentSlot', $prelude);
        $this->assertFalse(PreludeBuilder::isComponentView('<?php $attributesFoo = []; $slots_count = 0; ?>'));
    }

    #[Test]
    public function a_commented_out_props_directive_is_not_a_live_directive(): void
    {
        // Blade strips `{{-- --}}` before compiling, so no `$attributes ??= ...` is emitted and the
        // bag is never absent; reading the commented directive as live typed it nullable and made
        // every `$attributes->` read in the view a false PossiblyNullReference.
        $source = "{{-- @props(['type' => 'info']) --}}\n{{ \$attributes->merge([]) }}";
        $prelude = (new PreludeBuilder())->build('<?php echo 1; ?>', $source);

        $this->assertStringContainsString('@var \Illuminate\View\ComponentAttributeBag $attributes */', $prelude);
        $this->assertStringNotContainsString('?\Illuminate\View\ComponentAttributeBag', $prelude);
    }

    #[Test]
    public function a_commented_out_ambient_mention_does_not_classify_a_plain_page(): void
    {
        // A mention inside a comment never reaches the compiled output, so treating it as evidence
        // of a component view widens the relocator's drop gate over a template that has none.
        $source = "{{-- {{ \$attributes }} {{ \$slot }} --}}\n<div>plain page</div>";

        $this->assertFalse(PreludeBuilder::isComponentView($source));
    }

    #[Test]
    public function a_props_view_declares_slot_without_mentioning_it(): void
    {
        // Both render paths build a ComponentSlot for `$slot` whether or not the template names it
        // (ManagesComponents::componentData()), so recognition via a live directive is enough:
        // an indirect read must not fall through to UndefinedGlobalVariable.
        $prelude = (new PreludeBuilder())->build('<?php echo 1; ?>', "@props(['type' => 'info'])\n<div>no mention</div>");

        $this->assertStringContainsString('@var \Illuminate\View\ComponentSlot $slot */', $prelude);
    }

    #[Test]
    public function a_bare_attributes_mention_alone_does_not_declare_slot(): void
    {
        // Mention-based recognition is a heuristic over a name the template merely happens to use;
        // only a live `@props`/`@aware` directive is proof enough to declare a name never written.
        $prelude = (new PreludeBuilder())->build('<?php echo 1; ?>', '{{ $attributes->class(["x"]) }}');

        $this->assertStringNotContainsString('ComponentSlot', $prelude);
    }

    /**
     * @return iterable<string, array{string, list<string>|null}>
     */
    public static function contractTypes(): iterable
    {
        yield 'fully qualified' => ['\App\User', ['\App\User']];
        yield 'nullable' => ['?\App\User', ['\App\User']];
        yield 'short name' => ['User', ['User']];
        yield 'generic arguments' => ['array<int, \App\User>|\Illuminate\Support\Collection<int, User>', ['\Illuminate\Support\Collection', '\App\User', 'User']];
        yield 'shape keys are not classes' => ['array{name: string, user?: \App\User}', ['\App\User']];
        yield 'keywords and literals' => ["non-empty-list<int>|'Draft'|\"Posted\"|positive-int|-1|null", []];
        yield 'class constant' => ['\App\Status::DRAFT_*', ['\App\Status']];
        yield 'callable parameter names' => ['callable(string $user): \App\User', ['\App\User']];
        yield 'unparseable' => ['array<int $m', null];
    }

    /**
     * @param list<string>|null $expected
     */
    #[Test]
    #[DataProvider('contractTypes')]
    public function class_names_in_a_declared_type_are_read_as_written(string $type, ?array $expected): void
    {
        $this->assertSame($expected, PreludeBuilder::classNamesIn($type));
    }

    #[Test]
    public function a_local_first_seen_as_a_root_level_assignment_is_not_declared(): void
    {
        $prelude = (new PreludeBuilder())->build('<?php $flag = $item !== null; if ($flag) { echo $item; } ?>', '');

        $this->assertStringNotContainsString('$flag */', $prelude);
        $this->assertStringContainsString('@var mixed $item */', $prelude);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function stillDeclaredAssignments(): iterable
    {
        yield 'the assignment reads its own target' => ['<?php $title = strtoupper($title); ?>'];
        yield 'a read comes first' => ['<?php echo $title; $title = 1; ?>'];
        yield 'the first mention is inside a closure' => ['<?php $f = function () { $title = 1; }; $title = 2; ?>'];
        yield 'the first write is a foreach target' => ['<?php foreach ($rows as $title) {} ?>'];
        yield 'the assignment sits in a branch' => ['<?php if ($c): ?><?php $title = 1; ?><?php endif; ?><?php echo $title; ?>'];
    }

    #[Test]
    #[DataProvider('stillDeclaredAssignments')]
    public function a_name_whose_first_mention_is_not_a_root_level_assignment_stays_declared(string $compiled): void
    {
        $prelude = (new PreludeBuilder())->build($compiled, '');

        $this->assertStringContainsString('@var mixed $title */', $prelude);
    }

    /**
     * #1553: a compiled shadow with a syntax error anywhere used to drop the ENTIRE prelude net
     * (undeclaredVariables() caught the parse Throwable and returned []), not just the erroring
     * statement. A read that precedes the error must still get its `@var mixed` fallback.
     */
    #[Test]
    public function a_read_before_a_syntax_error_still_gets_var_mixed(): void
    {
        $prelude = (new PreludeBuilder())->build('<?php echo $foo; $bad = [\'value\' => ,]; ?>', '');

        $this->assertStringContainsString('@var mixed $foo */', $prelude);
    }

    /**
     * #1558: a shared `__`-prefixed global (e.g. bookstack's `$__themeViews`) that a template
     * merely READS, never assigns, used to be silently dropped from the undeclared set by the
     * `__`-prefix skip below, leaving the read genuinely undeclared and reporting
     * UndefinedGlobalVariable per occurrence. The design default for an undeclared read is silent
     * `mixed`, same as any other name.
     */
    #[Test]
    public function a_read_underscore_prefixed_global_gets_var_mixed(): void
    {
        $prelude = (new PreludeBuilder())->build('<?php echo $__customShared; ?>', '');

        $this->assertStringContainsString('@var mixed $__customShared */', $prelude);
    }

    /**
     * #1558: a `__`-prefixed name the compiled output WRITES (Blade's `@session` bookkeeping
     * appends to `$__sessionPrevious` without ever assigning it whole) must keep the skip: a
     * `mixed` declaration on an append target widens the appended array to
     * `mixed|non-empty-list<mixed>` and turns the compiler's own `!empty()` epilogue check into a
     * new `RiskyTruthyFalsyComparison` on the template line. Only read-only `__` names are shared
     * globals; a written one is bookkeeping. #1722: the save stacks' `list<mixed>` needs the
     * compiler's own push, so the same name written any other way is still skipped.
     */
    #[Test]
    public function a_written_underscore_prefixed_variable_keeps_the_skip(): void
    {
        $compiled = '<?php $__sessionPrevious[] = 1;'
            . ' if (!empty($__sessionPrevious)) { echo 1; } echo $__readOnlyShared; ?>';

        $prelude = (new PreludeBuilder())->build($compiled, '');

        $this->assertStringNotContainsString('$__sessionPrevious', $prelude);
        $this->assertStringContainsString('@var mixed $__readOnlyShared */', $prelude);
    }

    /**
     * #1558: `$__env` is the one `__`-prefixed name with a REAL declared type
     * ({@see PreludeBuilder::AMBIENT_TYPES}); removing the blanket `__`-prefix skip must not let a
     * read of it also fall into the generic mixed-declaration pass and duplicate the docblock.
     */
    #[Test]
    public function declared_underscore_prefixed_variable_is_not_duplicated_as_mixed(): void
    {
        $prelude = (new PreludeBuilder())->build('<?php echo $__env; ?>', '');

        $this->assertSame(1, \substr_count($prelude, '$__env'));
        $this->assertStringContainsString('@var \Illuminate\View\Factory $__env */', $prelude);
        $this->assertStringNotContainsString('@var mixed $__env', $prelude);
    }

    /**
     * #1873: a caller mentions `$component`/`$attributes` only through a tag's compiled
     * save/restore, so both get their runtime types, and so does each saved original: Psalm types a
     * variable assigned only inside an `if` as `mixed` in a later `isset()` branch, which is where
     * the restore reads it.
     */
    #[Test]
    public function a_tag_callers_bookkeeping_names_get_their_runtime_types(): void
    {
        $prelude = (new PreludeBuilder())->build($this->compiledTag('5194778a'), '<x-alert>Hi</x-alert>');

        $this->assertStringContainsString('@var \Illuminate\View\Component|null $component */', $prelude);
        $this->assertStringContainsString('@var \Illuminate\View\Component|null $__componentOriginal5194778a */', $prelude);
        $this->assertStringContainsString('@var \Illuminate\View\ComponentAttributeBag|null $attributes */', $prelude);
        $this->assertStringContainsString('@var \Illuminate\View\ComponentAttributeBag|null $__attributesOriginal5194778a */', $prelude);
        $this->assertStringNotContainsString('@var mixed', $prelude);
    }

    /** #1701: a declared, read, or view-data `$component` keeps today's typing; only the bookkeeping-only case changes. */
    #[Test]
    public function a_template_that_names_component_keeps_it_mixed(): void
    {
        $source = "@php /** @var \\App\\Foo \$component */ @endphp\n<x-alert>Hi</x-alert>\n{{ \$component->id() }}";
        $prelude = (new PreludeBuilder())->build($this->compiledTag('5194778a'), $source);

        $this->assertStringContainsString('@var mixed $component */', $prelude);
        $this->assertStringNotContainsString('$__componentOriginal', $prelude);
        $this->assertStringContainsString('@var \Illuminate\View\ComponentAttributeBag|null $attributes */', $prelude);
    }

    /** Blade removes `{{-- --}}` before compiling and its tag markers after, so text they split still reads `$component`. */
    #[Test]
    #[DataProvider('splitComponentReads')]
    public function a_component_read_split_by_text_blade_removes_keeps_it_mixed(string $source): void
    {
        $prelude = (new PreludeBuilder())->build(self::compiledTag('5194778a'), $source . "\n<x-alert/>");

        $this->assertStringContainsString('@var mixed $component */', $prelude);
        $this->assertStringNotContainsString('$__componentOriginal', $prelude);
    }

    /** @return iterable<string, array{string}> */
    public static function splitComponentReads(): iterable
    {
        yield 'Blade comment' => ['{{ $compo{{-- split --}}nent->id() }}'];
    }

    /** A component view keeps its own `$attributes` typing, restore re-assert included (#1543); `$component` is typed as in any caller. */
    #[Test]
    public function a_component_view_keeps_its_attributes_typing(): void
    {
        $prelude = (new PreludeBuilder())->build($this->compiledTag('5194778a'), "@props(['type'])\n<x-alert>Hi</x-alert>");

        $this->assertStringContainsString('@var ?\Illuminate\View\ComponentAttributeBag $attributes */', $prelude);
        $this->assertSame(1, \substr_count($prelude, ' $attributes */'));
        $this->assertStringNotContainsString('$__attributesOriginal', $prelude);
        $this->assertStringContainsString('@var \Illuminate\View\Component|null $component */', $prelude);
    }

    #[Test]
    public function every_tags_saved_original_is_declared_once(): void
    {
        $compiled = $this->compiledTag('aaaa') . $this->compiledTag('bbbb') . $this->compiledTag('aaaa');
        $prelude = (new PreludeBuilder())->build($compiled, '<x-a/><x-b/><x-a/>');

        $this->assertSame(1, \substr_count($prelude, '$__componentOriginalaaaa */'));
        $this->assertSame(1, \substr_count($prelude, '$__componentOriginalbbbb */'));
        $this->assertSame(1, \substr_count($prelude, '$__attributesOriginalbbbb */'));
    }

    #[Test]
    public function only_a_prelude_typing_attributes_from_a_tag_declares_tag_attributes(): void
    {
        $builder = new PreludeBuilder();
        $compiled = self::compiledTag('5194778a');

        $this->assertTrue(PreludeBuilder::declaresTagAttributes($builder->build($compiled, '<x-alert/>') . $compiled));
        $this->assertFalse(PreludeBuilder::declaresTagAttributes($builder->build($compiled, "@props(['type'])\n<x-alert/>") . $compiled));
        $this->assertFalse(PreludeBuilder::declaresTagAttributes($builder->build($compiled, "@aware(['type'])\n<x-alert/>") . $compiled));

        // The same text after the prelude is template code, not a declaration.
        $body = "<?php\n/** @var \\Illuminate\\View\\ComponentAttributeBag|null \$attributes */\n?>\n";
        $this->assertFalse(PreludeBuilder::declaresTagAttributes($builder->build('<?php echo 1; ?>', '') . $body));
    }

    /** What `CompilesComponents::compileClassComponentOpening()` and `compileEndComponentClass()` emit for `<x-alert>Hi</x-alert>`. */
    private function compiledTag(string $hash): string
    {
        return <<<PHP
            <?php if (isset(\$component)) { \$__componentOriginal{$hash} = \$component; } ?>
            <?php if (isset(\$attributes)) { \$__attributesOriginal{$hash} = \$attributes; } ?>
            <?php \$component = Illuminate\\View\\AnonymousComponent::resolve(['view' => 'components.alert','data' => []] + (isset(\$attributes) && \$attributes instanceof Illuminate\\View\\ComponentAttributeBag ? \$attributes->all() : [])); ?>
            <?php \$component->withName('alert'); ?>
            <?php if (\$component->shouldRender()): ?>
            <?php \$__env->startComponent(\$component->resolveView(), \$component->data()); ?>
            <?php if (isset(\$attributes) && \$attributes instanceof Illuminate\\View\\ComponentAttributeBag): ?>
            <?php \$attributes = \$attributes->except(\\Illuminate\\View\\AnonymousComponent::ignoredParameterNames()); ?>
            <?php endif; ?>
            <?php \$component->withAttributes([]); ?>Hi <?php echo \$__env->renderComponent(); ?>
            <?php endif; ?>
            <?php if (isset(\$__attributesOriginal{$hash})): ?>
            <?php \$attributes = \$__attributesOriginal{$hash}; ?>
            <?php unset(\$__attributesOriginal{$hash}); ?>
            <?php endif; ?>
            <?php if (isset(\$__componentOriginal{$hash})): ?>
            <?php \$component = \$__componentOriginal{$hash}; ?>
            <?php unset(\$__componentOriginal{$hash}); ?>
            <?php endif; ?>

            PHP;
    }

    /**
     * #1804: the prelude's closing tag parses to one `Stmt\Nop` on the prelude's last line (mapped
     * to template line 0) carrying every `@var` docblock before it, whatever the compiled output
     * starts with. ComponentViewSeedHandler seeds on it, after all of them apply.
     */
    #[Test]
    public function its_closing_tag_is_one_nop_carrying_the_docblocks(): void
    {
        $prelude = (new PreludeBuilder())->build('<?php echo $title, $body; ?>', '');
        $docblocks = \substr_count($prelude, '/** @var ');
        $parser = (new ParserFactory())->createForNewestSupportedVersion();

        $this->assertSame($docblocks + 2, \substr_count($prelude, "\n"));

        foreach (['<div></div>', '<?php echo $title; ?>'] as $compiled) {
            $stmts = $parser->parse($prelude . $compiled);
            $this->assertIsArray($stmts);

            $sentinel = $stmts[0] ?? null;
            $this->assertInstanceOf(Nop::class, $sentinel);
            $this->assertCount($docblocks, $sentinel->getComments());
            $this->assertSame($docblocks + 2, $sentinel->getStartLine());
            $this->assertNotInstanceOf(Nop::class, $stmts[1] ?? null);
        }
    }

    #[Test]
    public function only_a_leading_backslash_or_an_internal_class_resolves_without_an_import(): void
    {
        $this->assertTrue(PreludeBuilder::resolvesWithoutImport('\\App\\User'));
        $this->assertTrue(PreludeBuilder::resolvesWithoutImport('Closure'));
        $this->assertFalse(PreludeBuilder::resolvesWithoutImport('App\\User'), "resolved through the template's imports");
        $this->assertFalse(PreludeBuilder::resolvesWithoutImport('User'));
    }
}
