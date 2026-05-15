<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Tests\Unit\Blade;

use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Blade\BladeEchoKind;
use Psalm\LaravelPlugin\Blade\BladeTemplateScanner;
use Psalm\LaravelPlugin\Blade\BladeUncertaintyReason;
use Psalm\LaravelPlugin\Blade\BladeViewSafetyKind;

/**
 * Tests cover the public contract of {@see BladeTemplateScanner}: the tri-state
 * classification ({@see BladeViewSafetyKind}), the unsafe-key extraction, the
 * uncertainty enumeration, and the per-occurrence {@see BladeVariableUsage}
 * list returned by {@see BladeTemplateScanner::scan()}.
 *
 * Tests that pinned regex-era quirks (string-literal-inside-@php fooling the
 * scope-local regex, word-boundary edge cases on directive names, escaped-brace
 * stripping order) were deleted: the compiled-Blade backend processes those
 * inputs structurally, so the asymmetries no longer exist.
 */
final class BladeTemplateScannerTest extends TestCase
{
    private BladeTemplateScanner $scanner;

    #[\Override]
    protected function setUp(): void
    {
        $this->scanner = BladeTemplateScanner::withDefaults();
    }

    public function test_escaped_echo_is_classified_as_escaped(): void
    {
        $usages = $this->scanner->scan('<h1>{{ $title }}</h1>');

        $this->assertCount(1, $usages);
        $this->assertSame('title', $usages[0]->name);
        $this->assertSame(BladeEchoKind::Escaped, $usages[0]->kind);
    }

    public function test_raw_echo_is_classified_as_raw(): void
    {
        $usages = $this->scanner->scan('<div>{!! $html !!}</div>');

        $this->assertCount(1, $usages);
        $this->assertSame('html', $usages[0]->name);
        $this->assertSame(BladeEchoKind::Raw, $usages[0]->kind);
    }

    public function test_legacy_triple_brace_is_classified_as_escaped(): void
    {
        // Laravel compiles `{{{ $x }}}` to `echo e($x)`, same as `{{ $x }}`.
        $usages = $this->scanner->scan('<p>{{{ $legacy }}}</p>');

        $this->assertCount(1, $usages);
        $this->assertSame('legacy', $usages[0]->name);
        $this->assertSame(BladeEchoKind::Escaped, $usages[0]->kind);
    }

    public function test_blade_comments_are_ignored(): void
    {
        // `{{-- comment --}}` is stripped at compile time; the variable inside
        // never reaches an `echo`. Free correctness vs. the regex backend's
        // explicit strip pass.
        $usages = $this->scanner->scan('{{-- {!! $hidden !!} --}} {{ $visible }}');

        $this->assertCount(1, $usages);
        $this->assertSame('visible', $usages[0]->name);
    }

    public function test_verbatim_blocks_are_ignored(): void
    {
        $source = <<<'BLADE'
            @verbatim
                {!! $jsTemplate !!}
            @endverbatim
            {{ $real }}
            BLADE;

        $usages = $this->scanner->scan($source);

        $this->assertCount(1, $usages);
        $this->assertSame('real', $usages[0]->name);
    }

    public function test_escaped_braces_are_ignored(): void
    {
        // `@{{ $x }}` renders literally as `{{ $x }}` (Vue/Alpine pattern).
        $usages = $this->scanner->scan('@{{ $vuetemplate }} {{ $real }}');

        $this->assertCount(1, $usages);
        $this->assertSame('real', $usages[0]->name);
    }

    public function test_escaped_raw_brace_is_ignored(): void
    {
        $usages = $this->scanner->scan('@{!! $literal !!} {{ $real }}');

        $this->assertCount(1, $usages);
        $this->assertSame('real', $usages[0]->name);
    }

