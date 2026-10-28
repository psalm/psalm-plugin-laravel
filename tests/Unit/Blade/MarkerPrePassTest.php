<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Blade\MarkerPrePass;

#[CoversClass(MarkerPrePass::class)]
final class MarkerPrePassTest extends TestCase
{
    #[Test]
    public function skips_verbatim_body_lines(): void
    {
        $skip = MarkerPrePass::computeSkipLines("@verbatim\n{{ raw }}\nstill raw\n@endverbatim\ndone\n");

        $this->assertArrayNotHasKey(1, $skip);
        $this->assertArrayHasKey(2, $skip);
        $this->assertArrayHasKey(3, $skip);
        $this->assertArrayHasKey(4, $skip);
        $this->assertArrayNotHasKey(5, $skip);
    }

    #[Test]
    public function skips_multiline_comment_body(): void
    {
        $skip = MarkerPrePass::computeSkipLines("{{--\nhidden\n--}}\nvisible\n");

        $this->assertArrayNotHasKey(1, $skip);
        $this->assertArrayHasKey(2, $skip);
        $this->assertArrayHasKey(3, $skip);
        $this->assertArrayNotHasKey(4, $skip);
    }

    #[Test]
    public function skips_multiline_directive_argument_body(): void
    {
        $skip = MarkerPrePass::computeSkipLines("@if(\n    \$a &&\n    \$b\n)\nyes\n@endif\n");

        $this->assertArrayNotHasKey(1, $skip);
        $this->assertArrayHasKey(2, $skip);
        $this->assertArrayHasKey(3, $skip);
        $this->assertArrayHasKey(4, $skip);
        $this->assertArrayNotHasKey(5, $skip);
    }

    #[Test]
    public function skips_multiline_component_tag_body(): void
    {
        // A marker between two attributes of a multi-line component tag defeats
        // ComponentTagCompiler's strict alternation match (see class docblock).
        $skip = MarkerPrePass::computeSkipLines("<x-alert\n    type=\"error\"\n    message=\"oops\"\n/>\ndone\n");

        $this->assertArrayNotHasKey(1, $skip);
        $this->assertArrayHasKey(2, $skip);
        $this->assertArrayHasKey(3, $skip);
        $this->assertArrayHasKey(4, $skip);
        $this->assertArrayNotHasKey(5, $skip);
    }

    /** @return iterable<string, array{string}> */
    public static function componentTagAttributesContainingAGreaterThanSign(): iterable
    {
        // #1700: `->` inside an echo.
        yield 'attributes echo with arrow call' => ["{{ \$attributes->merge(['class' => 'x']) }}"];
        yield '@class with array arrow' => ["@class(['active' => \$on])"];
    }

    #[Test]
    #[DataProvider('componentTagAttributesContainingAGreaterThanSign')]
    public function skips_a_multiline_component_tag_through_an_attribute_containing_a_greater_than_sign(string $attribute): void
    {
        $source = "<x-foo\n    a=\"b\"\n    {$attribute}\n>\n    @if (\$a)\n        x\n    @endif\n</x-foo>\n";
        $skip = MarkerPrePass::computeSkipLines($source);

        $this->assertArrayHasKey(2, $skip);
        $this->assertArrayHasKey(3, $skip);
        $this->assertArrayHasKey(4, $skip); // the tag's closing `>`
        $this->assertArrayNotHasKey(5, $skip);
        $this->assertStringNotContainsString('blade:4 */', MarkerPrePass::inject($source));
    }

    #[Test]
    public function skips_the_wrapped_end_of_a_multiline_closing_component_tag(): void
    {
        // compileClosingTags() needs `</x-alert` and its `>` with only whitespace between.
        $source = "<x-alert class=\"a\"\n    >Read more</x-alert\n    >\ndone\n";
        $skip = MarkerPrePass::computeSkipLines($source);

        $this->assertArrayHasKey(3, $skip);
        $this->assertArrayNotHasKey(4, $skip);
    }

    #[Test]
    public function skips_a_multiline_prefixed_precompiled_tag_body(): void
    {
        // #1910: precompilers owning a tag prefix (`<native:...>`, `<flux:...>`) match as strictly.
        $source = "<native:top-bar\n    title=\"{{ \$t }}\"\n>\n    x\n</native:top-bar\n>\ndone\n";
        $skip = MarkerPrePass::computeSkipLines($source);

        $this->assertArrayNotHasKey(1, $skip);
        $this->assertArrayHasKey(2, $skip);
        $this->assertArrayHasKey(3, $skip);
        $this->assertArrayNotHasKey(4, $skip);
        $this->assertArrayNotHasKey(5, $skip);
        $this->assertArrayHasKey(6, $skip); // the wrapped closing tag's `>`
        $this->assertArrayNotHasKey(7, $skip);
    }

