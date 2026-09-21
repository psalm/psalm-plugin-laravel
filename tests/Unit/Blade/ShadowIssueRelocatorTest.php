<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\CodeLocation\Raw;
use Psalm\Issue\CodeIssue;
use Psalm\Issue\DocblockTypeContradiction;
use Psalm\Issue\MissingClosureParamType;
use Psalm\Issue\MissingClosureReturnType;
use Psalm\Issue\MixedAssignment;
use Psalm\Issue\RedundantCondition;
use Psalm\Issue\RedundantConditionGivenDocblockType;
use Psalm\Issue\TooManyArguments;
use Psalm\Issue\UndefinedMethod;
use Psalm\Issue\UndefinedVariable;
use Psalm\Issue\UnevaluatedCode;
use Psalm\Issue\UnusedForeachValue;
use Psalm\Issue\UnusedVariable;
use Psalm\LaravelPlugin\Blade\ShadowEntry;
use Psalm\LaravelPlugin\Blade\ShadowIssueRelocator;
use Psalm\LaravelPlugin\Blade\ShadowTarget;

#[CoversClass(ShadowIssueRelocator::class)]
final class ShadowIssueRelocatorTest extends TestCase
{
    private const SHADOW = '/tmp/shadow.php';

    private const TEMPLATE = '/app/resources/views/profile.blade.php';

    private const TEMPLATE_SOURCE = "<div>\n  <p>first</p>\n  <p>second</p>\n</div>\n";

    /** @param array<int, int> $lineMap */
    private function entry(array $lineMap): ShadowEntry
    {
        return new ShadowEntry(self::TEMPLATE, $lineMap, []);
    }

    /** A location on the shadow, as Psalm would hand one to the handler. */
    private function shadowLocation(int $line): \Psalm\CodeLocation\Raw
    {
        return new Raw(\str_repeat("\n", $line - 1), self::SHADOW, 'shadow.php', $line - 1, $line - 1);
    }

    private function relocate(
        CodeIssue $issue,
        ShadowEntry $entry,
        bool $reportMixed = false,
        bool $isComponentView = false,
    ): CodeIssue|false|null {
        $target = new ShadowTarget($entry, self::TEMPLATE_SOURCE, 'resources/views/profile.blade.php', $isComponentView);

        // No other shadow: none of these cases is a taint issue, so the journey resolver is never
        // reached. {@see JourneyRemapperTest} covers it.
        return ShadowIssueRelocator::relocate($issue, $target, static fn(): null => null, $reportMixed);
    }

    #[Test]
    public function it_rebuilds_a_three_argument_issue_class_on_the_template_line(): void
    {
        $issue = new UndefinedMethod('Method Foo::bar does not exist', $this->shadowLocation(9), 'Foo::bar');

        $relocated = $this->relocate($issue, $this->entry([9 => 3]));

        $this->assertInstanceOf(UndefinedMethod::class, $relocated);
        // The third constructor argument only survives a reflective rebuild; a two-argument
        // `new $class(...)` throws for it, which inside an event handler loses the issue.
        $this->assertSame('foo::bar', $relocated->method_id);
        $this->assertSame($issue->message, $relocated->message);
        $this->assertSame(self::TEMPLATE, $relocated->getFilePath());
        $this->assertSame(3, $relocated->code_location->getLineNumber());
    }

    #[Test]
    public function it_points_the_location_at_the_bytes_of_the_mapped_line(): void
    {
        $issue = new UndefinedVariable('Cannot find referenced variable $x', $this->shadowLocation(4));

        $relocated = $this->relocate($issue, $this->entry([4 => 2]));

        $this->assertInstanceOf(CodeIssue::class, $relocated);
        $this->assertSame(
            '  <p>first</p>',
            \substr(
                self::TEMPLATE_SOURCE,
                $relocated->code_location->raw_file_start,
                $relocated->code_location->raw_file_end - $relocated->code_location->raw_file_start + 1,
            ),
        );
    }

    #[Test]
    public function it_drops_an_unmapped_mixed_issue(): void
    {
        // Prelude lines map to 0: the prelude types every unresolved template variable as mixed,
        // so a Mixed* issue there says nothing about the template. Run with the flag ON so this
        // keeps pinning the unmapped-line path specifically, not the new default-off behavior.
        $issue = new MixedAssignment('Unable to determine the type', $this->shadowLocation(2));

        $this->assertFalse($this->relocate($issue, $this->entry([2 => 0]), reportMixed: true));
    }

    #[Test]
    public function a_mapped_mixed_issue_is_dropped_by_default(): void
    {
        $issue = new MixedAssignment('Unable to determine the type', $this->shadowLocation(9));

        $this->assertFalse($this->relocate($issue, $this->entry([9 => 3])));
    }