    public function test_foreach_alias_is_not_treated_as_view_data_key(): void
    {
        // `$item` is bound by @foreach, not by the view() data array.
        $source = <<<'BLADE'
            @foreach ($items as $item)
                {!! $item !!}
            @endforeach
            BLADE;

        $analysis = $this->scanner->analyze($source);

        $this->assertSame(BladeViewSafetyKind::Safe, $analysis->kind);
        $this->assertSame([], $analysis->unsafeKeys);
    }

    public function test_foreach_key_value_aliases_are_excluded(): void
    {
        $source = <<<'BLADE'
            @foreach ($rows as $key => $row)
                {!! $row !!} {{ $key }}
            @endforeach
            BLADE;

        $analysis = $this->scanner->analyze($source);

        $this->assertSame(BladeViewSafetyKind::Safe, $analysis->kind);
    }

    public function test_forelse_alias_is_excluded(): void
    {
        $source = <<<'BLADE'
            @forelse ($posts as $post)
                {!! $post !!}
            @empty
                <p>None</p>
            @endforelse
            BLADE;

        $this->assertSame(BladeViewSafetyKind::Safe, $this->scanner->analyze($source)->kind);
    }

    public function test_inline_assignment_in_if_condition_is_excluded(): void
    {
        // The AST walker sees the {@see \PhpParser\Node\Expr\Assign} inside
        // the {@see \PhpParser\Node\Stmt\If_::$cond} and registers `$rendered`
        // as a scope-local. Subsequent raw echo of `$rendered` is not surfaced
        // as an unsafe view-data key.
        $source = <<<'BLADE'
            @if ($rendered = markdown($post->body))
                {!! $rendered !!}
            @endif
            BLADE;

        $this->assertSame(BladeViewSafetyKind::Safe, $this->scanner->analyze($source)->kind);
    }

    public function test_complex_inline_assignment_resolves_dependency(): void
    {
        // Regression for the regex-era known-limitation: assignments mid-condition
        // (after a `count(...)` call) used to leak `$rendered` as an unsafe key.
        // The AST walker now sees the {@see \PhpParser\Node\Expr\Assign}
        // regardless of position.
        $source = "@if (count(\$rows) > 0 && \$rendered = compute())\n{!! \$rendered !!}\n@endif";

        $analysis = $this->scanner->analyze($source);

        $this->assertSame(BladeViewSafetyKind::Safe, $analysis->kind);
        $this->assertSame([], $analysis->unsafeKeys);
    }

    public function test_inline_php_directive_resolves_dependency(): void
    {
        /*
         * Regression for the regex-era known-limitation: the inline `@php(...)`
         * directive wasn't matched by the multi-line `@php ... @endphp` regex.
         * BladeCompiler turns `@php($foo = 'x')` into a normal PHP statement,
         * and the AST walker registers `$foo` as a scope-local.
         */
        $source = "@php(\$foo = 'x')\n{!! \$foo !!}";

        $analysis = $this->scanner->analyze($source);

        $this->assertSame(BladeViewSafetyKind::Safe, $analysis->kind);
        $this->assertSame([], $analysis->unsafeKeys);
    }

    public function test_property_access_reports_top_level_variable_only(): void
    {
        $usages = $this->scanner->scan('{!! $user->bio !!}');

        $this->assertCount(1, $usages);
        $this->assertSame('user', $usages[0]->name);
    }

    public function test_array_access_reports_top_level_variable_only(): void
    {
        $usages = $this->scanner->scan('{!! $data["html"] !!}');

        $this->assertCount(1, $usages);
        $this->assertSame('data', $usages[0]->name);
    }

    public function test_multi_line_echo_is_handled(): void
    {
        $source = "{!!\n\$wrapped\n!!}";

        $analysis = $this->scanner->analyze($source);

        $this->assertSame(['wrapped'], $analysis->unsafeKeys);
    }

    public function test_multiple_echoes_on_one_line(): void
    {
        $usages = $this->scanner->scan('{{ $a }} and {!! $b !!}');

        $byName = [];

        foreach ($usages as $usage) {
            $byName[$usage->name] = $usage;
        }

        $this->assertSame(BladeEchoKind::Escaped, $byName['a']->kind);
        $this->assertSame(BladeEchoKind::Raw, $byName['b']->kind);
    }

