<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Tests\Unit\Blade;

use function count;

use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Blade\BladeEchoKind;
use Psalm\LaravelPlugin\Blade\BladeTemplateScanner;

final class BladeTemplateScannerTest extends TestCase
{
    public function test_escaped_echo_is_classified_as_escaped(): void
    {
        $usages = BladeTemplateScanner::scan('<h1>{{ $title }}</h1>');

        $this->assertCount(1, $usages);
        $this->assertSame('title', $usages[0]->name);
        $this->assertSame(BladeEchoKind::ESCAPED, $usages[0]->kind);
    }

    public function test_raw_echo_is_classified_as_raw(): void
    {
        $usages = BladeTemplateScanner::scan('<div>{!! $html !!}</div>');

        $this->assertCount(1, $usages);
        $this->assertSame('html', $usages[0]->name);
        $this->assertSame(BladeEchoKind::RAW, $usages[0]->kind);
    }

    public function test_legacy_triple_brace_is_classified_as_escaped(): void
    {
        $usages = BladeTemplateScanner::scan('<p>{{{ $legacy }}}</p>');

        $this->assertCount(1, $usages);
        $this->assertSame('legacy', $usages[0]->name);
        $this->assertSame(BladeEchoKind::ESCAPED, $usages[0]->kind);
    }

    public function test_php_block_classifies_variables_as_php_block(): void
    {
        $source = <<<'BLADE'
            @php
                echo $dangerous;
            @endphp
            BLADE;

        $usages = BladeTemplateScanner::scan($source);

        $this->assertCount(1, $usages);
        $this->assertSame('dangerous', $usages[0]->name);
        $this->assertSame(BladeEchoKind::PHP_BLOCK, $usages[0]->kind);
    }

    public function test_raw_echo_literal_inside_php_string_is_not_mis_matched(): void
    {
        // Regression: before the @php-blank pass, the raw-echo regex would
        // match the literal `{!! pwned !!}` inside the PHP string and emit
        // `pwned` as an unsafe variable.
        $source = <<<'BLADE'
            @php $note = "Text with {!! pwned !!} brace-literal"; @endphp
            BLADE;

        $unsafe = BladeTemplateScanner::unsafeVariables($source);

        $this->assertSame(['note'], $unsafe);
    }

    public function test_blade_comments_are_ignored(): void
    {
        $source = '{{-- {!! $hidden !!} --}} {{ $visible }}';

        $usages = BladeTemplateScanner::scan($source);

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

        $usages = BladeTemplateScanner::scan($source);

        $this->assertCount(1, $usages);
        $this->assertSame('real', $usages[0]->name);
    }

    public function test_unclosed_verbatim_block_strips_to_end_of_file(): void
    {
        // Real Blade compiler would throw on an unclosed @verbatim; the
        // scanner should fail safe by treating everything after the opening
        // directive as literal text, not by leaking variables from it.
        $source = "@verbatim\n{!! \$leaked !!}\nno closing tag";

        $this->assertSame([], BladeTemplateScanner::unsafeVariables($source));
    }

    public function test_escaped_braces_are_ignored(): void
    {
        // `@{{ $x }}` is rendered literally as `{{ $x }}` by Blade — it's a
        // common pattern for Vue/Alpine templates. No PHP variable is emitted.
        $usages = BladeTemplateScanner::scan('@{{ $vuetemplate }} {{ $real }}');

        $this->assertCount(1, $usages);
        $this->assertSame('real', $usages[0]->name);
    }

    public function test_escaped_raw_brace_is_ignored(): void
    {
        $usages = BladeTemplateScanner::scan('@{!! $literal !!} {{ $real }}');

        $this->assertCount(1, $usages);
        $this->assertSame('real', $usages[0]->name);
    }

