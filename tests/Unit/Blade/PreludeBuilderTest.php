<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Blade\PreludeBuilder;

#[CoversClass(PreludeBuilder::class)]
final class PreludeBuilderTest extends TestCase
{
    #[Test]
    public function includes_ambient_vars(): void
    {
        $prelude = (new PreludeBuilder())->compose('<?php echo 1; ?>', [], '')[0];

        foreach (['__env', 'errors', 'loop'] as $name) {
            $this->assertStringContainsString("\${$name} */", $prelude);
        }
    }

    #[Test]
    public function component_is_never_declared_as_a_class(): void
    {
        // Laravel never passes `$component` as view data (only ManagesComponents::componentData()'s
        // slot/data merge reaches a view); it is only a local in the CALLER's compiled output.
        $prelude = (new PreludeBuilder())->compose('<?php if (isset($component)) {} ?>', [], '')[0];

        $this->assertStringNotContainsString('Illuminate\View\Component ', $prelude);
        $this->assertStringContainsString('@var mixed $component */', $prelude);
    }

    #[Test]
    public function attributes_and_slot_are_not_declared_outside_a_component_view(): void
    {
        $prelude = (new PreludeBuilder())->compose(
            '<?php if (isset($attributes)) {} ?>',
            [],
            '<div>plain caller template, no component markers</div>',
        )[0];

        $this->assertStringNotContainsString('ComponentAttributeBag', $prelude);
        $this->assertStringContainsString('@var mixed $attributes */', $prelude);
    }

    #[Test]
    public function props_declares_attributes_nullable_and_slot_when_mentioned(): void
    {
        $prelude = (new PreludeBuilder())->compose(
            '<?php echo 1; ?>',
            [],
            "@props(['type' => 'info'])\n{{ \$attributes }} {{ \$slot }}",
        )[0];

        $this->assertStringContainsString('@var ?\Illuminate\View\ComponentAttributeBag $attributes */', $prelude);
        $this->assertStringContainsString('@var \Illuminate\View\ComponentSlot $slot */', $prelude);
    }

    #[Test]
    public function aware_declares_attributes_non_null(): void
    {
        // compileAware() emits no `$attributes` assignment of its own (Component::data() /
        // AnonymousComponent::data() already guarantee the key), so it is never nullable.
        $prelude = (new PreludeBuilder())->compose('<?php echo 1; ?>', [], "@aware(['type'])\n{{ \$attributes }}")[0];

        $this->assertStringContainsString('@var \Illuminate\View\ComponentAttributeBag $attributes */', $prelude);
        $this->assertStringNotContainsString('?\Illuminate\View\ComponentAttributeBag', $prelude);
    }

    #[Test]
    public function a_bare_attributes_mention_without_props_declares_attributes_non_null(): void
    {
        $prelude = (new PreludeBuilder())->compose('<?php echo 1; ?>', [], '{{ $attributes->class(["x"]) }}')[0];

        $this->assertStringContainsString('@var \Illuminate\View\ComponentAttributeBag $attributes */', $prelude);
        $this->assertStringNotContainsString('?\Illuminate\View\ComponentAttributeBag', $prelude);
    }

    #[Test]
    public function a_bare_slot_mention_alone_declares_only_slot(): void
    {
        // The @component-directive render path agrees with <x-*>: both hand a ComponentSlot
        // (ManagesComponents::componentData()), so a lone `$slot` mention is enough either way.
        $prelude = (new PreludeBuilder())->compose('<?php echo 1; ?>', [], '<div>{{ $slot }}</div>')[0];

        $this->assertStringContainsString('@var \Illuminate\View\ComponentSlot $slot */', $prelude);
        $this->assertStringNotContainsString('ComponentAttributeBag', $prelude);
    }

    #[Test]
    public function a_longer_identifier_is_not_mistaken_for_the_attributes_or_slot_mention(): void
    {
        // `str_contains($source, '$attributes')`/`'$slot'` would match inside `$attributesFoo` and
        // `$slots_count` too — a longer identifier, not a mention of the ambient name itself — which
        // would flip isComponentView() to true on a plain page that merely happens to declare one.
        $prelude = (new PreludeBuilder())->compose(
            '<?php echo 1; ?>',
            [],
            '<?php $attributesFoo = []; $slots_count = 0; ?>',
        )[0];

        $this->assertStringNotContainsString('ComponentAttributeBag', $prelude);
        $this->assertStringNotContainsString('ComponentSlot', $prelude);
        $this->assertFalse(PreludeBuilder::isComponentView('<?php $attributesFoo = []; $slots_count = 0; ?>'));
    }

