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
use Psalm\Issue\PossiblyFalseArgument;
use Psalm\Issue\PossiblyInvalidArgument;
use Psalm\Issue\RedundantCondition;
use Psalm\Issue\RedundantConditionGivenDocblockType;
use Psalm\Issue\TooManyArguments;
use Psalm\Issue\TypeDoesNotContainNull;
use Psalm\Issue\TypeDoesNotContainType;
use Psalm\Issue\UndefinedMethod;
use Psalm\Issue\UndefinedVariable;
use Psalm\Issue\UnevaluatedCode;
use Psalm\Issue\UnusedForeachValue;
use Psalm\Issue\UnusedVariable;
use Psalm\LaravelPlugin\Blade\MarkerComment;
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
        $target = new ShadowTarget($entry, self::TEMPLATE_SOURCE, 'resources/views/profile.blade.php', $isComponentView, MarkerComment::prefixFor(self::TEMPLATE_SOURCE));

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
     * #1535, both halves of the echo-position family. Same "cannot be read" case as the arity gate
     * above: without a booted ProjectAnalyzer `getSnippet()` throws, and an issue whose callee and
     * argument position both match the gate must still come back relocated rather than dropped.
     * The discriminating behaviour needs a real compiled shadow and is pinned by
     * {@see EchoUnionArgumentTest}; the string rule itself by {@see TemplateSnippetMatcherTest}.
     */
    #[Test]
    public function an_echo_position_argument_issue_is_kept_when_its_snippet_cannot_be_read(): void
    {
        $issue = new PossiblyInvalidArgument(
            'Argument 1 of e expects string, but possibly different type array<array-key, mixed>|null|string provided',
            $this->shadowLocation(9),
            'e',
        );

        $relocated = $this->relocate($issue, $this->entry([9 => 3]));

        $this->assertInstanceOf(PossiblyInvalidArgument::class, $relocated);
        $this->assertSame(3, $relocated->code_location->getLineNumber());
    }

    #[Test]
    public function an_echo_position_false_argument_issue_is_kept_when_its_snippet_cannot_be_read(): void
    {
        $issue = new PossiblyFalseArgument(
            'Argument 1 of echo cannot be false, possibly string value expected',
            $this->shadowLocation(9),
            'echo',
        );

        $relocated = $this->relocate($issue, $this->entry([9 => 3]));

        $this->assertInstanceOf(PossiblyFalseArgument::class, $relocated);
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

    /** Bare `$slot`, mirroring the `$attributes` case above: dropped inside a component view. */
    #[Test]
    public function a_bare_slot_redundant_condition_is_dropped_inside_a_component_view(): void
    {
        $issue = new RedundantCondition(
            'Type Illuminate\View\ComponentSlot for $slot is never null',
            $this->shadowLocation(9),
            null,
        );

        $this->assertFalse($this->relocate($issue, $this->entry([9 => 3]), isComponentView: true));
    }

    /** Negative: the SAME bare `$slot` message, outside a component view, must survive. */
    #[Test]
    public function a_bare_slot_redundant_condition_survives_outside_a_component_view(): void
    {
        $issue = new RedundantCondition(
            'Type Illuminate\View\ComponentSlot for $slot is never null',
            $this->shadowLocation(9),
            null,
        );

        $relocated = $this->relocate($issue, $this->entry([9 => 3]), isComponentView: false);

        $this->assertInstanceOf(RedundantCondition::class, $relocated);
        $this->assertSame(3, $relocated->code_location->getLineNumber());
    }

    /**
     * `Reconciler::triggerIssueForImpossible()`'s INFERRED-branch siblings of
     * `RedundantCondition`/`RedundantConditionGivenDocblockType`/`DocblockTypeContradiction`: the
     * shape a `@props` view's nested `<x-...>` tag actually hits, since `@props` replaces the
     * prelude's docblock type with an inferred one before the nested tag's own guard runs.
     */
    #[Test]
    public function type_does_not_contain_null_is_dropped_inside_a_component_view(): void
    {
        $issue = new TypeDoesNotContainNull(
            'Cannot resolve types for $attributes - Illuminate\View\ComponentAttributeBag does not contain null',
            $this->shadowLocation(9),
            null,
        );

        $this->assertFalse($this->relocate($issue, $this->entry([9 => 3]), isComponentView: true));
    }

    #[Test]
    public function type_does_not_contain_type_is_dropped_inside_a_component_view(): void
    {
        $issue = new TypeDoesNotContainType(
            'Illuminate\View\ComponentAttributeBag for $attributes is never Illuminate\View\Component',
            $this->shadowLocation(9),
            null,
        );

        $this->assertFalse($this->relocate($issue, $this->entry([9 => 3]), isComponentView: true));
    }

    /**
     * Negative: the SAME message, outside a component view. The prelude never declares
     * `$attributes`/`$slot` there, so for those two names the drop must not fire on a message
     * shape alone.
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
     * #1532: unlike `$attributes`/`$slot`, `$component` is dropped even OUTSIDE a component view —
     * its narrowed type comes from a PRECEDING `<x-...>` tag's compiled `resolve()` call, a shape a plain page
     * hits just as much as a `@props`/`@aware` view.
     */
    #[Test]
    public function an_ambient_component_guard_is_dropped_outside_a_component_view(): void
    {
        $issue = new RedundantCondition(
            'Type Illuminate\View\AnonymousComponent for $component is never null',
            $this->shadowLocation(9),
            null,
        );

        $this->assertFalse($this->relocate($issue, $this->entry([9 => 3]), isComponentView: false));
    }

    /**
     * #1532 review: a bare substring search for `" for $component"` also matches a rendered TYPE
     * that happens to quote it. `Reconciler::triggerIssueForImpossible()` puts `$key` immediately
     * BEFORE `" is (never|always) "` in this message shape (`Type <type> for $key is ... <assertion>`)
     * — here the real key is `$range` and the TYPE is the literal string `' for $component '`, so a
     * substring-only gate drops an author's OWN guard because its rendered type happens to contain
     * the ambient name.
     */
    #[Test]
    public function a_literal_type_string_naming_component_does_not_mask_the_real_key(): void
    {
        $issue = new RedundantCondition(
            "Type ' for \$component ' for \$range is always isset",
            $this->shadowLocation(9),
            null,
        );

        $relocated = $this->relocate($issue, $this->entry([9 => 3]), isComponentView: false);

        $this->assertInstanceOf(RedundantCondition::class, $relocated);
        $this->assertSame(3, $relocated->code_location->getLineNumber());
    }

    /**
     * #1532 review, the mirror hazard: `AssertionReconciler`'s message shape puts `$key` right after
     * `"for "` and the TYPE afterward (`Cannot resolve types for $key - docblock-defined type <type>
     * does not contain ...`), so here the real key is `$range` and the TYPE (rendered after the
     * dash) is the literal string `' for $attributes are great'` — the gate must anchor on the
     * message START, not find the name anywhere in the tail, or this drops the author's OWN guard
     * inside a component view.
     */
    #[Test]
    public function a_literal_type_string_naming_attributes_does_not_mask_the_real_key(): void
    {
        $issue = new DocblockTypeContradiction(
            "Cannot resolve types for \$range - docblock-defined type ' for \$attributes are great' does not contain null",
            $this->shadowLocation(9),
            null,
        );

        $relocated = $this->relocate($issue, $this->entry([9 => 3]), isComponentView: true);

        $this->assertInstanceOf(DocblockTypeContradiction::class, $relocated);
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

    /**
     * Negative: the `$component` drop applies OUTSIDE a component view too, so it needs the same
     * `->` boundary check as `$slot->attributes` above, on a receiver where `isComponentView` can't
     * rescue a missed boundary.
     */
    #[Test]
    public function a_message_naming_a_property_fetch_on_component_survives_outside_a_component_view(): void
    {
        $issue = new RedundantCondition('Type string for $component->name is never null', $this->shadowLocation(9), null);

        $relocated = $this->relocate($issue, $this->entry([9 => 3]), isComponentView: false);

        $this->assertInstanceOf(RedundantCondition::class, $relocated);
        $this->assertSame(3, $relocated->code_location->getLineNumber());
    }
}
