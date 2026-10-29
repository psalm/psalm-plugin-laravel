<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use PhpParser\Node\Expr\Throw_;
use PhpParser\Node\Expr\Variable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\Aliases;
use Psalm\CodeLocation;
use Psalm\CodeLocation\Raw;
use Psalm\FileSource;
use Psalm\Issue\CodeIssue;
use Psalm\Issue\DocblockTypeContradiction;
use Psalm\Issue\ImplicitToStringCast;
use Psalm\Issue\InvalidArrayOffset;
use Psalm\Issue\InvalidCast;
use Psalm\Issue\InvalidDocblock;
use Psalm\Issue\InvalidMethodCall;
use Psalm\Issue\InvalidNullableReturnType;
use Psalm\Issue\InvalidScope;
use Psalm\Issue\MissingPureAnnotation;
use Psalm\Issue\MixedAssignment;
use Psalm\Issue\NonStaticSelfCall;
use Psalm\Issue\NoValue;
use Psalm\Issue\NullArgument;
use Psalm\Issue\ParseError;
use Psalm\Issue\PossiblyInvalidArgument;
use Psalm\Issue\PossiblyInvalidCast;
use Psalm\Issue\PossiblyInvalidMethodCall;
use Psalm\Issue\PossiblyUndefinedGlobalVariable;
use Psalm\Issue\RedundantCondition;
use Psalm\Issue\RedundantConditionGivenDocblockType;
use Psalm\Issue\ReferenceReusedFromConfusingScope;
use Psalm\Issue\TooManyArguments;
use Psalm\Issue\TypeDoesNotContainNull;
use Psalm\Issue\TypeDoesNotContainType;
use Psalm\Issue\UndefinedMethod;
use Psalm\Issue\UndefinedThisPropertyAssignment;
use Psalm\Issue\UndefinedThisPropertyFetch;
use Psalm\Issue\UndefinedVariable;
use Psalm\Issue\UnevaluatedCode;
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

    /** The loop body line `CompilesComponents::compileAware()` emits. */
    private const AWARE_SHADOW_LINE = '$$__consumeVariable = is_string($__key) ? $__env->getConsumableComponentData($__key, $__value) : $__env->getConsumableComponentData($__value);';

    private const AWARE_KEYED_CALL = '$__env->getConsumableComponentData($__key';

    private const AWARE_LIST_CALL = '$__env->getConsumableComponentData($__value';

    private const AWARE_CALLEE = 'Illuminate\View\Factory::getConsumableComponentData';

    private const AWARE_NULL_ARGUMENT = 'Argument 1 of Illuminate\View\Factory::getConsumableComponentData cannot be null, null value provided to parameter with type string';

    /** The one line `{!! $links !!}` compiles to, as far as the cast gate reads it. */
    private const RAW_ECHO_LINE = '<?php echo $links; ?>';

    private const HTMLABLE_CAST = 'Illuminate\Contracts\Support\Htmlable cannot be cast to string';

    private const AWARE_NO_VALUE = 'All possible types for this argument were invalidated - This may be dead code';

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
        bool $isComponentView = false,
        string $templateSource = self::TEMPLATE_SOURCE,
        bool $attributesFromTag = false,
    ): CodeIssue|false|null {
        $target = new ShadowTarget($entry, $templateSource, 'resources/views/profile.blade.php', $isComponentView, MarkerComment::prefixFor($templateSource), $attributesFromTag);

        // No other shadow: none of these cases is a taint issue, so the journey resolver is never
        // reached. {@see JourneyRemapperTest} covers it.
        return ShadowIssueRelocator::relocate($issue, $target, static fn(): null => null);
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
        // so a Mixed* issue there says nothing about the template.
        $issue = new MixedAssignment('Unable to determine the type', $this->shadowLocation(2));

        $this->assertFalse($this->relocate($issue, $this->entry([2 => 0])));
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

    /**
     * #1723: Psalm prints the line of the first mention INSIDE the message. That number is a shadow
     * line, so it must be rewritten through the issue's own line map, not left naming the shadow.
     */
    #[Test]
    public function a_first_seen_line_reference_names_the_template_line(): void
    {
        $issue = new PossiblyUndefinedGlobalVariable(
            'Possibly undefined global variable $__authorLocal, first seen on line 31',
            $this->shadowLocation(40),
            '$__authorLocal',
        );

        $relocated = $this->relocate($issue, $this->entry([31 => 2, 40 => 3]));

        $this->assertInstanceOf(PossiblyUndefinedGlobalVariable::class, $relocated);
        $this->assertSame('Possibly undefined global variable $__authorLocal, first seen on line 2', $relocated->message);
        $this->assertSame(3, $relocated->code_location->getLineNumber());
        $this->assertSame('$__authorlocal', $relocated->var_name);
    }

    /** A reference into the compiler-generated prelude has no template line: omit it rather than invent one. */
    #[Test]
    public function a_first_seen_reference_into_the_prelude_drops_the_clause(): void
    {
        $issue = new PossiblyUndefinedGlobalVariable(
            'Possibly undefined global variable $__authorLocal, first seen on line 2',
            $this->shadowLocation(40),
            '$__authorLocal',
        );

        $relocated = $this->relocate($issue, $this->entry([2 => 0, 40 => 3]));

        $this->assertInstanceOf(PossiblyUndefinedGlobalVariable::class, $relocated);
        $this->assertSame('Possibly undefined global variable $__authorLocal', $relocated->message);
        $this->assertSame(3, $relocated->code_location->getLineNumber());
    }

    /** The clause is dropped BEFORE the `(unmapped)` suffix is appended, so the suffix is not part of the anchor. */
    #[Test]
    public function an_unmapped_parse_error_drops_the_clause_and_keeps_the_unmapped_suffix(): void
    {
        $issue = new ParseError('Syntax error, unexpected T_ENDIF on line 2', $this->shadowLocation(2));

        $relocated = $this->relocate($issue, $this->entry([2 => 0]));

        $this->assertInstanceOf(ParseError::class, $relocated);
        $this->assertSame('Syntax error, unexpected T_ENDIF (unmapped)', $relocated->message);
        $this->assertSame(1, $relocated->code_location->getLineNumber());
    }

    /** The class key is the gate: an identical-looking wording on any other class stays untouched. */
    #[Test]
    public function a_line_reference_on_an_unlisted_class_is_left_alone(): void
    {
        $issue = new UndefinedVariable('Cannot find referenced variable $x on line 31', $this->shadowLocation(40));

        $relocated = $this->relocate($issue, $this->entry([31 => 2, 40 => 3]));

        $this->assertInstanceOf(UndefinedVariable::class, $relocated);
        $this->assertSame('Cannot find referenced variable $x on line 31', $relocated->message);
    }

    /**
     * #1723: `ReferenceReusedFromConfusingScope` embeds `CodeLocation::getShortSummary()`
     * (`<shadow file name>:<line>:<column>`) in its message. The descriptor must name the template,
     * with the line resolved through the same map and the column kept.
     */
    #[Test]
    public function a_location_descriptor_inside_a_message_names_the_template(): void
    {
        $issue = new ReferenceReusedFromConfusingScope(
            '$x is possibly a reference defined at shadow.php:31:64. Reusing this variable may cause the referenced value to change.',
            $this->shadowLocation(40),
        );

        $relocated = $this->relocate($issue, $this->entry([31 => 3, 40 => 2]));

        $this->assertInstanceOf(ReferenceReusedFromConfusingScope::class, $relocated);
        $this->assertSame(
            '$x is possibly a reference defined at resources/views/profile.blade.php:3:64. Reusing this variable may cause the referenced value to change.',
            $relocated->message,
        );
        $this->assertSame(2, $relocated->code_location->getLineNumber());
    }

    /**
     * `CommentAnalyzer` appends `in <absolute shadow path>:<line>` (no column) to an invalid
     * docblock type's message; the cache path means nothing to the author and differs per machine.
     */
    #[Test]
    public function an_absolute_path_and_line_inside_a_message_names_the_template(): void
    {
        $issue = new InvalidDocblock(
            'array<int, string, bool> is not a valid type (Too many template parameters for array in ' . self::SHADOW . ':5)',
            $this->shadowLocation(5),
        );

        $relocated = $this->relocate($issue, $this->entry([5 => 2]));

        $this->assertInstanceOf(InvalidDocblock::class, $relocated);
        $this->assertSame(
            'array<int, string, bool> is not a valid type (Too many template parameters for array in resources/views/profile.blade.php:2)',
            $relocated->message,
        );
    }

    /**
     * A closure's id is `strtolower(<shadow path>):<line>:<byte offset>:-:closure`, and return-type
     * messages name it. The path differs per machine and the offset shifts with the prelude, so
     * both go: the id becomes `<template>:<template line>:-:closure`.
     */
    #[Test]
    public function a_closure_id_inside_a_message_names_the_template(): void
    {
        $shadow = '/tmp/Cache/Blade/Shadow.php';
        $issue = new InvalidNullableReturnType(
            "The declared return type 'string' for " . \strtolower($shadow) . ":5:25279:-:closure is not nullable, but the function returns 'null|string'",
            new Raw("\n\n\n\n", $shadow, 'Shadow.php', 4, 4),
        );

        $relocated = $this->relocate($issue, $this->entry([5 => 2]));

        $this->assertInstanceOf(InvalidNullableReturnType::class, $relocated);
        $this->assertSame(
            "The declared return type 'string' for resources/views/profile.blade.php:2:-:closure is not nullable, but the function returns 'null|string'",
            $relocated->message,
        );
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

    /**
     * A location built from a node Psalm synthesized (a desugared `match` arm's `VirtualThrow`)
     * carries no attributes, so its line is Psalm's positionless `-1`.
     */
    private function positionlessLocation(): CodeLocation
    {
        $source = new class implements FileSource {
            public function getFileName(): string
            {
                return 'shadow.php';
            }

            public function getFilePath(): string
            {
                return '/tmp/shadow.php';
            }

            public function getRootFileName(): string
            {
                return 'shadow.php';
            }

            public function getRootFilePath(): string
            {
                return '/tmp/shadow.php';
            }

            public function getAliases(): Aliases
            {
                return new Aliases();
            }
        };

        $location = new CodeLocation($source, new Throw_(new Variable('e')));
        $this->assertSame(-1, $location->getLineNumber());

        return $location;
    }

    /**
     * #1801: Psalm itself drops a positionless issue (IssueBuffer::isSuppressed()), so re-emitting it
     * on template line 1 as `(unmapped)` only leaks what plain PHP never reports.
     */
    #[Test]
    public function a_positionless_issue_is_declined(): void
    {
        $issue = new TypeDoesNotContainType('Type never does not contain never', $this->positionlessLocation(), 'never');

        $this->assertNull($this->relocate($issue, $this->entry([2 => 0])));
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

    /** #1908: Livewire's compiled `$__split` closure. */
    #[Test]
    public function a_missing_closure_purity_annotation_is_dropped_unconditionally(): void
    {
        $issue = new MissingPureAnnotation('{closure} must be marked @psalm-pure to aid security analysis, run with --alter --issues=MissingPureAnnotation to fix this', $this->shadowLocation(9));

        $this->assertFalse($this->relocate($issue, $this->entry([9 => 3])));
    }

    #[Test]
    public function a_missing_purity_annotation_on_a_named_function_keeps_reporting(): void
    {
        $issue = new MissingPureAnnotation('helper must be marked @psalm-pure to aid security analysis, run with --alter --issues=MissingPureAnnotation to fix this', $this->shadowLocation(9));

        $this->assertInstanceOf(MissingPureAnnotation::class, $this->relocate($issue, $this->entry([9 => 3])));
    }

    /** #1908: Livewire's `@entangle('tab')` compiles to `'tab'->value()` and `'tab'->hasModifier('live')`. */
    #[Test]
    #[DataProvider('compiledEntangleCalls')]
    public function a_compiled_method_call_on_a_string_literal_is_dropped(string $anchor, int $width): void
    {
        $shadow = "window.Livewire.find('<?php echo e(\$__livewire->getId()); ?>').entangle('<?php echo e('tab'->value()); ?>')<?php echo e('tab'->hasModifier('live') ? '.live' : ''); ?>";
        $issue = new InvalidMethodCall("Cannot call method on 'tab' variable ", self::selectionLocation($anchor, $width, $shadow));

        $this->assertFalse($this->relocate($issue, $this->entry([9 => 3]), templateSource: "<div x-data=\"{ open: @entangle('tab') }\"></div>\n"));
    }

    /** @return iterable<string, array{string, int}> */
    public static function compiledEntangleCalls(): iterable
    {
        yield 'value()' => ["'tab'->value", 5];
        yield 'hasModifier()' => ["'tab'->hasModifier", 11];
    }

    #[Test]
    public function an_authored_method_call_on_a_string_literal_keeps_reporting(): void
    {
        $issue = new InvalidMethodCall("Cannot call method on 'x' variable ", self::selectionLocation("'x'->value", 5, "<?php echo e('x'->value()); ?>"));

        $relocated = $this->relocate($issue, $this->entry([9 => 3]), templateSource: "<div>\n  {{ 'x'->value() }}\n</div>\n");

        $this->assertInstanceOf(InvalidMethodCall::class, $relocated);
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
     * Negative: `@error(1 => 'field')` (a keyed argument, unusual but valid PHP) compiles to
     * `$__errorArgs = [1 => 'field'];`, which has no key `0` — the compiled `$__bag->has($__errorArgs[0])`
     * read then reports THIS class with the offset-`0` wording, a genuine malformed-input bug (runtime
     * `Undefined array key 0`), not the generated `[1]` bookkeeping probe. The gate must be pinned to
     * the literal offset `'1'`, not any offset on `$__errorArgs` (#1566 review).
     */
    #[Test]
    public function an_offset_zero_error_args_message_survives(): void
    {
        $issue = new InvalidArrayOffset(
            "Cannot access value on variable \$__errorArgs using offset value of '0', expecting 1",
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
     * #1695: `compileAware()`'s loop body, as one shadow line. Argument and cast issues on `$__value`
     * in the list-form call, and `NoValue` on `$__key` in the keyed call, are compiler artifacts.
     * {@see BladeIssueRemapTest} pins the same gate end to end on real compiled shadows.
     */
    #[Test]
    #[DataProvider('compiledAwareIssues')]
    public function a_compiled_aware_argument_issue_is_dropped(CodeIssue $issue): void
    {
        $this->assertFalse($this->relocate($issue, $this->entry([9 => 3])));
    }

    /** @return iterable<string, array{CodeIssue}> */
    public static function compiledAwareIssues(): iterable
    {
        yield 'NoValue on the keyed call' => [new NoValue(self::AWARE_NO_VALUE, self::selectionLocation(self::AWARE_KEYED_CALL, 6))];
        yield 'NullArgument on the list-form call' => [new NullArgument(self::AWARE_NULL_ARGUMENT, self::selectionLocation(self::AWARE_LIST_CALL, 8), self::AWARE_CALLEE)];
        yield 'InvalidCast on the list-form call' => [new InvalidCast('array<never, never> cannot be cast to string', self::selectionLocation(self::AWARE_LIST_CALL, 8))];
        yield 'ImplicitToStringCast on the list-form call' => [new ImplicitToStringCast('Argument 1 of Illuminate\View\Factory::getConsumableComponentData expects string, Foo provided with a __toString method', self::selectionLocation(self::AWARE_LIST_CALL, 8))];
    }

    /**
     * Negatives: each case differs from a dropped one above in exactly ONE gate condition, so the
     * case goes red if that condition is removed.
     */
    #[Test]
    #[DataProvider('authorAwareLookAlikes')]
    public function an_aware_look_alike_keeps_reporting(CodeIssue $issue, string $templateSource = self::TEMPLATE_SOURCE): void
    {
        $relocated = $this->relocate($issue, $this->entry([9 => 3]), templateSource: $templateSource);

        $this->assertInstanceOf($issue::class, $relocated);
        // Line 3 rather than the unmapped fallback (line 1 plus a suffix), so this is a real mapping.
        $this->assertSame(3, $relocated->code_location->getLineNumber());
        $this->assertSame($issue->message, $relocated->message);
    }

    /** @return iterable<string, array{0: CodeIssue, 1?: string}> */
    public static function authorAwareLookAlikes(): iterable
    {
        $list = self::selectionLocation(self::AWARE_LIST_CALL, 8);

        yield 'issue class outside the gate' => [new UndefinedVariable('Cannot find referenced variable $__value', $list)];
        yield 'another callee' => [new NullArgument(self::AWARE_NULL_ARGUMENT, $list, 'foo')];
        yield 'selection wider than the argument' => [new NullArgument(self::AWARE_NULL_ARGUMENT, self::selectionLocation(self::AWARE_LIST_CALL, 9), self::AWARE_CALLEE)];
        // An author's own factory: same callee and argument, so only the `$__env->` call text differs.
        yield 'receiver other than $__env' => [new NullArgument(self::AWARE_NULL_ARGUMENT, self::selectionLocation('$factory->getConsumableComponentData($__value', 8, '$factory->getConsumableComponentData($__value);'), self::AWARE_CALLEE)];
        yield 'call text written in the template' => [
            new NullArgument(self::AWARE_NULL_ARGUMENT, $list, self::AWARE_CALLEE),
            "<div>\n@php \$__value = null; \$__env->getConsumableComponentData(\$__value); @endphp\n</div>\n",
        ];
    }

    /**
     * #1196: a raw echo (`{!! !!}`, a bare `<?php echo $expr; ?>`) of `Htmlable` or the `View` contract
     * reports `InvalidCast`, because neither interface declares `__toString()`; the framework's own
     * implementations all have one. {@see BladeTemplatesTest} pins the same gate on real compiled output.
     */
    #[Test]
    #[DataProvider('rawEchoOfAContract')]
    public function a_raw_echo_of_an_htmlable_contract_is_dropped(CodeIssue $issue, string $templateSource): void
    {
        $this->assertFalse($this->relocate($issue, $this->entry([9 => 3]), templateSource: $templateSource));
    }

    /** @return iterable<string, array{CodeIssue, string}> */
    public static function rawEchoOfAContract(): iterable
    {
        $location = self::selectionLocation('$links', 6, self::RAW_ECHO_LINE);
        $template = self::onLineThree('{!! $links !!}');

        yield 'Htmlable' => [new InvalidCast(self::HTMLABLE_CAST, $location), $template];
        yield 'View contract' => [new InvalidCast('Illuminate\Contracts\View\View cannot be cast to string', $location), $template];
        yield 'Htmlable in a union with a castable member' => [new PossiblyInvalidCast(self::HTMLABLE_CAST, $location), $template];
        yield 'class name in another case' => [new InvalidCast('illuminate\contracts\support\htmlable cannot be cast to string', $location), $template];
        yield 'an operand with a subscript' => [new InvalidCast(self::HTMLABLE_CAST, self::selectionLocation("\$links['']", 10, "<?php echo \$links['']; ?>")), self::onLineThree("{!! \$links[''] !!}")];
        yield 'a comment after the operand' => [
            new InvalidCast(self::HTMLABLE_CAST, self::selectionLocation('$links', 6, '<?php echo $links /* why */; ?>')),
            self::onLineThree('{!! $links /* why */ !!}'),
        ];
        yield 'a comment before the operand' => [
            new InvalidCast(self::HTMLABLE_CAST, self::selectionLocation('$links', 6, '<?php echo /* why */ $links; ?>')),
            self::onLineThree('{!! /* why */ $links !!}'),
        ];
        yield 'a whole expression as the operand' => [
            new PossiblyInvalidCast(self::HTMLABLE_CAST, self::selectionLocation("\$a ? \$links : 'x'", 17, "<?php echo \$a ? \$links : 'x'; ?>")),
            self::onLineThree("{!! \$a ? \$links : 'x' !!}"),
        ];
    }

    /**
     * Negatives: each case differs from the `Htmlable` row above in exactly ONE gate condition (the
     * cast type, the issue class, the echo's provenance, the operand's shape), so it goes red if
     * that condition is removed.
     */
    #[Test]
    #[DataProvider('castsOnTheAuthorsOrAConcreteClass')]
    public function a_cast_the_author_owns_or_a_concrete_class_keeps_reporting(CodeIssue $issue, string $templateSource): void
    {
        $relocated = $this->relocate($issue, $this->entry([9 => 3]), templateSource: $templateSource);

        $this->assertInstanceOf($issue::class, $relocated);
        $this->assertSame(3, $relocated->code_location->getLineNumber());
    }

    /** @return iterable<string, array{CodeIssue, string}> */
    public static function castsOnTheAuthorsOrAConcreteClass(): iterable
    {
        $location = self::selectionLocation('$links', 6, self::RAW_ECHO_LINE);
        $template = self::onLineThree('{!! $links !!}');

        // The cast type.
        yield 'concrete class without __toString' => [new InvalidCast('App\NoToStringHtmlable cannot be cast to string', $location), $template];
        yield 'userland interface extending Htmlable' => [new InvalidCast('App\Contracts\Html cannot be cast to string', $location), $template];
        yield 'intersection with the interface' => [new InvalidCast('Illuminate\Contracts\Support\Htmlable&App\Marker cannot be cast to string', $location), $template];
        yield 'intersection led by another type' => [new InvalidCast('App\Marker&Illuminate\Contracts\Support\Htmlable cannot be cast to string', $location), $template];
        yield 'text after the message' => [new InvalidCast(self::HTMLABLE_CAST . ' (and more)', $location), $template];
        yield 'a cast type that merely starts with the interface name' => [new InvalidCast('Illuminate\Contracts\Support\HtmlableX cannot be cast to string', $location), $template];
        // The issue class.
        yield 'another issue class with the same message' => [new ImplicitToStringCast(self::HTMLABLE_CAST, $location), $template];
        // The echo's provenance: the author wrote an `echo` of the same expression.
        yield 'echo inside @php' => [new InvalidCast(self::HTMLABLE_CAST, $location), self::onLineThree('@php echo $links; @endphp')];
        yield 'raw php tag' => [new InvalidCast(self::HTMLABLE_CAST, $location), self::onLineThree('<?php echo $links; ?>')];
        yield 'raw echo beside an echo of the same expression' => [new InvalidCast(self::HTMLABLE_CAST, $location), self::onLineThree("{!! \$links !!}\n@php echo \$links; @endphp")];
        // Blade strips the marker, so the author's echo and the generated one compile to the same text.
        yield 'author echo Blade rewrote' => [
            new InvalidCast(self::HTMLABLE_CAST, self::selectionLocation("\$links['']", 10, "<?php echo \$links['']; ?>")),
            self::onLineThree("@php echo \$links['##BEGIN-COMPONENT-CLASS##']; @endphp\n{!! \$links[''] !!}"),
        ];
        yield 'echo with a comment before the operand inside @php' => [
            new InvalidCast(self::HTMLABLE_CAST, self::selectionLocation('$links', 6, '<?php echo /* why */ $links; ?>')),
            self::onLineThree('@php echo /* why */ $links; @endphp'),
        ];
        yield 'echo with a comment after the operand inside @php' => [
            new InvalidCast(self::HTMLABLE_CAST, self::selectionLocation('$links', 6, '<?php echo $links /* why */; ?>')),
            self::onLineThree('@php echo $links /* why */; @endphp'),
        ];
        // The operand did not survive compilation, so the template says nothing about the echo.
        yield 'operand absent from the template' => [new InvalidCast(self::HTMLABLE_CAST, $location), self::onLineThree('{!! $other !!}')];
        // The operand's shape.
        yield 'the author wrote the (string) cast' => [
            new InvalidCast(self::HTMLABLE_CAST, self::selectionLocation('(string) $links', 15, '<?php echo (string) $links; ?>')),
            self::onLineThree('{!! (string) $links !!}'),
        ];
        yield 'not an echo operand' => [new InvalidCast(self::HTMLABLE_CAST, self::selectionLocation('$links', 6, '<?php print $links; ?>')), $template];
        yield 'operand of an e() call' => [new InvalidCast(self::HTMLABLE_CAST, self::selectionLocation('$links', 6, '<?php echo e($links); ?>')), $template];
        yield 'only the left side of a larger echo expression' => [
            new InvalidCast(self::HTMLABLE_CAST, self::selectionLocation('$links', 6, "<?php echo \$links . 'x'; ?>")),
            self::onLineThree("{!! \$links . 'x' !!}"),
        ];
    }

    /**
     * The last line of a file with no final newline: Psalm cuts the snippet at the selection's end,
     * so the `;` is only in the file. Same operand, same template; only the file's bytes differ.
     */
    #[Test]
    public function an_echo_on_the_last_line_of_a_shadow_is_judged_by_the_file_not_the_snippet(): void
    {
        $template = self::onLineThree('{!! $links !!}');

        $this->assertFalse($this->relocate(new InvalidCast(self::HTMLABLE_CAST, self::fileLocation('<?php echo $links; ?>', '$links')), $this->entry([9 => 3]), templateSource: $template));
        $this->assertFalse($this->relocate(new InvalidCast(self::HTMLABLE_CAST, self::fileLocation('<?php echo $links ; ?>', '$links')), $this->entry([9 => 3]), templateSource: $template));

        $kept = new InvalidCast(self::HTMLABLE_CAST, self::fileLocation("<?php echo \$links . 'x'; ?>", '$links'));
        $this->assertInstanceOf(InvalidCast::class, $this->relocate($kept, $this->entry([9 => 3]), templateSource: self::onLineThree("{!! \$links . 'x' !!}")));
    }

    #[Test]
    public function an_echo_whose_terminator_cannot_be_read_keeps_reporting(): void
    {
        $issue = new InvalidCast(self::HTMLABLE_CAST, self::fileLocation('<?php echo $links; ?>', '$links', writeFile: false));

        $this->assertInstanceOf(InvalidCast::class, $this->relocate($issue, $this->entry([9 => 3]), templateSource: self::onLineThree('{!! $links !!}')));
    }

    /**
     * The same provenance proof backs the #1535 argument gate, so an author echo Blade rewrote keeps
     * its finding there too; the control differs only in the template.
     */
    #[Test]
    #[DataProvider('echoArgumentTemplates')]
    public function an_echo_argument_issue_is_dropped_only_when_no_author_echo_compiles_to_the_same_text(string $templateSource, bool $dropped): void
    {
        $issue = new PossiblyInvalidArgument(
            'Argument 1 of echo expects string, but array<array-key, mixed>|string provided',
            self::selectionLocation("\$links['']", 10, "<?php echo \$links['']; ?>"),
            'echo',
        );

        $relocated = $this->relocate($issue, $this->entry([9 => 3]), templateSource: $templateSource);

        $dropped ? $this->assertFalse($relocated) : $this->assertInstanceOf(PossiblyInvalidArgument::class, $relocated);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function echoArgumentTemplates(): iterable
    {
        yield 'generated echo only' => [self::onLineThree("{!! \$links[''] !!}"), true];
        yield 'author echo Blade rewrote' => [self::onLineThree("@php echo \$links['##BEGIN-COMPONENT-CLASS##']; @endphp\n{!! \$links[''] !!}"), false];
    }

    /**
     * A location on a real one-line file whose snippet stops at the selection's end, as Psalm's does
     * for a last line without a newline.
     */
    private static function fileLocation(string $shadow, string $anchor, bool $writeFile = true): CodeLocation
    {
        $path = \sys_get_temp_dir() . '/shadow-last-line-' . \hash('xxh128', $shadow) . '.php';

        if ($writeFile) {
            \file_put_contents($path, $shadow);
        } elseif (\is_file($path)) {
            \unlink($path);
        }

        $end = (int) \strpos($shadow, $anchor) + \strlen($anchor);

        return new class (\substr($shadow, 0, $end), $path, [$end - \strlen($anchor), $end]) extends CodeLocation {
            /** @param array{int, int} $selection */
            public function __construct(
                private readonly string $snippetText,
                string $path,
                private readonly array $selection,
            ) {
                $this->file_path = $path;
                $this->file_name = 'shadow.php';
                $this->raw_line_number = 9;
            }

            #[\Override]
            public function getSnippet(): string
            {
                return $this->snippetText;
            }

            #[\Override]
            public function getSelectionBounds(): array
            {
                return $this->selection;
            }

            #[\Override]
            public function getSnippetBounds(): array
            {
                return [0, \strlen($this->snippetText)];
            }
        };
    }

    /** A template whose `$line` sits on line 3, where the `[9 => 3]` line map sends the shadow line. */
    private static function onLineThree(string $line): string
    {
        return "<div>\n<p>x</p>\n{$line}\n</div>\n";
    }

    /**
     * A shadow-line-9 location whose snippet is readable without a booted ProjectAnalyzer, selecting
     * the $width bytes that end where $anchor ends. Offset 1000 keeps file and snippet offsets apart.
     */
    private static function selectionLocation(string $anchor, int $width, string $snippet = self::AWARE_SHADOW_LINE): CodeLocation
    {
        $end = 1000 + (int) \strpos($snippet, $anchor) + \strlen($anchor);

        return new class ($snippet, [$end - $width, $end]) extends CodeLocation {
            /** @param array{int, int} $selection */
            public function __construct(
                private readonly string $snippetText,
                private readonly array $selection,
            ) {
                // Kept issues get their message remapped, which reads the file path and name.
                $this->file_path = '/tmp/shadow.php';
                $this->file_name = 'shadow.php';
                $this->raw_line_number = 9;
            }

            #[\Override]
            public function getSnippet(): string
            {
                return $this->snippetText;
            }

            #[\Override]
            public function getSelectionBounds(): array
            {
                return $this->selection;
            }

            #[\Override]
            public function getSnippetBounds(): array
            {
                return [1000, 1000 + \strlen($this->snippetText)];
            }
        };
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

    /** Declares `$footer` and `$füß` as required named slots; line 3 is the mapped one. */
    private const NAMED_SLOT_SOURCE = "<?php /** @var \\Illuminate\\View\\ComponentSlot \$footer */ ?>\n<?php /** @var \\Illuminate\\View\\ComponentSlot \$füß Optional. */ ?>\n<p>x</p>\n";

    /**
     * #1871: a guard on a named slot declared non-null in a component view keeps reporting, with a
     * hint naming the optional declaration.
     */
    #[Test]
    #[DataProvider('namedSlotGuards')]
    public function a_named_slot_guard_in_a_component_view_carries_the_optional_hint(CodeIssue $issue, string $name): void
    {
        $relocated = $this->relocate($issue, $this->entry([9 => 3]), isComponentView: true, templateSource: self::NAMED_SLOT_SOURCE);

        $this->assertInstanceOf($issue::class, $relocated);
        $this->assertSame(
            $issue->message . " (named slots are optional: declare `\\Illuminate\\View\\ComponentSlot|null \${$name} = null`)",
            $relocated->message,
        );
    }

    /** @return iterable<string, array{CodeIssue, string}> */
    public static function namedSlotGuards(): iterable
    {
        yield 'isset' => [new RedundantConditionGivenDocblockType('Docblock-defined type Illuminate\View\ComponentSlot for $footer is never null', self::staticShadowLocation(9), null), 'footer'];
        yield '??' => [new DocblockTypeContradiction('Cannot resolve types for $footer - docblock-defined type Illuminate\View\ComponentSlot does not contain null', self::staticShadowLocation(9), null), 'footer'];
        yield 'non-ASCII name' => [new RedundantConditionGivenDocblockType('Docblock-defined type Illuminate\View\ComponentSlot for $füß is never null', self::staticShadowLocation(9), null), 'füß'];
    }

    #[Test]
    #[DataProvider('unhintedGuards')]
    public function a_guard_outside_the_named_slot_shape_carries_no_hint(CodeIssue $issue, bool $isComponentView, string $templateSource = self::NAMED_SLOT_SOURCE): void
    {
        $relocated = $this->relocate($issue, $this->entry([9 => 3]), isComponentView: $isComponentView, templateSource: $templateSource);

        $this->assertInstanceOf($issue::class, $relocated);
        $this->assertSame($issue->message, $relocated->message);
    }

    /** @return iterable<string, array{0: CodeIssue, 1: bool, 2?: string}> */
    public static function unhintedGuards(): iterable
    {
        $guard = static fn(): RedundantConditionGivenDocblockType => new RedundantConditionGivenDocblockType('Docblock-defined type Illuminate\View\ComponentSlot for $footer is never null', self::staticShadowLocation(9), null);

        yield 'outside a component view' => [$guard(), false];
        yield 'another type' => [new RedundantConditionGivenDocblockType('Docblock-defined type string for $title is never null', self::staticShadowLocation(9), null), true];
        yield 'not a null guard' => [new RedundantConditionGivenDocblockType('Docblock-defined type Illuminate\View\ComponentSlot for $footer is always Illuminate\View\ComponentSlot', self::staticShadowLocation(9), null), true];
        // Narrowed by an outer `isset()`: Psalm renders the same wording, but the declaration is fine.
        yield 'declared optional' => [$guard(), true, "<?php /** @var \\Illuminate\\View\\ComponentSlot|null \$footer = null */ ?>\n\n<p>x</p>\n"];
        yield 'not declared in the template' => [$guard(), true, self::TEMPLATE_SOURCE];
    }

    private static function staticShadowLocation(int $line): Raw
    {
        return new Raw(\str_repeat("\n", $line - 1), self::SHADOW, 'shadow.php', $line - 1, $line - 1);
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

    /** A tag's compiled `isset($attributes) && $attributes instanceof ...` check, both places Blade emits it. */
    private const ATTRIBUTES_CHECK = 'isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag';

    private const ATTRIBUTES_CHECK_LINES = [
        'resolve() data merge' => '$component = Illuminate\View\AnonymousComponent::resolve([\'view\' => \'components.alert\',\'data\' => []] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : []));',
        'ignored-parameter strip' => 'if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag):',
    ];

    private const ATTRIBUTES_ALWAYS = 'Docblock-defined type Illuminate\View\ComponentAttributeBag for $attributes is always Illuminate\View\ComponentAttributeBag';

    /** #1873: outside a component view, typing `$attributes` from the tag makes the compiled `instanceof` redundant. */
    #[Test]
    #[DataProvider('compiledAttributesChecks')]
    public function a_compiled_attributes_check_is_dropped_outside_a_component_view(string $line): void
    {
        $issue = new RedundantConditionGivenDocblockType(self::ATTRIBUTES_ALWAYS, self::selectionLocation(self::ATTRIBUTES_CHECK, \strlen(self::ATTRIBUTES_CHECK), $line), null);

        $this->assertFalse($this->relocate($issue, $this->entry([9 => 3]), attributesFromTag: true));
    }

    /** @return iterable<string, array{string}> */
    public static function compiledAttributesChecks(): iterable
    {
        foreach (self::ATTRIBUTES_CHECK_LINES as $name => $line) {
            yield $name => [$line];
        }
    }

    /**
     * #1909: Psalm's virtual `offsetGet()`/`offsetExists()` call is positioned on the subject of
     * the access, so the selection is immediately followed by `[`.
     */
    #[Test]
    #[DataProvider('virtualOffsetCalls')]
    public function a_method_call_issue_on_the_subject_of_an_array_access_is_dropped(CodeIssue $issue): void
    {
        $this->assertFalse($this->relocate($issue, $this->entry([9 => 3])));
    }

    /** @return iterable<string, array{CodeIssue}> */
    public static function virtualOffsetCalls(): iterable
    {
        $line = "<?php echo e(\$x['k']['n']); ?>";

        // The subject of the outer access (`$x['k']`) and of the inner one (`$x`).
        yield 'PossiblyInvalidMethodCall' => [new PossiblyInvalidMethodCall('Cannot call method on possible array<string, mixed> variable $x', self::selectionLocation("\$x['k']", 7, $line))];
    }

    /** Negatives: each differs from the dropped case in ONE gate condition. */
    #[Test]
    #[DataProvider('callsNotBeforeABracket')]
    public function a_method_call_issue_not_on_the_subject_of_an_array_access_keeps_reporting(CodeIssue $issue): void
    {
        $this->assertInstanceOf(CodeIssue::class, $this->relocate($issue, $this->entry([9 => 3])));
    }

    /** @return iterable<string, array{CodeIssue}> */
    public static function callsNotBeforeABracket(): iterable
    {
        $real = "<?php echo e(\$obj->items()['k']); ?>";

        // A real call is positioned on its method name, followed by `(`.
        yield 'PossiblyInvalidMethodCall on a real call' => [new PossiblyInvalidMethodCall('Cannot call method on possible array<string, string> variable $obj', self::selectionLocation('$obj->items', 5, $real))];
        // Whitespace before the `[` is not skipped.
        yield 'whitespace before the bracket' => [new PossiblyInvalidMethodCall('Cannot call method on possible array<string, mixed> variable $x', self::selectionLocation('$x', 2, "<?php echo e(\$x ['k']); ?>"))];
    }

    /** Negatives: each differs from the dropped case in ONE gate condition. */
    #[Test]
    #[DataProvider('attributesCheckLookAlikes')]
    public function an_attributes_check_look_alike_keeps_reporting(int $width, string $templateSource = self::TEMPLATE_SOURCE, bool $attributesFromTag = true): void
    {
        $location = self::selectionLocation(self::ATTRIBUTES_CHECK, $width, self::ATTRIBUTES_CHECK_LINES['ignored-parameter strip']);
        $issue = new RedundantConditionGivenDocblockType(self::ATTRIBUTES_ALWAYS, $location, null);
        $relocated = $this->relocate($issue, $this->entry([9 => 3]), templateSource: $templateSource, attributesFromTag: $attributesFromTag);

        $this->assertInstanceOf(RedundantConditionGivenDocblockType::class, $relocated);
        $this->assertSame(3, $relocated->code_location->getLineNumber());
    }

    /** @return iterable<string, array{0: int, 1?: string, 2?: bool}> */
    public static function attributesCheckLookAlikes(): iterable
    {
        yield 'prelude did not type $attributes from a tag' => [\strlen(self::ATTRIBUTES_CHECK), self::TEMPLATE_SOURCE, false];
        yield 'selection is only the instanceof' => [\strlen('$attributes instanceof Illuminate\View\ComponentAttributeBag')];
        yield 'check written in the template' => [
            \strlen(self::ATTRIBUTES_CHECK),
            "<div>\n@if (isset(\$attributes) && \$attributes instanceof Illuminate\\View\\ComponentAttributeBag) x @endif\n</div>\n",
        ];
        // Blade strips its own `##BEGIN-COMPONENT-CLASS##` marker from the compiled output, so this compiles to the check.
        yield 'check split by a stripped marker' => [
            \strlen(self::ATTRIBUTES_CHECK),
            "@php \$attr##BEGIN-COMPONENT-CLASS##ibutes = new \\Illuminate\\View\\ComponentAttributeBag;\n"
                . "if (isset(\$attr##BEGIN-COMPONENT-CLASS##ibutes) && \$attr##BEGIN-COMPONENT-CLASS##ibutes instanceof Illuminate\\View\\ComponentAttributeBag) {} @endphp\n",
        ];
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