    #[Test]
    public function directive_matching_is_case_insensitive(): void
    {
        // Blade dispatches a directive via method_exists($this, 'compile'.ucfirst($name)), which is
        // case-insensitive in PHP, so `@PROPS(...)` compiles exactly like `@props(...)` — the
        // classifier must agree, or it disagrees with the compiler about the same template.
        $prelude = (new PreludeBuilder())->compose('<?php echo 1; ?>', [], "@PROPS(['type' => 'info'])")[0];

        $this->assertStringContainsString('@var ?\Illuminate\View\ComponentAttributeBag $attributes */', $prelude);
    }

    #[Test]
    public function aware_directive_matching_is_case_insensitive(): void
    {
        $prelude = (new PreludeBuilder())->compose('<?php echo 1; ?>', [], "@AWARE(['type'])")[0];

        $this->assertStringContainsString('@var \Illuminate\View\ComponentAttributeBag $attributes */', $prelude);
        $this->assertStringNotContainsString('?\Illuminate\View\ComponentAttributeBag', $prelude);
    }

    #[Test]
    public function a_commented_out_props_directive_is_not_a_live_directive(): void
    {
        // Blade strips `{{-- --}}` before compiling, so no `$attributes ??= ...` is emitted and the
        // bag is never absent; reading the commented directive as live typed it nullable and made
        // every `$attributes->` read in the view a false PossiblyNullReference.
        $source = "{{-- @props(['type' => 'info']) --}}\n{{ \$attributes->merge([]) }}";
        $prelude = (new PreludeBuilder())->compose('<?php echo 1; ?>', [], $source)[0];

        $this->assertStringContainsString('@var \Illuminate\View\ComponentAttributeBag $attributes */', $prelude);
        $this->assertStringNotContainsString('?\Illuminate\View\ComponentAttributeBag', $prelude);
    }

    #[Test]
    public function an_escaped_props_directive_is_not_a_live_directive(): void
    {
        // `@@props(...)` compiles to the literal text `@props(...)`: compileStatements() sees the
        // leading `@` and echoes the rest verbatim instead of dispatching compileProps().
        $source = "@@props(['type' => 'info'])\n{{ \$attributes->merge([]) }}";
        $prelude = (new PreludeBuilder())->compose('<?php echo 1; ?>', [], $source)[0];

        $this->assertStringContainsString('@var \Illuminate\View\ComponentAttributeBag $attributes */', $prelude);
        $this->assertStringNotContainsString('?\Illuminate\View\ComponentAttributeBag', $prelude);
    }