    #[Test]
    public function a_mapped_mixed_issue_is_rebuilt_when_reportmixed_is_on(): void
    {
        $issue = new MixedAssignment('Unable to determine the type', $this->shadowLocation(9));

        $relocated = $this->relocate($issue, $this->entry([9 => 3]), reportMixed: true);

        $this->assertInstanceOf(MixedAssignment::class, $relocated);
        $this->assertSame(3, $relocated->code_location->getLineNumber());
    }

    #[Test]
    public function a_mapped_non_mixed_issue_is_unaffected_by_the_flag(): void
    {
        $issue = new UndefinedVariable('Cannot find referenced variable $x', $this->shadowLocation(9));

        $off = $this->relocate($issue, $this->entry([9 => 3]));
        $on = $this->relocate($issue, $this->entry([9 => 3]), reportMixed: true);

        $this->assertInstanceOf(CodeIssue::class, $off);
        $this->assertInstanceOf(CodeIssue::class, $on);
        $this->assertSame(3, $off->code_location->getLineNumber());
        $this->assertSame(3, $on->code_location->getLineNumber());
    }

    #[Test]
    public function it_reemits_an_unmapped_non_mixed_issue_on_line_one(): void
    {
        $issue = new UndefinedVariable('Cannot find referenced variable $x', $this->shadowLocation(2));

        $relocated = $this->relocate($issue, $this->entry([2 => 0]));

        $this->assertInstanceOf(CodeIssue::class, $relocated);
        $this->assertSame(1, $relocated->code_location->getLineNumber());
        $this->assertSame('Cannot find referenced variable $x (unmapped)', $relocated->message);
    }

    #[Test]
    public function a_shadow_line_missing_from_the_map_is_treated_as_unmapped(): void
    {
        $issue = new UndefinedVariable('Cannot find referenced variable $x', $this->shadowLocation(7));

        $relocated = $this->relocate($issue, $this->entry([2 => 3]));

        $this->assertInstanceOf(CodeIssue::class, $relocated);
        $this->assertSame(1, $relocated->code_location->getLineNumber());
    }

    #[Test]
    public function it_declines_when_a_constructor_argument_cannot_be_resolved(): void
    {
        $issue = new UnresolvableIssue('boom', $this->shadowLocation(9), 'not a property');

        $this->assertNull($this->relocate($issue, $this->entry([9 => 2])));
    }

    #[Test]
    public function it_declines_when_the_template_has_no_such_line(): void
    {
        $issue = new UndefinedVariable('Cannot find referenced variable $x', $this->shadowLocation(9));

        $this->assertNull($this->relocate($issue, $this->entry([9 => 99])));
    }

    #[Test]
    public function a_missing_closure_param_type_is_dropped_unconditionally(): void
    {
        // Livewire's precompiler (and others like it) inject an untyped closure a template
        // author has no docblock position to annotate (#1498).
        $issue = new MissingClosureParamType('Parameter $x has no provided type', $this->shadowLocation(9));

        $this->assertFalse($this->relocate($issue, $this->entry([9 => 3])));
    }

    #[Test]
    public function a_missing_closure_return_type_is_dropped_unconditionally(): void
    {
        $issue = new MissingClosureReturnType('Closure does not have a return type', $this->shadowLocation(9));

        $this->assertFalse($this->relocate($issue, $this->entry([9 => 3])));
    }

    #[Test]
    public function an_unused_variable_is_dropped_unconditionally(): void
    {
        // Compiler bookkeeping ($__componentOriginal*, the tail $loop reassignment) the template
        // author never wrote and cannot read back (#1500).
        $issue = new UnusedVariable('$loop is never referenced or the value is not used', $this->shadowLocation(9));

        $this->assertFalse($this->relocate($issue, $this->entry([9 => 3])));
    }

    #[Test]
    public function the_trailing_inline_html_after_break_shape_is_dropped(): void
    {
        // A `@switch` arm's `@break` leaves Psalm treating the following `@case`/`@default` line
        // as unreachable (#1500). This is the exact message StatementsAnalyzer::processStmt() uses
        // for that shape.
        $issue = new UnevaluatedCode('Expressions after return/throw/continue', $this->shadowLocation(9));

        $this->assertFalse($this->relocate($issue, $this->entry([9 => 3])));
    }

    #[Test]
    public function the_gettype_impossible_value_shape_still_relocates(): void
    {
        // `UnevaluatedCode` is not one shape: AssertionFinder reports this SAME class, ungated by
        // find_unused_variables, for a `gettype()` comparison against a value it cannot return —
        // a genuine author typo, not compiler bookkeeping, so the drop must not catch it (#1500).
        $issue = new UnevaluatedCode('gettype cannot return this value', $this->shadowLocation(9));

        $relocated = $this->relocate($issue, $this->entry([9 => 3]));

        $this->assertInstanceOf(UnevaluatedCode::class, $relocated);
        $this->assertSame(3, $relocated->code_location->getLineNumber());
    }

