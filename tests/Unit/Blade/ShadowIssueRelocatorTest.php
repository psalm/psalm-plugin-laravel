<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\CodeLocation\Raw;
use Psalm\Issue\CodeIssue;
use Psalm\Issue\DocblockTypeContradiction;
use Psalm\Issue\InvalidArrayOffset;
use Psalm\Issue\InvalidScope;
use Psalm\Issue\MissingClosureParamType;
use Psalm\Issue\MissingClosureReturnType;
use Psalm\Issue\MixedAssignment;
use Psalm\Issue\NonStaticSelfCall;
use Psalm\Issue\PossiblyFalseArgument;
use Psalm\Issue\PossiblyInvalidArgument;
use Psalm\Issue\RedundantCondition;
use Psalm\Issue\RedundantConditionGivenDocblockType;
use Psalm\Issue\TooManyArguments;
use Psalm\Issue\TypeDoesNotContainNull;
use Psalm\Issue\TypeDoesNotContainType;
use Psalm\Issue\UndefinedMethod;
use Psalm\Issue\UndefinedThisPropertyAssignment;
use Psalm\Issue\UndefinedThisPropertyFetch;
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

    /**
     * #1545: `ExistingAtomicMethodCallAnalyzer`'s `__get` handling re-checks `sealAllProperties`
     * against a `VirtualMethodCall` Psalm synthesizes to model the magic call, and reports this
     * class regardless of the real receiver, duplicating the `UndefinedMagicPropertyFetch` the
     * direct property-fetch site already reports on the correct line. That synthesized node carries
     * NO location attributes at all, so its issue is always unmapped; the genuine emission carries
     * the real fetch node and maps normally, so an unmapped instance of this class is always the
     * synthesized duplicate, never a genuine `$this` fetch.
     */
    #[Test]
    public function an_unmapped_undefined_this_property_fetch_is_dropped(): void
    {
        $issue = new UndefinedThisPropertyFetch('Instance property Foo::$bar is not defined', $this->shadowLocation(2), 'Foo::$bar');

        $this->assertFalse($this->relocate($issue, $this->entry([2 => 0])));
    }

    /** Negative: a MAPPED instance of the same class is a real signal and must keep reporting. */
    #[Test]
    public function a_mapped_undefined_this_property_fetch_survives(): void
    {
        $issue = new UndefinedThisPropertyFetch('Instance property Foo::$bar is not defined', $this->shadowLocation(9), 'Foo::$bar');

        $relocated = $this->relocate($issue, $this->entry([9 => 3]));

        $this->assertInstanceOf(UndefinedThisPropertyFetch::class, $relocated);
        $this->assertSame(3, $relocated->code_location->getLineNumber());
    }

    /**
     * #1545 review: `UndefinedThisPropertyAssignment` LOOKS like the identical `__set` sibling
     * (same kind of synthesized, positionless `VirtualMethodCall` node via
     * `InstancePropertyAssignmentAnalyzer::analyzeSetCall()`), but is deliberately NOT dropped.
     * Its twin, `UndefinedMagicPropertyAssignment`, requires a resolved `$var_id` and is never
     * emitted for a non-variable receiver (`Magic::make()->missing = 1`); for that receiver shape
     * this unmapped issue is the ONLY diagnostic, and the relocator sees one issue at a time with
     * no way to know whether a twin fired for the same access — so it stays reported on line 1,
     * same as any other unmapped non-`MixedIssue`.
     */
    #[Test]
    public function an_unmapped_undefined_this_property_assignment_is_still_reported_on_line_one(): void
    {
        $issue = new UndefinedThisPropertyAssignment('Instance property Foo::$bar is not defined', $this->shadowLocation(2), 'Foo::$bar');

        $relocated = $this->relocate($issue, $this->entry([2 => 0]));

        $this->assertInstanceOf(UndefinedThisPropertyAssignment::class, $relocated);
        $this->assertSame(1, $relocated->code_location->getLineNumber());
        $this->assertSame('Instance property Foo::$bar is not defined (unmapped)', $relocated->message);
    }

    /** A MAPPED instance was never affected by the unmapped-branch logic either way. */
    #[Test]
    public function a_mapped_undefined_this_property_assignment_survives(): void
    {
        $issue = new UndefinedThisPropertyAssignment('Instance property Foo::$bar is not defined', $this->shadowLocation(9), 'Foo::$bar');

        $relocated = $this->relocate($issue, $this->entry([9 => 3]));

        $this->assertInstanceOf(UndefinedThisPropertyAssignment::class, $relocated);
        $this->assertSame(3, $relocated->code_location->getLineNumber());
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

    /**
     * #1566: `@error('field')` compiles to `$__errorArgs = ['field']; $__bag =
     * $errors->getBag($__errorArgs[1] ?? 'default');` — a single-argument directive's `$__errorArgs`
     * is a literal one-element list, so the `[1]` probe is a definite invalid offset the template
     * author never wrote and cannot act on.
     */
    #[Test]
    public function a_compiled_error_args_offset_is_dropped_unconditionally(): void
    {
        $issue = new InvalidArrayOffset(
            "Cannot access value on variable \$__errorArgs using offset value of '1', expecting 0",
            $this->shadowLocation(9),
        );

        $this->assertFalse($this->relocate($issue, $this->entry([9 => 3])));
    }

    /**
     * Negative: identical wording naming an author's own variable must keep reporting — the gate is
     * exact-name on `$__errorArgs`, not a wide `__`-prefix family (#1566).
     */
    #[Test]
    public function an_authors_own_invalid_array_offset_survives(): void
    {
        $issue = new InvalidArrayOffset(
            "Cannot access value on variable \$arr using offset value of '1', expecting 0",
            $this->shadowLocation(9),
        );

        $relocated = $this->relocate($issue, $this->entry([9 => 3]));

        $this->assertInstanceOf(InvalidArrayOffset::class, $relocated);
        $this->assertSame(3, $relocated->code_location->getLineNumber());
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
     * #1546: `$errors`, like `$component`, is dropped with no `isComponentView` requirement —
     * `ShareErrorsFromSession` runs in the `web` middleware group, not the `render()` call itself,
     * so the prelude declares it in EVERY shadow. Unlike `$component`, only the DOCBLOCK branch of
     * the two message shapes is matched (`isAmbientDocblockGuardName()`), because `$errors`'s
     * ambient type is always docblock-declared, never inferred; see the inferred-branch survival
     * test below for why that narrower match matters.
     */
    #[Test]
    public function an_ambient_errors_redundant_condition_is_dropped_outside_a_component_view(): void
    {
        $issue = new RedundantConditionGivenDocblockType(
            'Docblock-defined type Illuminate\Support\ViewErrorBag for $errors is never null',
            $this->shadowLocation(9),
            null,
        );

        $this->assertFalse($this->relocate($issue, $this->entry([9 => 3]), isComponentView: false));
    }

    #[Test]
    public function an_ambient_errors_docblock_contradiction_is_dropped_outside_a_component_view(): void
    {
        $issue = new DocblockTypeContradiction(
            'Cannot resolve types for $errors - docblock-defined type Illuminate\Support\ViewErrorBag does not contain null',
            $this->shadowLocation(9),
            null,
        );

        $this->assertFalse($this->relocate($issue, $this->entry([9 => 3]), isComponentView: false));
    }

    /**
     * Negative: a message that names `$errors` but does not match either anchored shape (no
     * `for $errors`, does not start with `Cannot resolve types for $errors`) must keep reporting.
     * Drawn from the corpus's own out-of-scope case: `Operand of type ViewErrorBag is always
     * truthy` names no variable at all, so it is ungateable by construction.
     */
    #[Test]
    public function a_non_matching_errors_message_shape_survives(): void
    {
        $issue = new RedundantConditionGivenDocblockType(
            'Operand of type Illuminate\Support\ViewErrorBag is always truthy',
            $this->shadowLocation(9),
            null,
        );

        $relocated = $this->relocate($issue, $this->entry([9 => 3]), isComponentView: false);

        $this->assertInstanceOf(RedundantConditionGivenDocblockType::class, $relocated);
        $this->assertSame(3, $relocated->code_location->getLineNumber());
    }

    /**
     * #1546 review: an author who reassigns `$errors` before guarding it (`@php $errors = 42;
     * @endphp @if (is_string($errors))`) replaces the prelude's docblock type with an INFERRED
     * one, so `Reconciler::triggerIssueForImpossible()` takes the non-docblock branch and renders
     * `Type 42 for $errors is never string` — no `Docblock-defined`/`docblock-defined` text. A
     * gate matched on `isAmbientGuardName()` (the same wide match `$component` uses) would drop
     * this GENUINE author contradiction; `isAmbientDocblockGuardName()` requires that text and so
     * must not match it.
     */
    #[Test]
    public function an_inferred_type_errors_contradiction_survives(): void
    {
        $issue = new TypeDoesNotContainType(
            'Type 42 for $errors is never string',
            $this->shadowLocation(9),
            null,
        );

        $relocated = $this->relocate($issue, $this->entry([9 => 3]), isComponentView: false);

        $this->assertInstanceOf(TypeDoesNotContainType::class, $relocated);
        $this->assertSame(3, $relocated->code_location->getLineNumber());
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

    /**
     * #1557: `compileAware()`'s generated `foreach ($expr as $__key => $__value) { is_string($__key)
     * ? ... }` iterates a literal array, so Psalm enumerates the single pair and narrows `$__key` to
     * its literal key — the template author never wrote `$__key` and cannot act on a guard about it.
     * Exact message reproduced against `components/nested-attributes-aware.blade.php`
     * ({@see \Tests\Psalm\LaravelPlugin\Unit\Blade\BladeIssueRemapTest}). Dropped unconditionally,
     * not gated on `isComponentView`: the bookkeeping compiles the same way whether or not the
     * enclosing view is itself a component.
     */
    #[Test]
    public function an_ambient_key_redundant_condition_is_dropped_unconditionally(): void
    {
        $issue = new RedundantCondition("Type 'type' for \$__key is always string", $this->shadowLocation(9), null);

        $this->assertFalse($this->relocate($issue, $this->entry([9 => 3]), isComponentView: false));
    }

    /** Same shape, the `TypeDoesNotContainType` sibling the negated `is_string($__key)` arm hits. */
    #[Test]
    public function an_ambient_key_type_does_not_contain_type_is_dropped_unconditionally(): void
    {
        $issue = new TypeDoesNotContainType("Type 'type' for \$__key is always !string", $this->shadowLocation(9), null);

        $this->assertFalse($this->relocate($issue, $this->entry([9 => 3]), isComponentView: false));
    }

    /**
     * The `AssertionReconciler` message shape (name right after `"for "`, anchored to the message
     * start), covering the other anchor `isAmbientGuardName()` matches — `$__value` rather than
     * `$__key`, since the gate's pattern fragment must match ANY `__`-prefixed name, not just the
     * one name the two positive tests above happen to use.
     */
    #[Test]
    public function an_ambient_value_docblock_contradiction_is_dropped_unconditionally(): void
    {
        $issue = new DocblockTypeContradiction(
            'Cannot resolve types for $__value - array<string, string> does not contain string',
            $this->shadowLocation(9),
            null,
        );

        $this->assertFalse($this->relocate($issue, $this->entry([9 => 3]), isComponentView: false));
    }

    /**
     * Negative: the gate's pattern fragment requires a literal DOUBLE underscore (`__`), the prefix
     * Blade's own compiler reserves for its bookkeeping — a single-underscore name an author chose
     * themselves (`$_key`) is not compiled bookkeeping and must keep reporting.
     */
    #[Test]
    public function a_single_underscore_key_name_survives(): void
    {
        $issue = new RedundantCondition("Type 'type' for \$_key is always string", $this->shadowLocation(9), null);

        $relocated = $this->relocate($issue, $this->entry([9 => 3]), isComponentView: false);

        $this->assertInstanceOf(RedundantCondition::class, $relocated);
        $this->assertSame(3, $relocated->code_location->getLineNumber());
    }

    /**
     * Negative: `$__tmp_nullsafe__<offset>` is Psalm's OWN synthesized temp for an author-written
     * `?->` chain, not Blade compiler bookkeeping — it reports this same issue family identically in
     * a plain `.php` file, so it is genuine author signal and the negative lookahead in the gate's
     * pattern fragment must carve it out of the wide `__`-prefix match.
     */
    #[Test]
    public function a_nullsafe_temp_variable_survives(): void
    {
        $issue = new TypeDoesNotContainNull(
            'Cannot resolve types for $__tmp_nullsafe__16812 - string does not contain null',
            $this->shadowLocation(9),
            null,
        );

        $relocated = $this->relocate($issue, $this->entry([9 => 3]), isComponentView: false);

        $this->assertInstanceOf(TypeDoesNotContainNull::class, $relocated);
        $this->assertSame(3, $relocated->code_location->getLineNumber());
    }

    /**
     * #1557 review: `$__env`, unlike every other `$__`-prefixed name, IS declared via the prelude's
     * own `@var` docblock (`PreludeBuilder::AMBIENT_TYPES`) — the same shape `$errors` above is
     * excluded for. An author's own reassignment (`@php $__env = 42; @endphp @if
     * (is_string($__env))`) replaces that docblock type with an INFERRED one, rendering this
     * plain, non-"Docblock-defined" wording — genuine author signal the wide `$__`-prefix match
     * would otherwise wrongly drop as compiled bookkeeping.
     */
    #[Test]
    public function an_inferred_type_dunder_env_contradiction_survives(): void
    {
        $issue = new TypeDoesNotContainType('Type 42 for $__env is never string', $this->shadowLocation(9), null);

        $relocated = $this->relocate($issue, $this->entry([9 => 3]), isComponentView: false);

        $this->assertInstanceOf(TypeDoesNotContainType::class, $relocated);
        $this->assertSame(3, $relocated->code_location->getLineNumber());
    }

    /** The docblock-branch counterpart: `$__env`'s OWN ambient guard, dropped like `$errors`'s. */
    #[Test]
    public function an_ambient_dunder_env_docblock_contradiction_is_dropped(): void
    {
        $issue = new RedundantConditionGivenDocblockType(
            'Docblock-defined type Illuminate\View\Factory for $__env is never null',
            $this->shadowLocation(9),
            null,
        );

        $this->assertFalse($this->relocate($issue, $this->entry([9 => 3]), isComponentView: false));
    }

    /**
     * Negative: the `$__env` exclusion from the wide `$__`-prefix match is exact-name, not a
     * prefix — `$__environment` is compiled bookkeeping like any other `$__`-prefixed local and
     * must still be dropped, proving the lookahead's word boundary doesn't over-exclude.
     */
    #[Test]
    public function a_dunder_environment_name_is_still_dropped(): void
    {
        $issue = new RedundantCondition("Type 'x' for \$__environment is always string", $this->shadowLocation(9), null);

        $this->assertFalse($this->relocate($issue, $this->entry([9 => 3]), isComponentView: false));
    }

    /**
     * #1557 review (CLI P2): `array_map([$obj, 'method'], [...])` against a callback carrying
     * `@psalm-assert` synthesizes `$__fake_<id>_method_call_var__`, and CAN render into the
     * `Cannot resolve types for $key - ...` anchored shape — genuine author-actionable signal
     * about the callback's own contract, not Blade bookkeeping. Excluded from the wide match
     * alongside `__tmp_*`.
     */
    #[Test]
    public function a_fake_method_call_var_survives(): void
    {
        $issue = new TypeDoesNotContainType(
            'Cannot resolve types for $__fake_123_method_call_var__ - ReviewFirst does not contain ReviewSecond',
            $this->shadowLocation(9),
            null,
        );

        $relocated = $this->relocate($issue, $this->entry([9 => 3]), isComponentView: false);

        $this->assertInstanceOf(TypeDoesNotContainType::class, $relocated);
        $this->assertSame(3, $relocated->code_location->getLineNumber());
    }

    /**
     * #1559: a Blade shadow is classless global scope, so `$this->method()` in a plain template
     * reports this exact message (MethodCallAnalyzer.php's dedicated `!$statements_analyzer->
     * getFQCLN()` check, ahead of the general `$this` handling below) — flooding noise on
     * Livewire/Filament templates, never author signal, since the template author cannot declare a
     * class around their own code.
     */
    #[Test]
    public function this_in_non_class_context_is_dropped_unconditionally(): void
    {
        $issue = new InvalidScope('Use of $this in non-class context', $this->shadowLocation(9));

        $this->assertFalse($this->relocate($issue, $this->entry([9 => 3])));
    }

    /**
     * Negative: VariableFetchAnalyzer.php's sibling message for a bare `$this` fetch is NOT gated
     * — it is not only the classless-template shape. `ClosureAnalyzer.php::analyzeExpression()`
     * only threads `$this` into a closure's `use_context` when NEITHER the enclosing method nor
     * the closure itself is static (lines 92-105); a non-static closure written inside a STATIC
     * method of a real class `@php class ... @endphp` compiles into the shadow therefore has no
     * `$this` in scope even though it sits lexically inside a class, and its own `$this->` read
     * genuinely fails this exact check — the runtime confirms with `Using $this when not in
     * object context`. Corpus review found zero occurrences of this message among the messages
     * the sibling drop above covers (all were `Use of $this in non-class context`), so this
     * message is left alone entirely rather than risk swallowing that shape.
     */
    #[Test]
    public function this_in_non_class_context_bare_fetch_survives(): void
    {
        $issue = new InvalidScope('Invalid reference to $this in a non-class context', $this->shadowLocation(9));

        $relocated = $this->relocate($issue, $this->entry([9 => 3]));

        $this->assertInstanceOf(InvalidScope::class, $relocated);
        $this->assertSame(3, $relocated->code_location->getLineNumber());
    }

    /**
     * Negative: `$this` inside a static method IS a class context, so this message is genuine
     * author signal — reported only when `@php class ... @endphp` compiles a real class into the
     * shadow (VariableFetchAnalyzer.php's `$statements_analyzer->isStatic()` branch) — and must
     * keep reporting on the template.
     */
    #[Test]
    public function this_in_a_static_context_survives(): void
    {
        $issue = new InvalidScope('Invalid reference to $this in a static context', $this->shadowLocation(9));

        $relocated = $this->relocate($issue, $this->entry([9 => 3]));

        $this->assertInstanceOf(InvalidScope::class, $relocated);
        $this->assertSame(3, $relocated->code_location->getLineNumber());
    }

    /**
     * Negative: `$this = 1` (AssignmentAnalyzer.php's `Cannot re-assign $this`) is a PHP fatal the
     * author literally wrote in `@php`, never compiled bookkeeping — must keep reporting even
     * though its issue class is also `InvalidScope`.
     */
    #[Test]
    public function a_this_reassignment_survives(): void
    {
        $issue = new InvalidScope('Cannot re-assign $this', $this->shadowLocation(9));

        $relocated = $this->relocate($issue, $this->entry([9 => 3]));

        $this->assertInstanceOf(InvalidScope::class, $relocated);
        $this->assertSame(3, $relocated->code_location->getLineNumber());
    }

    /**
     * `self::bar()`/`self::CONST` outside any class (StaticCallAnalyzer.php and
     * ClassConstAnalyzer.php share this class): the same classless-shadow flooding as `$this`
     * above.
     */
    #[Test]
    public function self_outside_class_context_is_dropped_unconditionally(): void
    {
        $issue = new NonStaticSelfCall('Cannot use self outside class context', $this->shadowLocation(9));

        $this->assertFalse($this->relocate($issue, $this->entry([9 => 3])));
    }

    /**
     * StaticCallAnalyzer.php preserves the AUTHOR's own case for this message (unlike
     * ClassConstAnalyzer.php, which always lowercases it): an uppercase `SELF::bar()` call is the
     * SAME flooding shape and must be dropped too, so the gate cannot be anchored to a lowercase
     * literal.
     */
    #[Test]
    public function an_uppercase_self_call_outside_class_context_is_also_dropped(): void
    {
        $issue = new NonStaticSelfCall('Cannot use SELF outside class context', $this->shadowLocation(9));

        $this->assertFalse($this->relocate($issue, $this->entry([9 => 3])));
    }

    /** `static::` is the same classless-shadow flooding as `self::` above. */
    #[Test]
    public function static_outside_class_context_is_dropped_unconditionally(): void
    {
        $issue = new NonStaticSelfCall('Cannot use static outside class context', $this->shadowLocation(9));

        $this->assertFalse($this->relocate($issue, $this->entry([9 => 3])));
    }

    /**
     * Negative: StaticCallAnalyzer.php's own keyword check is case-INSENSITIVE (`self`/`static`/
     * `parent` all match via `strtolower()`), but its `parent` HANDLING is case-SENSITIVE — only
     * a literal lowercase `parent` reaches the dedicated `ParentNotFound` branch. `PARENT::x()`/
     * `Parent::x()` outside a class instead falls through to the SAME `NonStaticSelfCall` branch
     * as `self`/`static`, rendering `Cannot use PARENT outside class context` — a message this
     * gate must never match, because the `parent::` family is explicitly out of scope (near-zero
     * corpus prevalence) and stays with Psalm's own handling.
     */
    #[Test]
    public function an_uppercase_parent_call_outside_class_context_survives(): void
    {
        $issue = new NonStaticSelfCall('Cannot use PARENT outside class context', $this->shadowLocation(9));

        $relocated = $this->relocate($issue, $this->entry([9 => 3]));

        $this->assertInstanceOf(NonStaticSelfCall::class, $relocated);
        $this->assertSame(3, $relocated->code_location->getLineNumber());
    }

    /**
     * Negative: a non-static method called via `self::` FROM WITHIN a real class
     * (MethodAnalyzer.php) is genuine author signal, never compiled bookkeeping — must keep
     * reporting even though its class is also `NonStaticSelfCall`.
     */
    #[Test]
    public function a_non_static_method_called_via_self_survives(): void
    {
        $issue = new NonStaticSelfCall('Method Foo::bar is not static, but is called using self::', $this->shadowLocation(9));

        $relocated = $this->relocate($issue, $this->entry([9 => 3]));

        $this->assertInstanceOf(NonStaticSelfCall::class, $relocated);
        $this->assertSame(3, $relocated->code_location->getLineNumber());
    }
}
