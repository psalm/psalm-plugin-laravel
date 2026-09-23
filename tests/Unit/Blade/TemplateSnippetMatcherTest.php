<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Blade\TemplateSnippetMatcher;

#[CoversClass(TemplateSnippetMatcher::class)]
final class TemplateSnippetMatcherTest extends TestCase
{
    #[Test]
    public function an_identical_snippet_occurs(): void
    {
        $this->assertTrue(TemplateSnippetMatcher::occursIn(
            "foo('a', 'b')",
            "<div>\n  foo('a', 'b')\n</div>\n",
        ));
    }

    #[Test]
    public function differing_line_breaks_between_arguments_still_occur(): void
    {
        // A generated block reformats an argument list onto its own lines; the comparison must
        // ignore that rather than requiring byte-identical whitespace.
        $this->assertTrue(TemplateSnippetMatcher::occursIn(
            "foo('a',\n'b',\n'c')",
            "<div>\n  foo('a', 'b', 'c')\n</div>\n",
        ));
    }

    #[Test]
    public function a_multi_line_snippet_is_found_against_the_whole_source_not_one_line(): void
    {
        // Guards the "match the whole source, never one line" rule directly: a per-line
        // comparison would find neither half of a snippet split across two template lines.
        $snippet = "foo('a',\n  'b')";
        $source = "<div>\n  foo('a',\n  'b')\n</div>\n";

        $this->assertTrue(TemplateSnippetMatcher::occursIn($snippet, $source));
    }

    #[Test]
    public function an_absent_snippet_does_not_occur(): void
    {
        $this->assertFalse(TemplateSnippetMatcher::occursIn(
            "bar('a', 'b', 'c')",
            "<div>\n  foo('a', 'b')\n</div>\n",
        ));
    }

    /**
     * The shape Psalm hands the relocator: a whole compiled shadow line, plus the offset of the
     * callee-name node inside it. Only the call comes back out, so what is matched against the
     * template is text the Blade compiler copied rather than text it wrote.
     */
    #[Test]
    public function a_call_is_cut_out_of_a_compiled_echo_line(): void
    {
        $line = '<?php /* blade:2 */ ?>  <?php echo e($x->mount(1, 2, 3)); ?>';

        $this->assertSame(
            'mount(1, 2, 3)',
            TemplateSnippetMatcher::callExpressionAt($line, \strpos($line, 'mount(') ?: 0),
        );
    }

    #[Test]
    public function a_nested_call_does_not_end_the_argument_list_early(): void
    {
        $this->assertSame(
            'mount(inner(1), 2)',
            TemplateSnippetMatcher::callExpressionAt('mount(inner(1), 2);', 0),
        );
    }

    #[Test]
    public function a_parenthesis_inside_a_string_literal_does_not_end_the_argument_list(): void
    {
        // Why the lexer and not a character scan: a `)` in a quoted argument would close the list
        // early, the truncated text would not be found in the template, and a real author issue
        // would be dropped as generated.
        $this->assertSame(
            "mount('a) b', 2)",
            TemplateSnippetMatcher::callExpressionAt("mount('a) b', 2);", 0),
        );
    }

    #[Test]
    public function a_parenthesis_inside_an_interpolated_string_does_not_end_the_argument_list(): void
    {
        // `"$label("` lexes as several tokens, one of which is the bare text `(`. Treating token
        // TEXT as punctuation counts that as an opening paren, and the extraction then runs one
        // `)` too far and swallows the compiler's own closing paren for `e(`. The result is absent
        // from the template and a real author issue is dropped.
        $line = '<?php /* blade:2 */ ?>  <?php echo e($x->mount("$label(", 2)); ?>';

        $this->assertSame(
            'mount("$label(", 2)',
            TemplateSnippetMatcher::callExpressionAt($line, \strpos($line, 'mount(') ?: 0),
        );
    }

