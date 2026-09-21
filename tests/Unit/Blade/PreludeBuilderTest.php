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
        $prelude = (new PreludeBuilder())->build('<?php echo 1; ?>', [], '');

        foreach (['__env', 'errors', 'loop'] as $name) {
            $this->assertStringContainsString("\${$name} */", $prelude);
        }
    }

    #[Test]
    public function component_is_never_declared_as_a_class(): void
    {
        // Laravel never passes `$component` as view data (only ManagesComponents::componentData()'s
        // slot/data merge reaches a view); it is only a local in the CALLER's compiled output.
        $prelude = (new PreludeBuilder())->build('<?php if (isset($component)) {} ?>', [], '');

        $this->assertStringNotContainsString('Illuminate\View\Component ', $prelude);
        $this->assertStringContainsString('@var mixed $component */', $prelude);
    }

    #[Test]
    public function attributes_and_slot_are_not_declared_outside_a_component_view(): void
    {
        $prelude = (new PreludeBuilder())->build(
            '<?php if (isset($attributes)) {} ?>',
            [],
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
            [],
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
        $prelude = (new PreludeBuilder())->build('<?php echo 1; ?>', [], "@aware(['type'])\n{{ \$attributes }}");

        $this->assertStringContainsString('@var \Illuminate\View\ComponentAttributeBag $attributes */', $prelude);
        $this->assertStringNotContainsString('?\Illuminate\View\ComponentAttributeBag', $prelude);
    }

    #[Test]
    public function a_bare_attributes_mention_without_props_declares_attributes_non_null(): void
    {
        $prelude = (new PreludeBuilder())->build('<?php echo 1; ?>', [], '{{ $attributes->class(["x"]) }}');

        $this->assertStringContainsString('@var \Illuminate\View\ComponentAttributeBag $attributes */', $prelude);
        $this->assertStringNotContainsString('?\Illuminate\View\ComponentAttributeBag', $prelude);
    }

    #[Test]
    public function a_bare_slot_mention_alone_declares_only_slot(): void
    {
        // The @component-directive render path agrees with <x-*>: both hand a ComponentSlot
        // (ManagesComponents::componentData()), so a lone `$slot` mention is enough either way.
        $prelude = (new PreludeBuilder())->build('<?php echo 1; ?>', [], '<div>{{ $slot }}</div>');

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
            [],
            '<?php $attributesFoo = []; $slots_count = 0; ?>',
        );

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
        $prelude = (new PreludeBuilder())->build('<?php echo 1; ?>', [], "@PROPS(['type' => 'info'])");

        $this->assertStringContainsString('@var ?\Illuminate\View\ComponentAttributeBag $attributes */', $prelude);
    }

    #[Test]
    public function aware_directive_matching_is_case_insensitive(): void
    {
        $prelude = (new PreludeBuilder())->build('<?php echo 1; ?>', [], "@AWARE(['type'])");

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
        $prelude = (new PreludeBuilder())->build('<?php echo 1; ?>', [], $source);

        $this->assertStringContainsString('@var \Illuminate\View\ComponentAttributeBag $attributes */', $prelude);
        $this->assertStringNotContainsString('?\Illuminate\View\ComponentAttributeBag', $prelude);
    }

    #[Test]
    public function an_escaped_props_directive_is_not_a_live_directive(): void
    {
        // `@@props(...)` compiles to the literal text `@props(...)`: compileStatements() sees the
        // leading `@` and echoes the rest verbatim instead of dispatching compileProps().
        $source = "@@props(['type' => 'info'])\n{{ \$attributes->merge([]) }}";
        $prelude = (new PreludeBuilder())->build('<?php echo 1; ?>', [], $source);

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
        $prelude = (new PreludeBuilder())->build('<?php echo 1; ?>', [], "@props(['type' => 'info'])\n<div>no mention</div>");

        $this->assertStringContainsString('@var \Illuminate\View\ComponentSlot $slot */', $prelude);
    }

    #[Test]
    public function an_aware_view_declares_slot_without_mentioning_it(): void
    {
        $prelude = (new PreludeBuilder())->build('<?php echo 1; ?>', [], "@aware(['type'])\n<div>no mention</div>");

        $this->assertStringContainsString('@var \Illuminate\View\ComponentSlot $slot */', $prelude);
    }

    #[Test]
    public function a_bare_attributes_mention_alone_does_not_declare_slot(): void
    {
        // Mention-based recognition is a heuristic over a name the template merely happens to use;
        // only a live `@props`/`@aware` directive is proof enough to declare a name never written.
        $prelude = (new PreludeBuilder())->build('<?php echo 1; ?>', [], '{{ $attributes->class(["x"]) }}');

        $this->assertStringNotContainsString('ComponentSlot', $prelude);
    }

    #[Test]
    public function includes_contract_vars_with_given_type(): void
    {
        $prelude = (new PreludeBuilder())->build('<?php echo 1; ?>', ['user' => '\App\Models\User'], '');

        $this->assertStringContainsString('@var \App\Models\User $user */', $prelude);
    }

    #[Test]
    public function undeclared_variable_gets_var_mixed(): void
    {
        $prelude = (new PreludeBuilder())->build('<?php echo $foo; ?>', [], '');

        $this->assertStringContainsString('@var mixed $foo */', $prelude);
    }

    #[Test]
    public function underscore_prefixed_variables_are_excluded(): void
    {
        $prelude = (new PreludeBuilder())->build('<?php echo $__key; ?>', [], '');

        $this->assertStringNotContainsString('$__key', $prelude);
    }

    #[Test]
    public function declared_variables_are_not_duplicated_as_mixed(): void
    {
        $prelude = (new PreludeBuilder())->build('<?php echo $errors; ?>', [], '');

        $this->assertSame(1, \substr_count($prelude, '$errors'));
    }

    #[Test]
    public function wraps_content_in_a_single_php_block(): void
    {
        $prelude = (new PreludeBuilder())->build('<?php echo 1; ?>', [], '');

        $this->assertSame(1, \substr_count($prelude, '<?php'));
        $this->assertSame(1, \substr_count($prelude, '?>'));
    }
}