    #[Test]
    public function a_prefixed_pseudo_tag_in_a_script_does_not_run_past_the_next_tag(): void
    {
        // Skipped lines inherit the opener's line, so a line-keyed `@psalm-suppress` would swallow
        // the distinct findings on lines 5 and 6.
        $source = "{{-- @psalm-suppress UndefinedMethod --}}\n<script>\nconst text = `<demo:tag\n`;\n"
            . "{{ (new \\stdClass)->missingOne() }}\n{{ (new \\stdClass)->missingTwo() }}\n</script>\n";
        $skip = MarkerPrePass::computeSkipLines($source);

        $this->assertArrayNotHasKey(5, $skip);
        $this->assertArrayNotHasKey(6, $skip);
    }

    #[Test]
    public function a_less_than_x_dash_comparison_does_not_open_a_component_tag(): void
    {
        // ComponentTagCompiler wants whitespace, `/` or `>` after the tag name; `x-1)` is not one.
        $source = "<script>\nif (a < x-1) {\n    b({{ \$c }});\n}\n</script>\ndone\n";

        $this->assertSame([], MarkerPrePass::computeSkipLines($source));
    }

    #[Test]
    public function a_never_closed_component_tag_does_not_disarm_later_component_tags(): void
    {
        // The unterminated quote makes every later `>` unreachable, so the match fails only after
        // trying both ways to consume each `{{ }}`: exponential without the possessive outer loop,
        // and a failed preg_match_all drops every component-tag skip in the template.
        $source = "<x-alert\n" . \str_repeat("    {{ \$a }}\n", 40) . "    title=\"unterminated\n<x-foo\n    class='a'\n/>\n";
        $tagLine = 43;

        $skip = MarkerPrePass::computeSkipLines($source);

        $this->assertArrayHasKey($tagLine + 1, $skip);
        $this->assertArrayHasKey($tagLine + 2, $skip);
    }

    #[Test]
    public function whitespace_only_lines_get_no_marker(): void
    {
        $marked = MarkerPrePass::inject("line one\n   \nline three\n");

        $this->assertStringNotContainsString('blade:2 */', $marked);
        $this->assertStringContainsString('blade:1 */', $marked);
        $this->assertStringContainsString('blade:3 */', $marked);
    }

    #[Test]
    public function appends_trailing_marker_for_extends_line(): void
    {
        $marked = MarkerPrePass::inject("@extends('layout')\n@section('content')\nhi\n@endsection\n");

        // Once for the opening-line marker, once more as the trailing footer marker.
        $this->assertSame(2, \substr_count($marked, '/* blade:1 */'));
    }

    #[Test]
    public function extends_line_detects_source_line(): void
    {
        $this->assertSame(2, MarkerPrePass::extendsLine("line one\n@extends('layout')\n"));
    }

    #[Test]
    public function skips_raw_php_block_body_lines(): void
    {
        $skip = MarkerPrePass::computeSkipLines("<?php\n\$x = 1;\n?>\nhello\n");

        $this->assertArrayNotHasKey(1, $skip);
        $this->assertArrayHasKey(2, $skip);
        $this->assertArrayHasKey(3, $skip);
        $this->assertArrayNotHasKey(4, $skip);
    }

    #[Test]
    public function extends_line_returns_null_for_a_commented_out_extends(): void
    {
        $this->assertNull(MarkerPrePass::extendsLine("{{-- @extends('layout') --}}\nhello\n"));
    }

    #[Test]
    public function an_unclosed_php_tag_inside_a_comment_does_not_mask_the_rest_of_the_template(): void
    {
        // Independent preg_match_all() passes let the raw-PHP pattern re-scan text the comment
        // pattern already claimed: a raw PHP open tag typed inside a Blade comment, with no
        // closing tag of its own, would otherwise mask everything to EOF via the `.*\z` fallback.
        $source = "{{-- <?php --}}\n<div>a</div>\n<div>b</div>\n@extends('real')\n";

        $this->assertSame([], MarkerPrePass::computeSkipLines($source));
        $this->assertSame(4, MarkerPrePass::extendsLine($source));
    }