    #[Test]
    public function a_blade_comment_between_arguments_still_occurs(): void
    {
        // Blade strips `{{-- --}}` before compiling, so the compiled call has no comment in it and
        // the raw template does. The template side has to be stripped the same way, or the call is
        // never found and the issue is dropped as generated.
        $this->assertTrue(TemplateSnippetMatcher::occursIn(
            "mount('a', 'b')",
            "<div>\n  {{ \$x->mount('a', {{-- why --}} 'b') }}\n</div>\n",
        ));
    }

    #[Test]
    public function an_offset_that_does_not_start_a_call_declines(): void
    {
        // A `TooManyArguments` whose location is not a plain callee-name node: unjudgeable, and the
        // caller keeps the issue rather than guessing.
        $this->assertNull(TemplateSnippetMatcher::callExpressionAt('$x->mount(1, 2);', 0));
        $this->assertNull(TemplateSnippetMatcher::callExpressionAt('mount 1, 2;', 0));
        $this->assertNull(TemplateSnippetMatcher::callExpressionAt('mount(1, 2);', 99));
    }

    #[Test]
    public function an_argument_list_left_open_by_the_end_of_the_line_declines(): void
    {
        // Psalm's snippet is one line; a call continued on the next one cannot be read whole, and
        // a partial read would not match the template.
        $this->assertNull(TemplateSnippetMatcher::callExpressionAt('mount(1,', 0));
    }

    /**
     * The shape an argument-position issue hands the relocator: the whole compiled line, plus the
     * bounds of the ARGUMENT rather than the callee. The enclosing `e(` sits before those bounds on
     * the same line, so the walk runs backwards from them.
     */
    #[Test]
    public function the_enclosing_escape_call_is_cut_out_of_a_compiled_echo_line(): void
    {
        $line = "<?php /* blade:2 */ ?><?php echo e(old('k')); ?>";
        $start = (int) \strpos($line, "old('k')");

        $this->assertSame(
            "e(old('k')",
            TemplateSnippetMatcher::enclosingCallAt($line, $start, $start + \strlen("old('k')"), 'e'),
        );
    }

    #[Test]
    public function the_enclosing_raw_echo_is_cut_out_without_a_parenthesis(): void
    {
        // `{!! !!}` compiles to a bare `echo`, a language construct with no parenthesis to step
        // over, and Psalm names it `echo` in the message like any other callee.
        $line = "<?php /* blade:2 */ ?><div><?php echo old('k'); ?></div>";
        $start = (int) \strpos($line, "old('k')");

        $this->assertSame(
            "echo old('k')",
            TemplateSnippetMatcher::enclosingCallAt($line, $start, $start + \strlen("old('k')"), 'echo'),
        );
    }

    #[Test]
    public function whitespace_between_the_callee_and_its_parenthesis_is_kept(): void
    {
        // An author's own `e ( ... )` inside `@php` reaches the shadow verbatim, so the slice has
        // to reproduce that spacing for the template to contain it.
        $line = "echo e ( old('k') );";
        $start = (int) \strpos($line, "old('k')");

        $this->assertSame(
            "e ( old('k')",
            TemplateSnippetMatcher::enclosingCallAt($line, $start, $start + \strlen("old('k')"), 'e'),
        );
    }

    #[Test]
    public function the_nearest_preceding_call_wins_when_a_line_holds_two_echoes(): void
    {
        // `{{ $a }}{{ old('k') }}` compiles to two echo statements on one shadow line.
        $line = "<?php echo e(\$a); ?><?php echo e(old('k')); ?>";
        $start = (int) \strpos($line, "old('k')");

        $this->assertSame(
            "e(old('k')",
            TemplateSnippetMatcher::enclosingCallAt($line, $start, $start + \strlen("old('k')"), 'e'),
        );
    }