    public function test_analyze_collects_unsafe_keys_from_raw_echoes(): void
    {
        $source = <<<'BLADE'
            {{ $safe }}
            {!! $raw !!}
            BLADE;

        $unsafe = $this->scanner->analyze($source)->unsafeKeys;

        $this->assertSame(['raw'], $unsafe);
    }

    public function test_php_block_echo_classified_as_unsafe(): void
    {
        /*
         * `@php echo $x; @endphp` compiles to the same AST shape as
         * `{!! $x !!}`. The walker treats every Stmt\Echo_ node uniformly;
         * the distinction between BladeEchoKind::PhpBlock and BladeEchoKind::Raw
         * is no longer observable at compile time and collapses to RAW for
         * {@see BladeTemplateScanner::scan()}.
         */
        $source = "@php echo \$dangerous; @endphp";

        $analysis = $this->scanner->analyze($source);

        $this->assertSame(['dangerous'], $analysis->unsafeKeys);
    }

    public function test_php_block_assignment_does_not_become_unsafe_key(): void
    {
        // `@php $note = ...; @endphp` is purely an assignment — no echo. The
        // regex backend used to surface `note` as an unsafe key because it
        // collected every `$var` reference inside a @php block. The AST
        // walker only records actual echoes.
        $source = "@php \$note = 'hello'; @endphp\n<p>literal</p>";

        $analysis = $this->scanner->analyze($source);

        $this->assertSame(BladeViewSafetyKind::Safe, $analysis->kind);
        $this->assertSame([], $analysis->unsafeKeys);
    }

    public function test_same_variable_in_safe_and_raw_contexts_is_reported_unsafe(): void
    {
        // If ANY echo of a variable is raw, the key must surface as unsafe.
        $analysis = $this->scanner->analyze('{{ $title }} ... {!! $title !!}');

        $this->assertSame(BladeViewSafetyKind::UnsafeKeys, $analysis->kind);
        $this->assertSame(['title'], $analysis->unsafeKeys);
    }

    public function test_no_variables_returns_safe(): void
    {
        $this->assertSame([], $this->scanner->scan('<p>Hello, world!</p>'));

        $analysis = $this->scanner->analyze('<p>Hello, world!</p>');

        $this->assertSame(BladeViewSafetyKind::Safe, $analysis->kind);
        $this->assertSame([], $analysis->unsafeKeys);
        $this->assertSame([], $analysis->uncertainties);
    }

    public function test_all_safe_template_is_classified_safe(): void
    {
        $source = <<<'BLADE'
            <h1>{{ $title }}</h1>
            <p>{{ $user->name }}</p>
            @foreach ($posts as $post)
                <li>{{ $post->title }}</li>
            @endforeach
            BLADE;

        $analysis = $this->scanner->analyze($source);

        $this->assertSame(BladeViewSafetyKind::Safe, $analysis->kind);
        $this->assertSame([], $analysis->unsafeKeys);
    }

    public function test_multiple_variables_in_single_echo(): void
    {
        $usages = $this->scanner->scan('{!! $a . $b . $c !!}');

        $names = \array_map(static fn(\Psalm\LaravelPlugin\Blade\BladeVariableUsage $u): string => $u->name, $usages);

        \sort($names);

        $this->assertSame(['a', 'b', 'c'], $names);
    }

    public function test_known_safe_wrapper_e_is_recognized(): void
    {
        // `{!! e($x) !!}` is RAW-echoed-but-safely-wrapped Blade. The walker
        // detects {@see \PhpParser\Node\Expr\FuncCall} with name `e` inside
        // the echo and classifies it as ESCAPED.
        $analysis = $this->scanner->analyze('{!! e($safe) !!}');

        $this->assertSame(BladeViewSafetyKind::Safe, $analysis->kind);
    }