    #[Test]
    public function an_unused_foreach_value_still_relocates(): void
    {
        // The author-named foreach variable is real signal, unlike the compiler's own $loop
        // bookkeeping dropped above, and must still be rebuilt on the template line.
        $issue = new UnusedForeachValue('$item is never referenced or the value is not used', $this->shadowLocation(9));

        $relocated = $this->relocate($issue, $this->entry([9 => 3]));

        $this->assertInstanceOf(UnusedForeachValue::class, $relocated);
        $this->assertSame(3, $relocated->code_location->getLineNumber());
    }

    #[Test]
    public function a_toomanyarguments_issue_is_kept_when_its_snippet_cannot_be_read(): void
    {
        // No ProjectAnalyzer is booted in this pure-unit context, so `getSnippet()` throws for any
        // real CodeLocation. That is exactly the "cannot be read" case the relocator must fail open
        // on: proof the try/catch keeps the issue rather than dropping it on error.
        $issue = new TooManyArguments('Too many arguments', $this->shadowLocation(9), 'Foo::bar');

        $relocated = $this->relocate($issue, $this->entry([9 => 3]));

        $this->assertInstanceOf(TooManyArguments::class, $relocated);
        $this->assertSame(3, $relocated->code_location->getLineNumber());
    }

    /**
     * #1525: the three families Laravel's own compiled `$attributes`/`$component`/`$slot` guards
     * collapse into, once the prelude declares them as guaranteed inside a component view.
     */
    #[Test]
    public function an_ambient_guard_redundant_condition_is_dropped_inside_a_component_view(): void
    {
        $issue = new RedundantConditionGivenDocblockType(
            'Docblock-defined type Illuminate\View\ComponentAttributeBag for $attributes is never null',
            $this->shadowLocation(9),
            null,
        );

        $this->assertFalse($this->relocate($issue, $this->entry([9 => 3]), isComponentView: true));
    }

    #[Test]
    public function an_ambient_guard_docblock_contradiction_on_component_is_dropped_inside_a_component_view(): void
    {
        $issue = new DocblockTypeContradiction(
            'Cannot resolve types for $component - docblock-defined type Illuminate\View\Component does not contain null',
            $this->shadowLocation(9),
            null,
        );

        $this->assertFalse($this->relocate($issue, $this->entry([9 => 3]), isComponentView: true));
    }

    /**
     * Negative: the SAME message, outside a component view. The prelude never declares
     * `$attributes`/`$component`/`$slot` there, so the drop must not fire on a message shape alone.
     */
    #[Test]
    public function the_same_ambient_guard_message_survives_outside_a_component_view(): void
    {
        $issue = new RedundantConditionGivenDocblockType(
            'Docblock-defined type Illuminate\View\ComponentAttributeBag for $attributes is never null',
            $this->shadowLocation(9),
            null,
        );

        $relocated = $this->relocate($issue, $this->entry([9 => 3]), isComponentView: false);

        $this->assertInstanceOf(RedundantConditionGivenDocblockType::class, $relocated);
        $this->assertSame(3, $relocated->code_location->getLineNumber());
    }

    /**
     * Negative: an author's own redundant check on their OWN docblock, inside a component view,
     * must keep reporting — the gate gets no wider than the three ambient names, even though the
     * class alone matches.
     */
    #[Test]
    public function an_authors_own_redundant_condition_survives_inside_a_component_view(): void
    {
        $issue = new RedundantCondition('Type string for $range is never null', $this->shadowLocation(9), null);

        $relocated = $this->relocate($issue, $this->entry([9 => 3]), isComponentView: true);

        $this->assertInstanceOf(RedundantCondition::class, $relocated);
        $this->assertSame(3, $relocated->code_location->getLineNumber());
    }

    /**
     * Negative: `$slot->attributes` is a real public property on ComponentSlot, so the message
     * naming it must not be caught by the `$attributes` gate — the boundary after the name must
     * reject `->`, not just accept a word boundary.
     */
    #[Test]
    public function a_message_naming_a_property_fetch_on_slot_survives_inside_a_component_view(): void
    {
        $issue = new RedundantCondition('Type string for $slot->attributes is never null', $this->shadowLocation(9), null);

        $relocated = $this->relocate($issue, $this->entry([9 => 3]), isComponentView: true);

        $this->assertInstanceOf(RedundantCondition::class, $relocated);
        $this->assertSame(3, $relocated->code_location->getLineNumber());
    }
}