    #[Test]
    public function an_escaped_aware_directive_does_not_classify_the_view(): void
    {
        $this->assertFalse(PreludeBuilder::isComponentView("@@aware(['type'])\n<div>plain page</div>"));
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
    public function a_verbatim_ambient_mention_does_not_classify_a_plain_page(): void
    {
        // A `@verbatim` body is emitted as literal text, never as code that could read the name.
        $source = "@verbatim\n{{ \$attributes }} {{ \$slot }}\n@endverbatim";

        $this->assertFalse(PreludeBuilder::isComponentView($source));
    }

    #[Test]
    public function a_props_view_declares_slot_without_mentioning_it(): void
    {
        // Both render paths build a ComponentSlot for `$slot` whether or not the template names it
        // (ManagesComponents::componentData()), so recognition via a live directive is enough:
        // an indirect read must not fall through to UndefinedGlobalVariable.
        $prelude = (new PreludeBuilder())->compose('<?php echo 1; ?>', [], "@props(['type' => 'info'])\n<div>no mention</div>")[0];

        $this->assertStringContainsString('@var \Illuminate\View\ComponentSlot $slot */', $prelude);
    }

    #[Test]
    public function an_aware_view_declares_slot_without_mentioning_it(): void
    {
        $prelude = (new PreludeBuilder())->compose('<?php echo 1; ?>', [], "@aware(['type'])\n<div>no mention</div>")[0];

        $this->assertStringContainsString('@var \Illuminate\View\ComponentSlot $slot */', $prelude);
    }

    #[Test]
    public function a_bare_attributes_mention_alone_does_not_declare_slot(): void
    {
        // Mention-based recognition is a heuristic over a name the template merely happens to use;
        // only a live `@props`/`@aware` directive is proof enough to declare a name never written.
        $prelude = (new PreludeBuilder())->compose('<?php echo 1; ?>', [], '{{ $attributes->class(["x"]) }}')[0];

        $this->assertStringNotContainsString('ComponentSlot', $prelude);
    }

    #[Test]
    public function includes_contract_vars_with_given_type(): void
    {
        $prelude = (new PreludeBuilder())->compose('<?php echo 1; ?>', ['user' => '\App\Models\User'], '')[0];

        $this->assertStringContainsString('@var \App\Models\User $user */', $prelude);
    }

    #[Test]
    public function undeclared_variable_gets_var_mixed(): void
    {
        $prelude = (new PreludeBuilder())->compose('<?php echo $foo; ?>', [], '')[0];

        $this->assertStringContainsString('@var mixed $foo */', $prelude);
    }

    /**
     * #1553: a compiled shadow with a syntax error anywhere used to drop the ENTIRE prelude net
     * (undeclaredVariables() caught the parse Throwable and returned []), not just the erroring
     * statement. A read that precedes the error must still get its `@var mixed` fallback.
     */
    #[Test]
    public function a_read_before_a_syntax_error_still_gets_var_mixed(): void
    {
        $prelude = (new PreludeBuilder())->compose('<?php echo $foo; $bad = [\'value\' => ,]; ?>', [], '')[0];

        $this->assertStringContainsString('@var mixed $foo */', $prelude);
    }

    /**
     * The bagisto shape (#1553): an empty bound component attribute (`:value=""`) compiles to
     * `'value' => ,`, a syntax error. PhpParser's own error recovery drops only the erroring
     * statement and keeps analyzing what follows, so a read AFTER the error point must recover too.
     */
    #[Test]
    public function a_read_after_a_syntax_error_still_gets_var_mixed(): void
    {
        $prelude = (new PreludeBuilder())->compose('<?php $bad = [\'value\' => ,]; echo $undeclared; ?>', [], '')[0];

        $this->assertStringContainsString('@var mixed $undeclared */', $prelude);
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
        $prelude = (new PreludeBuilder())->compose('<?php echo $__customShared; ?>', [], '')[0];

        $this->assertStringContainsString('@var mixed $__customShared */', $prelude);
    }

    /**
     * #1558: a `__`-prefixed name the compiled output WRITES (Blade's `@session` bookkeeping
     * appends to `$__sessionPrevious` without ever assigning it whole) must keep the skip: a
     * `mixed` declaration on an append target widens the appended array to
     * `mixed|non-empty-list<mixed>` and turns the compiler's own `!empty()` epilogue check into a
     * new `RiskyTruthyFalsyComparison` on the template line. Only read-only `__` names are shared
     * globals; a written one is bookkeeping.
     */
    #[Test]
    public function a_written_underscore_prefixed_variable_keeps_the_skip(): void
    {
        $compiled = '<?php if (isset($value)) { $__sessionPrevious[] = $value; }'
            . ' if (!empty($__sessionPrevious)) { echo 1; } echo $__readOnlyShared; ?>';

        $prelude = (new PreludeBuilder())->compose($compiled, [], '')[0];

        $this->assertStringNotContainsString('$__sessionPrevious', $prelude);
        $this->assertStringContainsString('@var mixed $__readOnlyShared */', $prelude);
    }

    #[Test]
    public function declared_variables_are_not_duplicated_as_mixed(): void
    {
        $prelude = (new PreludeBuilder())->compose('<?php echo $errors; ?>', [], '')[0];

        $this->assertSame(1, \substr_count($prelude, '$errors'));
    }

    /**
     * #1558: `$__env` is the one `__`-prefixed name with a REAL declared type
     * ({@see PreludeBuilder::AMBIENT_TYPES}); removing the blanket `__`-prefix skip must not let a
     * read of it also fall into the generic mixed-declaration pass and duplicate the docblock.
     */
    #[Test]
    public function declared_underscore_prefixed_variable_is_not_duplicated_as_mixed(): void
    {
        $prelude = (new PreludeBuilder())->compose('<?php echo $__env; ?>', [], '')[0];

        $this->assertSame(1, \substr_count($prelude, '$__env'));
        $this->assertStringContainsString('@var \Illuminate\View\Factory $__env */', $prelude);
        $this->assertStringNotContainsString('@var mixed $__env', $prelude);
    }

    #[Test]
    public function wraps_content_in_a_single_php_block(): void
    {
        $prelude = (new PreludeBuilder())->compose('<?php echo 1; ?>', [], '')[0];

        $this->assertSame(1, \substr_count($prelude, '<?php'));
        $this->assertSame(1, \substr_count($prelude, '?>'));
    }

    private const OPTIONAL_BODY = "<?php\n/**\n * @var string \$label The caption\n * @var bool \$stacked\n */\n\$label ??= '';\necho \$stacked ?? true;\n?>\n";

    #[Test]
    public function a_documented_name_first_read_through_a_guard_is_declared_in_a_try(): void
    {
        [$prelude, $body] = (new PreludeBuilder())->compose(self::OPTIONAL_BODY, [], '');

        $this->assertStringContainsString(
            "try { /** @var string \$label */ \$label = \$GLOBALS['label']; /** @var bool \$stacked */ \$stacked = \$GLOBALS['stacked']; /* unwritten */ } catch (\\Throwable) {}",
            $prelude,
        );
        $this->assertStringNotContainsString('@var mixed $label', $prelude);
        $this->assertStringNotContainsString('@var mixed $stacked', $prelude);

        // The body keeps its byte length and line breaks; only the lifted tags stop declaring.
        $this->assertSame(\strlen(self::OPTIONAL_BODY), \strlen($body));
        $this->assertStringNotContainsString('@var string $label', $body);
        $this->assertStringNotContainsString('@var bool $stacked', $body);
        $this->assertSame(\substr_count(self::OPTIONAL_BODY, "\n"), \substr_count($body, "\n"));
    }

    #[Test]
    public function other_names_in_the_same_docblock_keep_their_var(): void
    {
        $compiled = "<?php\n/**\n * @var string \$label\n * @var string \$name\n */\n\$label ??= '';\necho \$name;\n?>";

        [$prelude, $body] = (new PreludeBuilder())->compose($compiled, [], '');

        $this->assertStringContainsString('$label = $GLOBALS', $prelude);
        $this->assertStringContainsString('@var mixed $name */', $prelude);
        $this->assertStringContainsString('@var string $name', $body);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function declinedOptionalShapes(): iterable
    {
        yield 'first read unguarded' => ["<?php\n/** @var string \$x */\necho \$x;\n\$x ??= '';\n?>"];
        yield 'written before read' => ["<?php\n/** @var string \$x */\n\$x = 'a';\nisset(\$x);\n?>"];
        yield 'relative class name' => ["<?php\nuse App\\Models\\User;\n/** @var User \$x */\n\$x ??= null;\n?>"];
        yield 'declared twice' => ["<?php\n/** @var string \$x */\n/** @var int \$x */\n\$x ??= '';\n?>"];
        yield 'psalm-var tag' => ["<?php\n/** @psalm-var string \$x */\n\$x ??= '';\n?>"];
        yield 'ambient name' => ["<?php\n/** @var \\Illuminate\\View\\ComponentSlot \$slot */\necho \$slot ?? '';\n?>"];
        yield 'guard inside a closure' => ["<?php\n/** @var string \$x */\n\$f = function () { return \$x ?? ''; };\necho \$x;\n?>"];
        yield 'look-alike in a string' => ["<?php\necho '/** @var string \$x */';\n\$x ??= '';\n?>"];
        yield 'plain comment' => ["<?php\n/* @var string \$x */\n\$x ??= '';\n?>"];
        yield 'variable-variable write (@props)' => ["<?php\nforeach (['x' => ''] as \$__key => \$__value) { \$\$__key = \$\$__key ?? \$__value; }\n/** @var string \$y */\n\$y ??= '';\n?>"];
        yield 'extract' => ["<?php\nextract(\$data);\n/** @var string \$x */\n\$x ??= '';\n?>"];
        yield 'declared only inside a closure' => ["<?php\necho \$x ?? '';\n\$f = static function (mixed \$item): void {\n    /** @var \\DateTimeImmutable \$x */\n    \$x = \$item;\n};\n?>"];
        yield 'closure parameter of the same name' => ["<?php\n/** @var string \$x */\necho \$x ?? '';\n\$f = static fn(string \$x): string => \$x ?? '';\n?>"];
        yield 'by-ref closure use of the same name' => ["<?php\n/** @var string \$x */\necho \$x ?? '';\n\$f = function () use (&\$x) {};\n?>"];
        yield 'closure use of the same name' => ["<?php\n/** @var string \$x */\necho \$x ?? '';\n\$f = function () use (\$x) { return \$x; };\n?>"];
        yield 'catch variable inside a closure' => ["<?php\n/** @var string \$x */\necho \$x ?? '';\n\$f = function () { try {} catch (\\Throwable \$x) {} };\n?>"];
        yield 'docblock carries a suppression' => ["<?php\n/**\n * @var \\Missing\\Type \$x\n * @psalm-suppress UndefinedDocblockClass\n */\necho \$x ?? '';\n?>"];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('declinedOptionalShapes')]
    public function an_unproven_optional_shape_keeps_todays_prelude(string $compiled): void
    {
        [$prelude, $body] = (new PreludeBuilder())->compose($compiled, [], '');

        $this->assertStringNotContainsString('try {', $prelude);
        $this->assertSame($compiled, $body);
    }

    #[Test]
    public function lifts_optional_reads_only_the_prelude(): void
    {
        [$prelude, $body] = (new PreludeBuilder())->compose(self::OPTIONAL_BODY, [], '');
        $authorTry = "<?php try { \$name = \$GLOBALS['name']; } catch (\\Throwable) {} ?>";

        $this->assertTrue(PreludeBuilder::liftsOptional($prelude . $body, 'label'));
        $this->assertFalse(PreludeBuilder::liftsOptional($prelude . $body, 'lab'));
        $this->assertFalse(PreludeBuilder::liftsOptional($prelude . $body . "\n" . $authorTry, 'name'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function writtenOptionalShapes(): iterable
    {
        yield 'assigned' => ["\$x = 'a';"];
        yield 'unset' => ['unset($x);'];
        yield 'by-ref function argument' => ["\\preg_match('/a/', 'a', \$x);"];
        yield 'unknown call argument' => ['$__env->fill($x);'];
        yield 'global' => ['global $x;'];
        yield 'reference source' => ['$y = &$x;'];
        yield 'pre-increment' => ['++$x;'];
        yield 'post-decrement' => ['$x--;'];
        yield 'catch variable' => ['try {} catch (\\Throwable $x) {}'];
        yield 'short list destructuring' => ['[$x] = [1];'];
        yield 'foreach key' => ['foreach ([] as $x => $v) {}'];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('writtenOptionalShapes')]
    public function a_lifted_name_the_template_may_write_is_not_marked_unwritten(string $write): void
    {
        $compiled = "<?php\n/** @var string \$x */\necho \$x ?? '';\n{$write}\n?>";

        [$prelude, $body] = (new PreludeBuilder())->compose($compiled, [], '');

        $this->assertTrue(PreludeBuilder::liftsOptional($prelude . $body, 'x'));
        $this->assertFalse(PreludeBuilder::liftsUnwritten($prelude . $body, 'x'));
    }

    #[Test]
    public function a_lifted_name_only_read_or_passed_by_value_is_marked_unwritten(): void
    {
        $compiled = "<?php\n/** @var string \$x */\necho \$x ?? '';\necho \\e(\$x);\n?>";

        [$prelude, $body] = (new PreludeBuilder())->compose($compiled, [], '');

        $this->assertTrue(PreludeBuilder::liftsUnwritten($prelude . $body, 'x'));
        $this->assertFalse(PreludeBuilder::liftsUnwritten($prelude . $body, 'y'));
    }

    #[Test]
    public function the_opt_tag_offset_is_found_from_a_prelude_offset(): void
    {
        [$prelude, $body] = (new PreludeBuilder())->compose(self::OPTIONAL_BODY, [], '');
        $shadow = $prelude . $body;
        $inStacked = \strpos($shadow, '@var bool $stacked');
        $this->assertIsInt($inStacked);

        $offset = PreludeBuilder::optionalTagOffset($shadow, $inStacked);

        $this->assertSame([\strpos($shadow, "/**\n"), \strpos($shadow, '@opt bool $stacked')], $offset);
        $this->assertNull(PreludeBuilder::optionalTagOffset($shadow, \strlen($prelude) + 2));
    }

    #[Test]
    public function the_opt_tag_offset_ignores_the_name_in_another_tag_description(): void
    {
        $compiled = "<?php\n/**\n * @var string \$a Shown next to \$b\n * @var \\Missing \$b\n */\necho \$a ?? '';\necho \$b ?? '';\n?>";
        [$prelude, $body] = (new PreludeBuilder())->compose($compiled, [], '');
        $shadow = $prelude . $body;
        $inB = \strpos($shadow, '@var \\Missing $b');
        $this->assertIsInt($inB);

        $this->assertSame([\strpos($shadow, "/**\n"), \strpos($shadow, '@opt \\Missing $b')], PreludeBuilder::optionalTagOffset($shadow, $inB));
    }

    #[Test]
    public function only_the_declared_name_is_attributed_to_a_tag(): void
    {
        $compiled = "<?php\n/**\n * @var string \$e Shown next to \$f\n * @var string \$f\n */\necho \$e ?? '';\necho \$f ?? '';\n?>";

        [$prelude] = (new PreludeBuilder())->compose($compiled, [], '');

        $this->assertStringContainsString('$f = $GLOBALS', $prelude);
    }
}