    public function test_known_safe_wrapper_htmlspecialchars_is_recognized(): void
    {
        $analysis = $this->scanner->analyze('{!! htmlspecialchars($safe) !!}');

        $this->assertSame(BladeViewSafetyKind::Safe, $analysis->kind);
    }

    public function test_known_safe_wrapper_htmlentities_is_recognized(): void
    {
        $analysis = $this->scanner->analyze('{!! htmlentities($safe) !!}');

        $this->assertSame(BladeViewSafetyKind::Safe, $analysis->kind);
    }

    public function test_unknown_function_call_in_raw_echo_surfaces_argument_variable(): void
    {
        // `{!! random_fn($x) !!}` — the walker has no taint-escape annotation
        // for `random_fn`, so it conservatively surfaces top-level vars from
        // the call's arguments as unsafe keys.
        $analysis = $this->scanner->analyze('{!! random_fn($x) !!}');

        $this->assertSame(['x'], $analysis->unsafeKeys);
    }

    public function test_section_directive_marks_template_unknown(): void
    {
        /*
         * `@section('title', $dynamic)` compiles to a `$__env->startSection(...)`
         * call. The {@see \PhpParser\Node\Expr\MethodCall} on `$__env`
         * signals LAYOUT_SECTION_FLOW.
         */
        $source = "@section('title', \$dynamic)\n@stop";

        $analysis = $this->scanner->analyze($source);

        $this->assertSame(BladeViewSafetyKind::Unknown, $analysis->kind);
        $this->assertContains(BladeUncertaintyReason::LayoutSectionFlow, $analysis->uncertainties);
    }

    public function test_extends_directive_marks_template_unknown(): void
    {
        // `@extends('layouts.app')` adds a `$__env->make(...)->render()` call
        // to the compiled output's footer. The make() call is mapped to
        // INCLUDE_DIRECTIVE; either uncertainty triggers the same UNKNOWN
        // fallback policy at the handler layer, so the spec-mapping of
        // make → INCLUDE is acceptable.
        $source = <<<'BLADE'
            @extends('layouts.app')
            @section('content')
                <p>{{ $title }}</p>
            @endsection
            BLADE;

        $analysis = $this->scanner->analyze($source);

        $this->assertSame(BladeViewSafetyKind::Unknown, $analysis->kind);
        $this->assertNotEmpty($analysis->uncertainties);
    }

    public function test_yield_directive_marks_template_unknown(): void
    {
        $analysis = $this->scanner->analyze("<body>@yield('content')</body>");

        $this->assertSame(BladeViewSafetyKind::Unknown, $analysis->kind);
        $this->assertContains(BladeUncertaintyReason::LayoutSectionFlow, $analysis->uncertainties);
    }

    public function test_stack_directive_marks_template_unknown(): void
    {
        $analysis = $this->scanner->analyze("<head>@stack('scripts')</head>");

        $this->assertSame(BladeViewSafetyKind::Unknown, $analysis->kind);
        $this->assertContains(BladeUncertaintyReason::LayoutSectionFlow, $analysis->uncertainties);
    }

    public function test_push_directive_marks_template_unknown(): void
    {
        $source = <<<'BLADE'
            @push('scripts')
                <script>console.log({!! $debug !!});</script>
            @endpush
            BLADE;

        $analysis = $this->scanner->analyze($source);

        $this->assertSame(BladeViewSafetyKind::Unknown, $analysis->kind);
        $this->assertContains(BladeUncertaintyReason::LayoutSectionFlow, $analysis->uncertainties);
    }

    public function test_prepend_directive_marks_template_unknown(): void
    {
        $analysis = $this->scanner->analyze("@prepend('foo')bar@endprepend");

        $this->assertSame(BladeViewSafetyKind::Unknown, $analysis->kind);
        $this->assertContains(BladeUncertaintyReason::LayoutSectionFlow, $analysis->uncertainties);
    }