    #[Test]
    public function a_literal_double_brace_inside_a_masked_raw_php_block_does_not_swallow_live_lines(): void
    {
        // The echo pattern must not be free to start matching INSIDE a masked raw-PHP block and
        // run past its end into live source: a literal `{{` with no matching `}}` of its own
        // inside the block would otherwise let it lazily consume every line up to the next REAL
        // `}}`, marking live lines in between as skipped and dropping their markers.
        $skip = MarkerPrePass::computeSkipLines("<?php \$s = '{{'; ?>\n<div>live</div>\n{{ \$x }}\n");

        $this->assertArrayNotHasKey(2, $skip);
    }

    #[Test]
    public function skips_lines_between_switch_and_its_first_case(): void
    {
        // Blade's compileSwitch() opens PHP mode for the switch statement without closing it;
        // it stays open until the first @case closes it. A marker on any line in between is
        // injected inside open PHP code and breaks the shadow's syntax outright.
        $skip = MarkerPrePass::computeSkipLines("@switch(\$x)\n\n{{-- note --}}\n@case('a')\nfoo\n@break\n@endswitch\n");

        $this->assertArrayNotHasKey(1, $skip);
        $this->assertArrayHasKey(2, $skip);
        $this->assertArrayHasKey(3, $skip);
        $this->assertArrayHasKey(4, $skip);
        $this->assertArrayNotHasKey(5, $skip);
        $this->assertArrayNotHasKey(6, $skip);
        $this->assertArrayNotHasKey(7, $skip);
    }

    #[Test]
    public function nested_switch_rearms_the_gate_and_a_later_case_finds_it_already_disarmed(): void
    {
        // firstCaseInSwitch is a single bool, not a stack: the inner @switch re-arms it and the
        // inner @case consumes it. The outer switch's OWN next @case then finds the flag already
        // false (Laravel compiles it as a self-contained, already-closed case statement), so a
        // gate implemented as a stack (which would think the outer frame is still pending) would
        // wrongly keep gating lines 8-9 here. Mirroring the bool must not.
        $source = "@switch(\$a)\n@case(1)\n@switch(\$b)\n\n@case(2)\nfoo\n@endswitch\n\n@case(3)\nbar\n@endswitch\n";
        $skip = MarkerPrePass::computeSkipLines($source);

        $this->assertArrayHasKey(2, $skip); // outer @case(1) line itself: closes the outer switch's open tag
        $this->assertArrayHasKey(4, $skip); // inner gap before @case(2)
        $this->assertArrayHasKey(5, $skip); // inner @case(2) line itself
        $this->assertArrayNotHasKey(8, $skip); // gap before outer @case(3): flag already disarmed
        $this->assertArrayNotHasKey(9, $skip); // outer @case(3) line itself: self-contained, no gating needed
    }

    #[Test]
    public function a_quoted_case_directive_inside_a_switch_argument_does_not_disarm_the_gate(): void
    {
        // The literal text "@case(1)" inside a STRING argument to @switch must not be mistaken
        // for a real @case directive that consumes the pending switch: the walk regex has to
        // consume the entire balanced-paren argument as one match, not just up to the first "(".
        $source = "@switch(str_contains(\$x, \"@case(1)\"))\nreal text\n@case('a')\nfoo\n@endswitch\n";
        $skip = MarkerPrePass::computeSkipLines($source);

        $this->assertArrayHasKey(2, $skip);
        $this->assertArrayHasKey(3, $skip); // the real @case line
        $this->assertArrayNotHasKey(4, $skip);
    }

    #[Test]
    public function blanking_inert_text_preserves_offsets_and_lines(): void
    {
        $source = "a\n{{-- @props([]) --}}\n@verbatim\n{{ \$slot }}\n@endverbatim\nb\n";
        $blanked = MarkerPrePass::blankInertText($source);

        $this->assertSame(\strlen($source), \strlen($blanked));
        $this->assertSame(\substr_count($source, "\n"), \substr_count($blanked, "\n"));
        $this->assertStringNotContainsString('@props', $blanked);
        $this->assertStringNotContainsString('$slot', $blanked);
        $this->assertSame("a\n", \substr($blanked, 0, 2));
    }

    #[Test]
    public function an_escaped_php_directive_does_not_open_a_masked_range(): void
    {
        // Blade's own `(?<!@)@php(.*?)@endphp` skips `@@php`, so it is literal text and the
        // comment below is compiled (stripped) live rather than swallowed into a phantom block.
        $source = "@@php\n{{-- note --}}\n@endphp\n";

        $this->assertSame(['{{-- note --}}'], \array_column(MarkerPrePass::maskedRanges($source), 0));
    }
}
