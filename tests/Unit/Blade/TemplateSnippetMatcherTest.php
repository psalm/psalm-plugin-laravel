<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Blade\MarkerComment;
use Psalm\LaravelPlugin\Blade\TemplateSnippetMatcher;

#[CoversClass(TemplateSnippetMatcher::class)]
final class TemplateSnippetMatcherTest extends TestCase
{
    /**
     * A prefix no test source below contains, so the marker strip is inert for every case that is
     * not about it. Its own cases build a real prefix with {@see MarkerComment::prefixFor()}.
     */
    private const PREFIX = 'blade:0f0f:';

    private function occursIn(string $snippet, string $source, string $markerPrefix = self::PREFIX): bool
    {
        return TemplateSnippetMatcher::occursIn($snippet, $source, $markerPrefix);
    }

    private function occursInWithRawTextRewrites(string $snippet, string $source, string $markerPrefix = self::PREFIX): bool
    {
        return TemplateSnippetMatcher::occursInWithRawTextRewrites($snippet, $source, $markerPrefix);
    }

    /**
     * #1544: a call written across several lines inside `@php` or a raw `<?php` block reaches the
     * shadow with a marker at the head of every continuation line, and no template contains one.
     * Without the strip the gate compares text the author could not have written, finds it absent,
     * and drops their issue as compiler-generated.
     *
     * That is a real lost finding, not noise. `CodeLocation` extends its snippet to the end of the
     * line the SELECTION ends on, so a node spanning lines yields a snippet spanning lines, and
     * `FunctionCallAnalyzer` builds a function call's `TooManyArguments` location from the whole
     * call node: `callExpressionAt()` then reads the argument list to its end and hands the markers
     * straight to the comparison. Pinned end to end on line 19 of `authored-arity.blade.php`, in
     * `BladeIssueRemapTest::an_authored_over_arity_call_survives_in_every_compiled_blade_syntax()`.
     */
    #[Test]
    public function in_block_markers_are_stripped_before_matching(): void
    {
        $source = "@php\n\$x->mount(\n  'a',\n  'b',\n);\n@endphp\n";
        $prefix = MarkerComment::prefixFor($source);
        $snippet = "mount(\n  /* {$prefix}3 */ 'a',\n  /* {$prefix}4 */ 'b',\n)";

        $this->assertTrue($this->occursIn($snippet, $source, $prefix));
        $this->assertTrue($this->occursInWithRawTextRewrites($snippet, $source, $prefix));

        // Teeth: the same snippet under an unrelated prefix keeps the marker text and misses.
        $this->assertFalse($this->occursIn($snippet, $source, self::PREFIX));
    }

    /**
     * The strip is scoped by the prefix, which {@see MarkerComment::prefixFor()} extends until it is
     * absent from the template. Marker-SHAPED text an author wrote is therefore never cut out, and a
     * call that genuinely differs from the template still does not match.
     */
    #[Test]
    public function author_written_marker_shaped_text_is_not_stripped(): void
    {
        $source = "@php\n\$x->mount('/* blade:3 */');\n@endphp\n";
        $prefix = MarkerComment::prefixFor($source);

        $this->assertTrue($this->occursIn("mount('/* blade:3 */')", $source, $prefix));
        $this->assertFalse($this->occursIn("mount('')", $source, $prefix));
    }

    #[Test]
    public function differing_line_breaks_between_arguments_still_occur(): void
    {
        // A generated block reformats an argument list onto its own lines; the comparison must
        // ignore that rather than requiring byte-identical whitespace.
        $this->assertTrue($this->occursIn("foo('a',\n'b',\n'c')", "<div>\n  foo('a', 'b', 'c')\n</div>\n"));
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
        $this->assertTrue($this->occursIn("mount('a', 'b')", "<div>\n  {{ \$x->mount('a', {{-- why --}} 'b') }}\n</div>\n"));
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
    public function bounds_with_no_room_for_a_callee_decline(): void
    {
        // Fail open on anything unreadable: an argument at offset 0 has nothing before it, and
        // bounds past the end of the snippet mean the location and the snippet disagree.
        $this->assertNull(TemplateSnippetMatcher::enclosingCallAt("old('k')", 0, 8, 'e'));
        $this->assertNull(TemplateSnippetMatcher::enclosingCallAt("e(old('k')", 2, 99, 'e'));
        $this->assertNull(TemplateSnippetMatcher::enclosingCallAt("e(old('k')", 2, 2, 'e'));
        $this->assertNull(TemplateSnippetMatcher::enclosingCallAt('e(', 2, 2, 'e'));
    }

    /**
     * The whole point of the slice: the compiler's own wrapper is absent from the template, an
     * author's identical-looking `@php` line is present, and the two shadow lines are byte-identical.
     */
    #[Test]
    public function the_slice_separates_a_compiled_echo_from_an_authors_identical_php_block(): void
    {
        $this->assertFalse($this->occursIn("e(old('k')", "<div>{{ old('k') }}</div>\n"));
        $this->assertTrue($this->occursIn("e(old('k')", "@php echo e(old('k')); @endphp\n"));
        $this->assertFalse($this->occursIn("echo old('k')", "<div>{!! old('k') !!}</div>\n"));
        $this->assertTrue($this->occursIn("echo old('k')", "@php echo old('k'); @endphp\n"));
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

        $this->assertFalse($this->occursIn($snippet, $source));
        $this->assertTrue($this->occursInWithRawTextRewrites($snippet, $source));
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

        $this->assertFalse($this->occursIn($snippet, $source));
        $this->assertTrue($this->occursInWithRawTextRewrites($snippet, $source));
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

        $this->assertFalse($this->occursIn($snippet, $source));
        $this->assertTrue($this->occursInWithRawTextRewrites($snippet, $source));
    }

    /** The `@aware` list-form gate: the compiled call text must end exactly where the selection does. */
    #[Test]
    public function ends_at_matches_text_ending_exactly_at_the_offset(): void
    {
        $call = '$__env->getConsumableComponentData($__value';
        $line = "<?php foreach (\$x as \$__key => \$__value) { \$__consumable = {$call}, \$__key); } ?>";
        $end = \strpos($line, $call) + \strlen($call);

        $this->assertTrue(TemplateSnippetMatcher::endsAt($line, $end, $call));
        $this->assertTrue(TemplateSnippetMatcher::endsAt($call, \strlen($call), $call));

        // One byte either side, or the text not there at all.
        $this->assertFalse(TemplateSnippetMatcher::endsAt($line, $end - 1, $call));
        $this->assertFalse(TemplateSnippetMatcher::endsAt($line, $end + 1, $call));
        $this->assertFalse(TemplateSnippetMatcher::endsAt('$other($__value, 1)', 15, $call));

        // Offset before the text could fit: at snippet start, and one short of its length.
        $this->assertFalse(TemplateSnippetMatcher::endsAt($call, 0, $call));
        $this->assertFalse(TemplateSnippetMatcher::endsAt($call . 'x', \strlen($call) - 1, $call));

        // Multibyte: offsets are bytes, as Psalm's are.
        $this->assertTrue(TemplateSnippetMatcher::endsAt("é{$call}", \strlen("é{$call}"), $call));
    }
}