    public function test_foreach_alias_is_not_treated_as_view_data_key(): void
    {
        // `$item` comes from the @foreach alias, not from the view() call's
        // data array. Reporting it as an unsafe view-data key would be a
        // false positive at the taint-sink layer.
        $source = <<<'BLADE'
            @foreach ($items as $item)
                {!! $item !!}
            @endforeach
            BLADE;

        $this->assertSame([], BladeTemplateScanner::unsafeVariables($source));
    }

    public function test_foreach_key_value_aliases_are_excluded(): void
    {
        $source = <<<'BLADE'
            @foreach ($rows as $key => $row)
                {!! $row !!} {{ $key }}
            @endforeach
            BLADE;

        $this->assertSame([], BladeTemplateScanner::unsafeVariables($source));
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

        $this->assertSame([], BladeTemplateScanner::unsafeVariables($source));
    }

    public function test_inline_assignment_in_if_condition_is_excluded(): void
    {
        $source = <<<'BLADE'
            @if ($rendered = markdown($post->body))
                {!! $rendered !!}
            @endif
            BLADE;

        $this->assertSame([], BladeTemplateScanner::unsafeVariables($source));
    }

    public function test_property_access_reports_top_level_variable_only(): void
    {
        // `{!! $user->bio !!}` — the view data key is `user`, not `bio`.
        // The scanner deliberately operates at top-level granularity.
        $usages = BladeTemplateScanner::scan('{!! $user->bio !!}');

        $this->assertCount(1, $usages);
        $this->assertSame('user', $usages[0]->name);
    }

    public function test_array_access_reports_top_level_variable_only(): void
    {
        $usages = BladeTemplateScanner::scan('{!! $data["html"] !!}');

        $this->assertCount(1, $usages);
        $this->assertSame('data', $usages[0]->name);
    }

    public function test_line_numbers_are_reported_correctly(): void
    {
        $source = "line1\nline2 {!! \$bad !!}\nline3 {{ \$good }}\n";

        $usages = BladeTemplateScanner::scan($source);

        $byName = [];

        foreach ($usages as $usage) {
            $byName[$usage->name] = $usage;
        }

        $this->assertCount(2, $byName);
        $this->assertSame(2, $byName['bad']->line);
        $this->assertSame(3, $byName['good']->line);
    }

    public function test_line_numbers_survive_multi_line_blanked_region(): void
    {
        // Regression: `blank()` previously replaced newlines with spaces,
        // collapsing downstream line numbers. `$later` must report line 6,
        // not line 2, even though the preceding @php / @verbatim / comment
        // block spans multiple lines.
        $source = <<<'BLADE'
            @php
                $noise = 'stuff';
            @endphp

            {{ $later }}
            BLADE;

        $usages = BladeTemplateScanner::scan($source);

        $byName = [];

        foreach ($usages as $usage) {
            $byName[$usage->name] = $usage;
        }

        $this->assertSame(5, $byName['later']->line);
    }

    public function test_line_numbers_survive_multi_line_raw_echo(): void
    {
        $source = "{!!\n\$x\n!!}\n\n{{ \$after }}";

        $byName = [];

        foreach (BladeTemplateScanner::scan($source) as $usage) {
            $byName[$usage->name] = $usage;
        }

        $this->assertSame(5, $byName['after']->line);
    }

    public function test_multi_line_echo_is_handled(): void
    {
        $source = "{!!\n\$wrapped\n!!}";

        $unsafe = BladeTemplateScanner::unsafeVariables($source);

        $this->assertSame(['wrapped'], $unsafe);
    }

    public function test_multiple_echoes_on_one_line(): void
    {
        $usages = BladeTemplateScanner::scan('{{ $a }} and {!! $b !!}');

        $byName = [];

        foreach ($usages as $usage) {
            $byName[$usage->name] = $usage;
        }

        $this->assertSame(BladeEchoKind::ESCAPED, $byName['a']->kind);
        $this->assertSame(BladeEchoKind::RAW, $byName['b']->kind);
    }