    #[Test]
    public function a_qualified_or_member_callee_of_the_same_name_declines(): void
    {
        // Without an identifier-boundary check all three read backwards as the identifier `e`, and
        // a user-defined `\Fx\e()` or `$obj->e()` would be treated as the compiler's own escape.
        foreach (["echo \\e(old('k'));", "echo Fx::e(old('k'));", "echo \$obj->e(old('k'));", "echo safe(old('k'));"] as $line) {
            $start = (int) \strpos($line, "old('k')");

            $this->assertNull(
                TemplateSnippetMatcher::enclosingCallAt($line, $start, $start + \strlen("old('k')"), 'e'),
                $line,
            );
        }
    }

    #[Test]
    public function an_argument_not_enclosed_by_the_named_callee_declines(): void
    {
        $line = "echo trim(old('k'));";
        $start = (int) \strpos($line, "old('k')");

        $this->assertNull(TemplateSnippetMatcher::enclosingCallAt($line, $start, $start + \strlen("old('k')"), 'e'));
    }

    #[Test]
    public function bounds_with_no_room_for_a_callee_decline(): void
    {
        // Fail open on anything unreadable: an argument at offset 0 has nothing before it, and
        // bounds past the end of the snippet mean the location and the snippet disagree.
        $this->assertNull(TemplateSnippetMatcher::enclosingCallAt("old('k')", 0, 8, 'e'));
        $this->assertNull(TemplateSnippetMatcher::enclosingCallAt("e(old('k')", 2, 99, 'e'));
        $this->assertNull(TemplateSnippetMatcher::enclosingCallAt("e(old('k')", 2, 2, 'e'));
        $this->assertNull(TemplateSnippetMatcher::enclosingCallAt('e(', 2, 2, 'e'));
    }

    #[Test]
    public function a_differently_cased_callee_still_matches(): void
    {
        // PHP identifiers are case-insensitive, so an author's `{{ E(old('k')) }}` compiles to an
        // inner call that is genuinely theirs; matching case-sensitively would decline and the
        // template-source check below would never get to keep it on its own merits.
        $line = "echo E(old('k'));";
        $start = (int) \strpos($line, "old('k')");

        $this->assertSame(
            "E(old('k')",
            TemplateSnippetMatcher::enclosingCallAt($line, $start, $start + \strlen("old('k')"), 'e'),
        );
    }

    /**
     * The whole point of the slice: the compiler's own wrapper is absent from the template, an
     * author's identical-looking `@php` line is present, and the two shadow lines are byte-identical.
     */
    #[Test]
    public function the_slice_separates_a_compiled_echo_from_an_authors_identical_php_block(): void
    {
        $this->assertFalse(TemplateSnippetMatcher::occursIn("e(old('k')", "<div>{{ old('k') }}</div>\n"));
        $this->assertTrue(TemplateSnippetMatcher::occursIn("e(old('k')", "@php echo e(old('k')); @endphp\n"));
        $this->assertFalse(TemplateSnippetMatcher::occursIn("echo old('k')", "<div>{!! old('k') !!}</div>\n"));
        $this->assertTrue(TemplateSnippetMatcher::occursIn("echo old('k')", "@php echo old('k'); @endphp\n"));
    }

    /**
     * `@@foo` unescapes to `@foo` before a compiled call reaches the shadow (#1540): an author's
     * over-arity call whose argument contains it is absent from the raw template under plain
     * `occursIn()`, and only found once that same unescape is mirrored onto the template side.
     */
    #[Test]
    public function an_at_escaped_argument_matches_only_with_raw_text_rewrites(): void
    {
        $snippet = "mount('@foo', 'u', 'v')";
        $source = "<div>\n  {{ \$x->mount('@@foo', 'u', 'v') }}\n</div>\n";

        $this->assertFalse(TemplateSnippetMatcher::occursIn($snippet, $source));
        $this->assertTrue(TemplateSnippetMatcher::occursInWithRawTextRewrites($snippet, $source));
    }