    public function test_include_directive_marks_template_unknown(): void
    {
        $analysis = $this->scanner->analyze("@include('partials.user', ['html' => \$html])");

        $this->assertSame(BladeViewSafetyKind::Unknown, $analysis->kind);
        $this->assertContains(BladeUncertaintyReason::IncludeDirective, $analysis->uncertainties);
    }

    public function test_include_when_directive_marks_template_unknown(): void
    {
        $analysis = $this->scanner->analyze("@includeWhen(\$cond, 'partials.flash')");

        $this->assertSame(BladeViewSafetyKind::Unknown, $analysis->kind);
        $this->assertContains(BladeUncertaintyReason::IncludeDirective, $analysis->uncertainties);
    }

    public function test_each_directive_marks_template_unknown(): void
    {
        $analysis = $this->scanner->analyze("@each('partials.user', \$users, 'user', 'empty')");

        $this->assertSame(BladeViewSafetyKind::Unknown, $analysis->kind);
        $this->assertContains(BladeUncertaintyReason::IncludeDirective, $analysis->uncertainties);
    }

    public function test_dash_form_component_tag_marks_template_unknown(): void
    {
        $analysis = $this->scanner->analyze('<x-alert :message="$message" />');

        $this->assertSame(BladeViewSafetyKind::Unknown, $analysis->kind);
        $this->assertContains(BladeUncertaintyReason::ComponentTag, $analysis->uncertainties);
    }

    public function test_colon_form_component_tag_marks_template_unknown(): void
    {
        // Laravel's ComponentTagCompiler accepts both `<x-foo>` and `<x:foo>`.
        $analysis = $this->scanner->analyze('<x:alert :message="$message" />');

        $this->assertSame(BladeViewSafetyKind::Unknown, $analysis->kind);
        $this->assertContains(BladeUncertaintyReason::ComponentTag, $analysis->uncertainties);
    }

    public function test_namespaced_component_tag_marks_template_unknown(): void
    {
        $analysis = $this->scanner->analyze('<x-mail::message>hi</x-mail::message>');

        $this->assertSame(BladeViewSafetyKind::Unknown, $analysis->kind);
        $this->assertContains(BladeUncertaintyReason::ComponentTag, $analysis->uncertainties);
    }

    public function test_component_directive_marks_template_unknown(): void
    {
        $source = <<<'BLADE'
            @component('alert')
                @slot('title') {{ $title }} @endslot
            @endcomponent
            BLADE;

        $analysis = $this->scanner->analyze($source);

        $this->assertSame(BladeViewSafetyKind::Unknown, $analysis->kind);
        $this->assertContains(BladeUncertaintyReason::ComponentTag, $analysis->uncertainties);
    }

    public function test_inject_binds_a_scope_local(): void
    {
        /*
         * `@inject('renderer', ...)` compiles to a `$renderer = app(...);`
         * statement. The {@see \PhpParser\Node\Expr\Assign} captures
         * `$renderer` as a scope-local, so a raw echo of
         * `$renderer->render($body)` surfaces only the data-driven `body`,
         * not `renderer`.
         */
        $source = "@inject('renderer', App\\Renderer::class)\n{!! \$renderer->render(\$body) !!}";

        $analysis = $this->scanner->analyze($source);

        $this->assertContains('body', $analysis->unsafeKeys);
        $this->assertNotContains('renderer', $analysis->unsafeKeys);
    }

    public function test_inject_with_double_quoted_name_binds_local(): void
    {
        $source = "@inject(\"renderer\", App\\Renderer::class)\n{!! \$renderer !!}";

        $analysis = $this->scanner->analyze($source);

        $this->assertNotContains('renderer', $analysis->unsafeKeys);
    }

