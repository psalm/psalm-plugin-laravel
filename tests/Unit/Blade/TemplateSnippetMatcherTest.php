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
}