    /**
     * Component-marker removal (#1540) mirrors `compileString()`'s own final `str_replace` onto
     * the template-source copy `occursIn()` matches against, as string-level parity with that
     * rewrite regardless of which side of a match it originates on.
     */
    #[Test]
    public function a_marker_wrapped_template_matches_only_with_raw_text_rewrites(): void
    {
        $snippet = "mount('a', 'b', 'c')";
        $source = "<div>\n  mount('a', ##BEGIN-COMPONENT-CLASS##'b', 'c')\n</div>\n";

        $this->assertFalse(TemplateSnippetMatcher::occursIn($snippet, $source));
        $this->assertTrue(TemplateSnippetMatcher::occursInWithRawTextRewrites($snippet, $source));
    }

    /**
     * Blade removes comments BEFORE it unescapes `@@` (`$compilers` order), so
     * `'@@{{-- c --}}foo'` compiles to `'@foo'`: the mirror must strip comments before its own
     * unescape pass, or the `@@` never sits directly before a word character and stays escaped.
     */
    #[Test]
    public function a_comment_split_escape_matches_only_with_blade_ordered_rewrites(): void
    {
        $snippet = "mount('@foo', 'u', 'v')";
        $source = "<div>\n  {{ \$x->mount('@@{{-- c --}}foo', 'u', 'v') }}\n</div>\n";

        $this->assertFalse(TemplateSnippetMatcher::occursIn($snippet, $source));
        $this->assertTrue(TemplateSnippetMatcher::occursInWithRawTextRewrites($snippet, $source));
    }

    /**
     * Blade unescapes `@@` during the token pass and strips component markers only at the very
     * end, so in `'x##BEGIN-COMPONENT-CLASS##@@foo'` the `@@` still unescapes (the `#` before it
     * satisfies `\B`) and the compiled text is `'x@foo'`. Removing the marker first would glue
     * `x` to `@@` and block the unescape; the mirror must keep Blade's order.
     */
    #[Test]
    public function a_marker_adjacent_escape_matches_only_with_blade_ordered_rewrites(): void
    {
        $snippet = "mount('x@foo', 'u', 'v')";
        $source = "<div>\n  {{ \$x->mount('x##BEGIN-COMPONENT-CLASS##@@foo', 'u', 'v') }}\n</div>\n";

        $this->assertFalse(TemplateSnippetMatcher::occursIn($snippet, $source));
        $this->assertTrue(TemplateSnippetMatcher::occursInWithRawTextRewrites($snippet, $source));
    }

    /**
     * `@php` and raw `<?php ?>` blocks are exempt from the `@@` unescape but NOT from the final
     * marker strip, so `'@@foo##BEGIN-COMPONENT-CLASS##'` inside `@php` compiles to `'@@foo'`.
     * Only a markers-only variant of the mirror finds that call; the full mirror over-unescapes
     * it to `'@foo'` and misses.
     */
    #[Test]
    public function a_php_block_call_with_marker_matches_via_the_markers_only_variant(): void
    {
        $snippet = "mount('@@foo', 'u', 'v')";
        $source = "@php \$x->mount('@@foo##BEGIN-COMPONENT-CLASS##', 'u', 'v'); @endphp\n";

        $this->assertFalse(TemplateSnippetMatcher::occursIn($snippet, $source));
        $this->assertTrue(TemplateSnippetMatcher::occursInWithRawTextRewrites($snippet, $source));
    }

    /** Mirroring never widens the gate: a call genuinely absent from the template still does not match. */
    #[Test]
    public function a_genuinely_absent_call_still_does_not_match_with_raw_text_rewrites(): void
    {
        $this->assertFalse(TemplateSnippetMatcher::occursInWithRawTextRewrites(
            "mount('@foo', 'u', 'v', 'w', 'x')",
            "<div>\n  {{ \$x->mount('@@foo', 'u', 'v') }}\n</div>\n",
        ));
    }
}