    public function test_directive_shaped_string_inside_php_block_does_not_trigger_uncertainty(): void
    {
        // BladeCompiler stores `@php` bodies as raw blocks before any other
        // directive matching runs, so directive-shaped string literals inside
        // are never seen as directives. The visitor only sees the assignment.
        $source = "@php \$s = '@yield(1)'; @endphp\n<p>Hello</p>";

        $analysis = $this->scanner->analyze($source);

        $this->assertNotContains(BladeUncertaintyReason::LayoutSectionFlow, $analysis->uncertainties);
    }

    public function test_component_shaped_string_inside_php_block_does_not_trigger_uncertainty(): void
    {
        $source = "@php \$s = '<x-foo>'; @endphp\n<p>Hi</p>";

        $analysis = $this->scanner->analyze($source);

        $this->assertNotContains(BladeUncertaintyReason::ComponentTag, $analysis->uncertainties);
    }

    public function test_include_shaped_string_inside_php_block_does_not_trigger_uncertainty(): void
    {
        $source = "@php \$s = '@include(\"x\")'; @endphp\n<p>Hi</p>";

        $analysis = $this->scanner->analyze($source);

        $this->assertNotContains(BladeUncertaintyReason::IncludeDirective, $analysis->uncertainties);
    }

    public function test_foreach_shaped_string_inside_php_block_does_not_fake_scope_local(): void
    {
        // Critical security regression: a directive-shaped string literal
        // inside `@php` must not register fake scope-locals. Compile-time
        // raw-block storage isolates the string, and `$y` is correctly
        // surfaced as unsafe by the later raw echo.
        $source = "@php \$s = '@foreach (\$x as \$y)'; @endphp\n{!! \$y !!}";

        $analysis = $this->scanner->analyze($source);

        $this->assertContains('y', $analysis->unsafeKeys);
    }

    public function test_layout_unknown_preserves_observed_unsafe_keys(): void
    {
        // UNKNOWN dominates the kind, but observed unsafe keys still appear
        // in the analysis so handlers can surface them in diagnostics.
        $source = <<<'BLADE'
            @extends('layouts.app')
            @section('body')
                {!! $bio !!}
            @endsection
            BLADE;

        $analysis = $this->scanner->analyze($source);

        $this->assertSame(BladeViewSafetyKind::Unknown, $analysis->kind);
        $this->assertSame(['bio'], $analysis->unsafeKeys);
    }

    public function test_string_interpolation_inside_raw_echo_surfaces_inner_names(): void
    {
        /*
         * `{!! "hello {$attacker}" !!}` compiles to an Echo wrapping a
         * Scalar\InterpolatedString node. The walker recurses into its parts
         * and surfaces every embedded variable as unsafe.
         */
        $analysis = $this->scanner->analyze('{!! "hello {$attacker}" !!}');

        $this->assertSame(['attacker'], $analysis->unsafeKeys);
    }

    public function test_extends_first_directive_marks_template_unknown(): void
    {
        // `@extendsFirst([...])` compiles to a `$__env->first(...)->render()`
        // call appended as a footer. The walker sees the cross-template flow.
        $analysis = $this->scanner->analyze("@extendsFirst(['mobile.layout', 'desktop.layout'])");

        $this->assertSame(BladeViewSafetyKind::Unknown, $analysis->kind);
        $this->assertNotEmpty($analysis->uncertainties);
    }

    public function test_has_stack_directive_marks_template_unknown(): void
    {
        $analysis = $this->scanner->analyze("@hasStack('scripts')\n  <div></div>\n@endif");

        $this->assertSame(BladeViewSafetyKind::Unknown, $analysis->kind);
        $this->assertContains(BladeUncertaintyReason::LayoutSectionFlow, $analysis->uncertainties);
    }

    public function test_malformed_blade_returns_unknown(): void
    {
        // BladeCompiler throws on unbalanced directives. The scanner catches
        // and emits UNKNOWN(UNPARSABLE_PHP_BLOCK).
        $source = "@php this is not valid php @endphp";

        $analysis = $this->scanner->analyze($source);

        $this->assertSame(BladeViewSafetyKind::Unknown, $analysis->kind);
        $this->assertContains(BladeUncertaintyReason::UnparsablePhpBlock, $analysis->uncertainties);
    }