    public function test_unsafe_variables_aggregates_across_kinds(): void
    {
        $source = <<<'BLADE'
            {{ $safe }}
            {!! $raw !!}
            @php echo $phpBlock; @endphp
            BLADE;

        $unsafe = BladeTemplateScanner::unsafeVariables($source);

        \sort($unsafe);

        $this->assertSame(['phpBlock', 'raw'], $unsafe);
    }

    public function test_same_variable_in_safe_and_raw_contexts_is_reported_unsafe(): void
    {
        // When the same key is rendered both ways, the raw occurrence dominates
        // — if ANY echo is unsafe, the key must be treated as an html sink.
        $source = '{{ $title }} ... {!! $title !!}';

        $this->assertSame(['title'], BladeTemplateScanner::unsafeVariables($source));
    }

    public function test_no_variables_returns_empty(): void
    {
        $this->assertSame([], BladeTemplateScanner::scan('<p>Hello, world!</p>'));
        $this->assertSame([], BladeTemplateScanner::unsafeVariables('<p>Hello, world!</p>'));
    }

    public function test_all_safe_template_has_no_unsafe_variables(): void
    {
        $source = <<<'BLADE'
            <h1>{{ $title }}</h1>
            <p>{{ $user->name }}</p>
            @foreach ($posts as $post)
                <li>{{ $post->title }}</li>
            @endforeach
            BLADE;

        $this->assertSame([], BladeTemplateScanner::unsafeVariables($source));
    }

    public function test_multiple_variables_in_single_echo(): void
    {
        $usages = BladeTemplateScanner::scan('{!! $a . $b . $c !!}');

        $names = \array_map(static fn(\Psalm\LaravelPlugin\Blade\BladeVariableUsage $u): string => $u->name, $usages);

        \sort($names);

        $this->assertSame(['a', 'b', 'c'], $names);
    }

    public function test_section_with_content_argument_does_not_flag_variable(): void
    {
        // @section('title', $dynamic) passes data but does NOT echo it raw.
        // Blade escapes it at the @yield site via `echo e($content)`. The
        // current scanner ignores @section entirely — this test pins that.
        $source = "@section('title', \$dynamic)\n@stop";

        $this->assertSame([], BladeTemplateScanner::unsafeVariables($source));
    }

    public function test_known_limitation_string_interpolation_inside_raw_echo_surfaces_names(): void
    {
        // The regex scanner does not understand PHP string interpolation.
        // `{!! "hello {$attacker}" !!}` extracts `attacker` as an unsafe key.
        // This is accepted as a known limitation: the handler integration
        // layer must treat this case conservatively (the outer expression is
        // already an unescaped echo, so any key appearing inside should be
        // sinked regardless). Pinning the current behaviour so intentional
        // future changes are visible.
        $this->assertSame(['attacker'], BladeTemplateScanner::unsafeVariables('{!! "hello {$attacker}" !!}'));
    }

    public function test_known_limitation_at_php_inline_directive_is_not_recognised(): void
    {
        // `@php($foo = bar())` is the inline alternative to @php...@endphp.
        // It is NOT matched by the `@php ... @endphp` regex, so the
        // assignment does not register `$foo` as scope-local. If $foo is
        // later raw-echoed, it will surface as unsafe. Pinned so a future
        // improvement lands with an intentional test change.
        $source = "@php(\$foo = 'x')\n{!! \$foo !!}";

        $this->assertSame(['foo'], BladeTemplateScanner::unsafeVariables($source));
    }

    public function test_known_limitation_assignment_with_preceding_call_in_condition(): void
    {
        // `@if (count($rows) > 0 && $x = compute())` — the scope-local regex
        // only recognises `@if ($x = ...)` at the top of the condition. Pin
        // the known-limitation behaviour: `$x` is NOT excluded.
        $source = "@if (count(\$rows) > 0 && \$rendered = compute())\n{!! \$rendered !!}\n@endif";

        $this->assertSame(['rendered'], BladeTemplateScanner::unsafeVariables($source));
    }
}
