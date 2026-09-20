<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use PHPUnit\Framework\Attributes\CoversClass;
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
    public function skips_php_block_body_lines(): void
    {
        $skip = MarkerPrePass::computeSkipLines("@php\n\$x = 1;\n\$y = 2;\n@endphp\ndone\n");

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
    public function extends_line_returns_null_when_absent(): void
    {
        $this->assertNull(MarkerPrePass::extendsLine("hello\n"));
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
    public function skips_raw_php_block_body_lines_when_unclosed_at_eof(): void
    {
        $skip = MarkerPrePass::computeSkipLines("<?php\n\$x = 1;\n");

        $this->assertArrayNotHasKey(1, $skip);
        $this->assertArrayHasKey(2, $skip);
    }

    #[Test]
    public function extends_line_returns_null_for_a_commented_out_extends(): void
    {
        $this->assertNull(MarkerPrePass::extendsLine("{{-- @extends('layout') --}}\nhello\n"));
    }

    #[Test]
    public function extends_line_skips_a_commented_out_extends_and_finds_the_live_one(): void
    {
        $this->assertSame(3, MarkerPrePass::extendsLine("{{-- @extends('old') --}}\n\n@extends('real')\n"));
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
    public function skips_an_uppercase_raw_php_block(): void
    {
        // `<?PHP` is a legal, case-insensitive PHP open tag.
        $skip = MarkerPrePass::computeSkipLines("<?PHP\n\$x = 1;\n?>\nhi\n");

        $this->assertArrayNotHasKey(1, $skip);
        $this->assertArrayHasKey(2, $skip);
        $this->assertArrayHasKey(3, $skip);
        $this->assertArrayNotHasKey(4, $skip);
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
    public function a_masked_switch_does_not_arm_the_case_gate(): void
    {
        // A `@switch(` typed inside a Blade comment is not a live directive; it must not arm
        // the gate for the real @case that follows, so that @case finds nothing pending and
        // suppresses nothing.
        $skip = MarkerPrePass::computeSkipLines("{{-- @switch(\$x) --}}\nline two\n@case('a')\nline four\n");

        $this->assertSame([], $skip);
    }

    #[Test]
    public function a_masked_case_does_not_disarm_a_live_switchs_gate(): void
    {
        // A `@case(` typed inside a Blade comment must not consume the pending switch: the real
        // @case that follows still needs its own gate, or the ParseError from #1496 comes back.
        $source = "@switch(\$x)\n{{-- @case(9) --}}\nreal text\n@case('a')\nfoo\n@endswitch\n";
        $skip = MarkerPrePass::computeSkipLines($source);

        $this->assertArrayHasKey(2, $skip);
        $this->assertArrayHasKey(3, $skip);
        $this->assertArrayHasKey(4, $skip); // the real @case line: still inside open PHP
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
    public function same_line_switch_and_case_marks_nothing_extra(): void
    {
        $skip = MarkerPrePass::computeSkipLines("@switch(\$x) @case('a')\nfoo\n@endswitch\n");

        $this->assertSame([], $skip);
    }

    #[Test]
    public function an_unresolved_switch_with_no_case_marks_nothing(): void
    {
        // Blade's own output is already unterminated PHP in this shape; the gate must not
        // invent a recovery for it.
        $skip = MarkerPrePass::computeSkipLines("@switch(\$x)\nno case here\n");

        $this->assertSame([], $skip);
    }

    #[Test]
    public function mixed_case_switch_and_case_directives_are_recognized(): void
    {
        // Blade dispatches directives via `compile{$name}`, and PHP method names are
        // case-insensitive, so `@SWITCH`/`@CASE` compile identically to their lowercase form.
        $skip = MarkerPrePass::computeSkipLines("@SWITCH(\$x)\n\n@CASE('a')\nfoo\n@endswitch\n");

        $this->assertArrayHasKey(2, $skip);
        $this->assertArrayHasKey(3, $skip);
        $this->assertArrayNotHasKey(4, $skip);
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
    public function a_comment_between_the_directive_name_and_its_parenthesis_still_arms_the_gate(): void
    {
        // Blade strips Blade comments before directive recognition, so `@switch{{-- note --}}($x)`
        // compiles exactly like `@switch($x)`. The gate walks the same blanked scan source the
        // sibling patterns use, so a masked comment there still leaves `[ \t]*` free to match.
        $source = "@switch{{-- note --}}(\$x)\nreal text\n@case('a')\nfoo\n@endswitch\n";
        $skip = MarkerPrePass::computeSkipLines($source);

        $this->assertArrayHasKey(2, $skip);
        $this->assertArrayHasKey(3, $skip);
    }
}