    public function test_unclosed_php_block_returns_unknown(): void
    {
        // BladeCompiler's storePhpBlocks regex requires a matching `@endphp`,
        // so an unclosed `@php` falls through as literal text in compiled
        // output. The scanner pre-detects the imbalance and emits
        // UNKNOWN(UNPARSABLE_PHP_BLOCK) instead of letting the unclosed
        // region slip through as SAFE.
        $source = "@php \$x = 1; // forgot endphp\n{{ \$y }}";

        $analysis = $this->scanner->analyze($source);

        $this->assertSame(BladeViewSafetyKind::Unknown, $analysis->kind);
        $this->assertContains(BladeUncertaintyReason::UnparsablePhpBlock, $analysis->uncertainties);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function includeFamilyDirectiveProvider(): iterable
    {
        yield 'include' => ["@include('p.x')"];
        yield 'includeIf' => ["@includeIf('p.x')"];
        yield 'includeWhen' => ['@includeWhen($cond, \'p.x\')'];
        yield 'includeUnless' => ['@includeUnless($cond, \'p.x\')'];
        yield 'includeFirst' => ["@includeFirst(['a', 'b'])"];
        yield 'each' => ['@each(\'p.row\', $rows, \'row\')'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('includeFamilyDirectiveProvider')]
    public function test_include_family_directive_marks_template_unknown(string $source): void
    {
        $analysis = $this->scanner->analyze($source);

        $this->assertSame(BladeViewSafetyKind::Unknown, $analysis->kind);
        $this->assertContains(BladeUncertaintyReason::IncludeDirective, $analysis->uncertainties);
    }

    public function test_raw_echo_of_string_cast_surfaces_inner_variable(): void
    {
        // `(string) $tainted` does not sanitize HTML. Raw echo must
        // surface `tainted` as an unsafe key.
        $analysis = $this->scanner->analyze('{!! (string) $tainted !!}');

        $this->assertSame(BladeViewSafetyKind::UnsafeKeys, $analysis->kind);
        $this->assertSame(['tainted'], $analysis->unsafeKeys);
    }

    public function test_raw_echo_of_negation_surfaces_inner_variable(): void
    {
        $analysis = $this->scanner->analyze('{!! !$tainted !!}');

        $this->assertSame(BladeViewSafetyKind::UnsafeKeys, $analysis->kind);
        $this->assertSame(['tainted'], $analysis->unsafeKeys);
    }

    public function test_raw_echo_of_array_literal_surfaces_inner_variables(): void
    {
        $analysis = $this->scanner->analyze('{!! [$attacker][0] !!}');

        $this->assertSame(BladeViewSafetyKind::UnsafeKeys, $analysis->kind);
        $this->assertSame(['attacker'], $analysis->unsafeKeys);
    }

    public function test_raw_echo_of_match_expression_surfaces_inner_variables(): void
    {
        $source = '{!! match($t) { "html" => $rawHtml, default => "" } !!}';

        $analysis = $this->scanner->analyze($source);

        $this->assertSame(BladeViewSafetyKind::UnsafeKeys, $analysis->kind);
        $this->assertContains('rawHtml', $analysis->unsafeKeys);
    }

    public function test_raw_echo_of_global_helper_call_marks_template_unknown(): void
    {
        // `{!! request()->input(...) !!}` is a canonical XSS source. The
        // scanner cannot enumerate top-level vars from a global helper chain,
        // so it conservatively classifies the template UNKNOWN with
        // UNKNOWN_LOCAL_DEPENDENCY.
        $analysis = $this->scanner->analyze("{!! request()->input('html') !!}");

        $this->assertSame(BladeViewSafetyKind::Unknown, $analysis->kind);
        $this->assertContains(BladeUncertaintyReason::UnknownLocalDependency, $analysis->uncertainties);
    }

    public function test_variable_variable_raw_echo_marks_template_unknown(): void
    {
        // `{!! $$name !!}` and `{!! ${$expr} !!}` cannot be tracked to a
        // top-level data key. The conservative fallback in classifyEcho
        // surfaces this as UNKNOWN_LOCAL_DEPENDENCY instead of letting it
        // slip through as SAFE.
        $analysis = $this->scanner->analyze('{!! $$name !!}');

        $this->assertSame(BladeViewSafetyKind::Unknown, $analysis->kind);
        $this->assertContains(BladeUncertaintyReason::UnknownLocalDependency, $analysis->uncertainties);
    }

    public function test_raw_echo_of_closure_invocation_marks_template_unknown(): void
    {
        // A closure result is opaque to the scanner; conservatively UNKNOWN.
        $analysis = $this->scanner->analyze("{!! (fn () => 'x')() !!}");

        $this->assertSame(BladeViewSafetyKind::Unknown, $analysis->kind);
        $this->assertContains(BladeUncertaintyReason::UnknownLocalDependency, $analysis->uncertainties);
    }

    public function test_raw_echo_of_string_literal_remains_safe(): void
    {
        // A literal raw echo carries no taint; the conservative fallback
        // must not over-fire on this case.
        $analysis = $this->scanner->analyze('{!! "literal" !!}');

        $this->assertSame(BladeViewSafetyKind::Safe, $analysis->kind);
    }

    public function test_foreach_destructuring_value_var_binds_inner_locals(): void
    {
        // `@foreach ($pairs as [$k, $v]) {!! $v !!} @endforeach` — `$v` is
        // bound by the destructuring, not by the view data array.
        $source = <<<'BLADE'
            @foreach ($pairs as [$k, $v])
                {!! $v !!} {{ $k }}
            @endforeach
            BLADE;

        $analysis = $this->scanner->analyze($source);

        $this->assertSame(BladeViewSafetyKind::Safe, $analysis->kind);
        $this->assertSame([], $analysis->unsafeKeys);
    }

    public function test_nested_destructuring_in_php_block_binds_inner_locals(): void
    {
        $source = "@php [\$a, [\$b, \$c]] = \$data; @endphp\n{!! \$b !!}";

        $analysis = $this->scanner->analyze($source);

        $this->assertSame(BladeViewSafetyKind::Safe, $analysis->kind);
        $this->assertNotContains('b', $analysis->unsafeKeys);
    }

    public function test_e_with_two_arguments_is_not_treated_as_safe(): void
    {
        // `e($x, false)` disables double-encoding and is no longer a sound
        // HTML wrapper. The walker falls back to RAW classification.
        $analysis = $this->scanner->analyze('{!! e($x, false) !!}');

        $this->assertSame(BladeViewSafetyKind::UnsafeKeys, $analysis->kind);
        $this->assertSame(['x'], $analysis->unsafeKeys);
    }

    public function test_js_from_chained_to_html_is_treated_as_safe(): void
    {
        // `Js::from($data)->toHtml()` continues to produce HTML-safe output.
        $analysis = $this->scanner->analyze('{!! ' . \Illuminate\Support\Js::class . '::from($data)->toHtml() !!}');

        $this->assertSame(BladeViewSafetyKind::Safe, $analysis->kind);
    }

    public function test_multiple_uncertainties_accumulate_independently(): void
    {
        // The visitor collects every uncertainty it sees, without short-circuiting.
        $source = <<<'BLADE'
            @yield('content')
            @include('partials.alert')
            <x-button :label="$label" />
            BLADE;

        $uncertainties = $this->scanner->analyze($source)->uncertainties;

        $this->assertContains(BladeUncertaintyReason::LayoutSectionFlow, $uncertainties);
        $this->assertContains(BladeUncertaintyReason::IncludeDirective, $uncertainties);
        $this->assertContains(BladeUncertaintyReason::ComponentTag, $uncertainties);
    }
}
