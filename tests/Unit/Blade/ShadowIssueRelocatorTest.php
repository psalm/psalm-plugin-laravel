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
use Psalm\Issue\InvalidStringClass;
use Psalm\Issue\MissingClosureParamType;
use Psalm\Issue\MissingClosureReturnType;
use Psalm\Issue\MissingPureAnnotation;
use Psalm\Issue\MixedArgument;
use Psalm\Issue\MixedArrayOffset;
use Psalm\Issue\MixedAssignment;
use Psalm\Issue\MixedMethodCall;
use Psalm\Issue\NonStaticSelfCall;
use Psalm\Issue\NoValue;
use Psalm\Issue\NullArgument;
use Psalm\Issue\ParseError;
use Psalm\Issue\PossiblyFalseArgument;
use Psalm\Issue\PossiblyInvalidArgument;
use Psalm\Issue\PossiblyInvalidCast;
use Psalm\Issue\PossiblyInvalidMethodCall;
use Psalm\Issue\PossiblyUndefinedGlobalVariable;
use Psalm\Issue\PossiblyUndefinedVariable;
use Psalm\Issue\RedundantCondition;
use Psalm\Issue\RedundantConditionGivenDocblockType;
use Psalm\Issue\ReferenceReusedFromConfusingScope;
use Psalm\Issue\RiskyTruthyFalsyComparison;
use Psalm\Issue\TaintedHtml;
use Psalm\Issue\TooFewArguments;
use Psalm\Issue\TooManyArguments;
use Psalm\Issue\TypeDoesNotContainNull;
use Psalm\Issue\TypeDoesNotContainType;
use Psalm\Issue\UndefinedMagicMethod;
use Psalm\Issue\UndefinedMethod;
use Psalm\Issue\UndefinedThisPropertyAssignment;
use Psalm\Issue\UndefinedThisPropertyFetch;
use Psalm\Issue\UndefinedVariable;
use Psalm\Issue\UnevaluatedCode;
use Psalm\Issue\UnhandledMatchCondition;
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

    /** The loop body line `CompilesComponents::compileAware()` emits. */
    private const AWARE_SHADOW_LINE = '$$__consumeVariable = is_string($__key) ? $__env->getConsumableComponentData($__key, $__value) : $__env->getConsumableComponentData($__value);';

    private const AWARE_KEYED_CALL = '$__env->getConsumableComponentData($__key';

    private const AWARE_LIST_CALL = '$__env->getConsumableComponentData($__value';

    private const AWARE_CALLEE = 'Illuminate\View\Factory::getConsumableComponentData';

    private const AWARE_NULL_ARGUMENT = 'Argument 1 of Illuminate\View\Factory::getConsumableComponentData cannot be null, null value provided to parameter with type string';

    private const AWARE_NO_VALUE = 'All possible types for this argument were invalidated - This may be dead code';

    /** `CompilesComponents::newComponentHash()` output: xxh128, 32 lowercase hex characters. */
    private const COMPONENT_HASH = '5194778a3a7b899dcee5619d0610f5cf';

    private const RISKY_MIXED = 'Operand of type false|mixed contains type mixed, which can be falsy and truthy. This can cause possibly unexpected behavior. Use strict comparison instead.';

    private const RISKY_SNIPPET = "<?php if(\$endpoint['body_params'] ?? false): ?>";

    private const MAGIC_SNIPPET = "<?php echo e(\$book->getUrl('/c')); ?>";

    private const STRIP_SNIPPET = "<?php echo e(strip_tags(str_replace([\"\\n\"], ' ', \$status['content']))); ?>";

    private const OFFSET_SNIPPET = "<?php echo e(\$acct['avatar']); ?>";

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

    /**
     * @param array<string, true>      $undeclaredNames
     * @param array<string, true>|null $untypedNames
     */
    private function relocate(
        CodeIssue $issue,
        ShadowEntry $entry,
        bool $isComponentView = false,
        string $templateSource = self::TEMPLATE_SOURCE,
        bool $attributesFromTag = false,
        array $undeclaredNames = [],
        ?array $untypedNames = null,
    ): CodeIssue|false|null {
        $target = new ShadowTarget($entry, $templateSource, 'resources/views/profile.blade.php', $isComponentView, MarkerComment::prefixFor($templateSource), $attributesFromTag, $undeclaredNames, $untypedNames);

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
        // so a Mixed* issue there says nothing about the template. A callee's origin keeps it past
        // the provenance gate, so this pins the unmapped-line path specifically.
        $issue = new MixedAssignment('Unable to determine the type', $this->shadowLocation(2), self::stubOrigin());

        $this->assertFalse($this->relocate($issue, $this->entry([2 => 0]), untypedNames: []));
    }

    #[Test]
    public function a_mapped_mixed_issue_is_dropped_by_default(): void
    {
        $issue = new MixedAssignment('Unable to determine the type', $this->shadowLocation(9));

        $this->assertFalse($this->relocate($issue, $this->entry([9 => 3])));
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

    /** The function and closure scope wording, emitted by a different class with a two-argument constructor. */
    #[Test]
    public function a_first_seen_line_reference_on_a_possibly_undefined_variable_names_the_template_line(): void
    {
        $issue = new PossiblyUndefinedVariable(
            'Possibly undefined variable $x, first seen on line 31',
            $this->shadowLocation(40),
        );

        $relocated = $this->relocate($issue, $this->entry([31 => 2, 40 => 3]));

        $this->assertInstanceOf(PossiblyUndefinedVariable::class, $relocated);
        $this->assertSame('Possibly undefined variable $x, first seen on line 2', $relocated->message);
        $this->assertSame(3, $relocated->code_location->getLineNumber());
    }

    #[Test]
    public function a_parse_error_line_reference_names_the_template_line(): void
    {
        $issue = new ParseError('Syntax error, unexpected T_ENDIF on line 23', $this->shadowLocation(23));

        $relocated = $this->relocate($issue, $this->entry([23 => 3]));

        $this->assertInstanceOf(ParseError::class, $relocated);
        $this->assertSame('Syntax error, unexpected T_ENDIF on line 3', $relocated->message);
        $this->assertSame(3, $relocated->code_location->getLineNumber());
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

    #[Test]
    public function a_parse_error_without_the_line_suffix_is_left_alone(): void
    {
        $issue = new ParseError('Interfaces cannot have properties', $this->shadowLocation(40));

        $relocated = $this->relocate($issue, $this->entry([40 => 3]));

        $this->assertInstanceOf(ParseError::class, $relocated);
        $this->assertSame('Interfaces cannot have properties', $relocated->message);
    }

    /** End anchor: quoted parser text that looks like the suffix must not be rewritten, only the real tail. */
    #[Test]
    public function only_the_trailing_line_reference_of_a_parse_error_is_rewritten(): void
    {
        $issue = new ParseError('Syntax error, unexpected identifier "x on line 31" on line 23', $this->shadowLocation(23));

        $relocated = $this->relocate($issue, $this->entry([23 => 3, 31 => 2]));

        $this->assertInstanceOf(ParseError::class, $relocated);
        $this->assertSame('Syntax error, unexpected identifier "x on line 31" on line 3', $relocated->message);
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

    /** The journey convention: a descriptor into the prelude clamps to line 1. */
    #[Test]
    public function a_location_descriptor_into_the_prelude_clamps_to_line_one(): void
    {
        $issue = new ReferenceReusedFromConfusingScope('$x is possibly a reference defined at shadow.php:5:9.', $this->shadowLocation(40));

        $relocated = $this->relocate($issue, $this->entry([5 => 0, 40 => 2]));

        $this->assertInstanceOf(ReferenceReusedFromConfusingScope::class, $relocated);
        $this->assertSame('$x is possibly a reference defined at resources/views/profile.blade.php:1:9.', $relocated->message);
    }

    /** Only the issue's own shadow is rewritten; another file's descriptor is ordinary text. */
    #[Test]
    public function a_descriptor_naming_another_file_is_left_alone(): void
    {
        $issue = new ReferenceReusedFromConfusingScope('$x is possibly a reference defined at app/Other.php:31:64.', $this->shadowLocation(40));

        $relocated = $this->relocate($issue, $this->entry([31 => 3, 40 => 2]));

        $this->assertInstanceOf(ReferenceReusedFromConfusingScope::class, $relocated);
        $this->assertSame('$x is possibly a reference defined at app/Other.php:31:64.', $relocated->message);
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

    /** The #1545 trade-off holds for the positionless shape too: it can be the only diagnostic. */
    #[Test]
    public function a_positionless_undefined_this_property_assignment_is_still_reported_on_line_one(): void
    {
        $issue = new UndefinedThisPropertyAssignment('Instance property Foo::$bar is not defined', $this->positionlessLocation(), 'Foo::$bar');

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
    public function a_method_call_on_a_string_literal_at_an_unreadable_location_keeps_reporting(): void
    {
        $issue = new InvalidMethodCall("Cannot call method on 'tab' variable ", $this->shadowLocation(9));

        $this->assertInstanceOf(InvalidMethodCall::class, $this->relocate($issue, $this->entry([9 => 3])));
    }

    #[Test]
    public function a_method_call_on_a_non_literal_receiver_keeps_reporting(): void
    {
        $issue = new InvalidMethodCall('Cannot call method on int variable $count', $this->shadowLocation(9));

        $this->assertInstanceOf(InvalidMethodCall::class, $this->relocate($issue, $this->entry([9 => 3])));
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

    /**
     * Negative control: the exact-name boundary must not widen to any `$__`-prefixed name — only
     * `$__errorArgs` is gated (#1566 review).
     */
    #[Test]
    public function a_dunder_prefixed_but_differently_named_offset_survives(): void
    {
        $issue = new InvalidArrayOffset(
            "Cannot access value on variable \$__foo using offset value of '9', expecting 0",
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
        yield 'NoValue on an assignment' => [new NoValue('All possible types for this assignment were invalidated - This may be dead code', self::selectionLocation(self::AWARE_KEYED_CALL, 6))];
        yield 'another callee' => [new NullArgument(self::AWARE_NULL_ARGUMENT, $list, 'foo')];
        yield 'second argument' => [new NullArgument(\str_replace('Argument 1 ', 'Argument 2 ', self::AWARE_NULL_ARGUMENT), $list, self::AWARE_CALLEE)];
        yield 'selection wider than the argument' => [new NullArgument(self::AWARE_NULL_ARGUMENT, self::selectionLocation(self::AWARE_LIST_CALL, 9), self::AWARE_CALLEE)];
        // An author's own factory: same callee and argument, so only the `$__env->` call text differs.
        yield 'receiver other than $__env' => [new NullArgument(self::AWARE_NULL_ARGUMENT, self::selectionLocation('$factory->getConsumableComponentData($__value', 8, '$factory->getConsumableComponentData($__value);'), self::AWARE_CALLEE)];
        yield 'call text written in the template' => [
            new NullArgument(self::AWARE_NULL_ARGUMENT, $list, self::AWARE_CALLEE),
            "<div>\n@php \$__value = null; \$__env->getConsumableComponentData(\$__value); @endphp\n</div>\n",
        ];
    }

    /** Same "cannot be read" case as the arity gate: an unreadable snippet is no evidence, so no drop. */
    #[Test]
    public function an_aware_shaped_issue_is_kept_when_its_snippet_cannot_be_read(): void
    {
        $relocated = $this->relocate(new NoValue(self::AWARE_NO_VALUE, $this->shadowLocation(9)), $this->entry([9 => 3]));

        $this->assertInstanceOf(NoValue::class, $relocated);
        $this->assertSame(3, $relocated->code_location->getLineNumber());
    }

    /**
     * #1872: the restores of a `<x-...>` tag, `@error`, `@session`/`@context`, and the stack push of
     * the latter two, as Laravel compiles them. `MixedAssignment` sits on the assignment target.
     * {@see BladeIssueRemapTest} pins the same gate end to end on real compiled shadows.
     */
    #[Test]
    #[DataProvider('compiledSaveRestoreIssues')]
    public function a_mixed_issue_on_a_compiled_save_or_restore_is_dropped(CodeIssue $issue): void
    {
        $this->assertFalse($this->relocate($issue, $this->entry([9 => 3]), untypedNames: []));
    }

    /** @return iterable<string, array{CodeIssue}> */
    public static function compiledSaveRestoreIssues(): iterable
    {
        foreach (['attributes', 'component'] as $name) {
            yield "\${$name} restore" => [self::mixedAssignment(
                "<?php \${$name} = \$__{$name}Original" . self::COMPONENT_HASH . '; ?>',
                "<?php \${$name}",
                \strlen("\${$name}"),
                $name,
            )];
        }

        yield '$message restore' => [self::mixedAssignment('if (isset($__messageOriginal)) { $message = $__messageOriginal; }', '{ $message', 8, 'message')];

        foreach (['session', 'context'] as $kind) {
            yield "@{$kind} push" => [self::mixedAssignment(
                "if (isset(\$value)) { \$__{$kind}Previous[] = \$value; }",
                "{ \$__{$kind}Previous[]",
                \strlen("\$__{$kind}Previous[]"),
            )];
            yield "@{$kind} read" => [self::mixedAssignment("\$value = {$kind}()->get(\$__{$kind}Args[0]); ?>", '$value', 6, 'value')];
            yield "@{$kind} restore" => [self::mixedAssignment(
                "if (isset(\$__{$kind}Previous) && !empty(\$__{$kind}Previous)) { \$value = array_pop(\$__{$kind}Previous); }",
                '{ $value',
                6,
                'value',
            )];
        }
    }

    /**
     * Negatives: each case differs from a dropped one above in exactly ONE gate condition, so the
     * case goes red if that condition is removed.
     */
    #[Test]
    #[DataProvider('authorSaveRestoreLookAlikes')]
    public function a_save_or_restore_look_alike_keeps_reporting(CodeIssue $issue, string $templateSource = self::TEMPLATE_SOURCE): void
    {
        $relocated = $this->relocate($issue, $this->entry([9 => 3]), templateSource: $templateSource, untypedNames: []);

        $this->assertInstanceOf($issue::class, $relocated);
        $this->assertSame(3, $relocated->code_location->getLineNumber());
    }

    /** @return iterable<string, array{0: CodeIssue, 1?: string}> */
    public static function authorSaveRestoreLookAlikes(): iterable
    {
        $restore = '<?php $attributes = $__attributesOriginal' . self::COMPONENT_HASH . '; ?>';

        yield 'author value' => [self::mixedAssignment('<?php $attributes = $something; ?>', '<?php $attributes', 11, 'attributes')];
        yield 'hash shorter than xxh128' => [self::mixedAssignment('<?php $attributes = $__attributesOriginalabc123; ?>', '<?php $attributes', 11, 'attributes')];
        yield 'original of the other name' => [self::mixedAssignment('<?php $attributes = $__componentOriginal' . self::COMPONENT_HASH . '; ?>', '<?php $attributes', 11, 'attributes')];
        yield 'not the assignment target' => [new MixedArgument(
            'Argument 1 of e cannot be mixed',
            self::selectionLocation('= $value', 6, 'if (isset($value)) { $__sessionPrevious[] = $value; }'),
            'e',
        )];
        yield 'statement written in the template' => [
            self::mixedAssignment($restore, '<?php $attributes', 11, 'attributes'),
            "<div>\n@php \$attributes = \$__attributesOriginal" . self::COMPONENT_HASH . "; @endphp\n</div>\n",
        ];
        // String literals that only look like a Blade comment: stripping `{{-- ... --}}` from the
        // template would remove the author's statement between them.
        yield 'statement written between comment look-alikes' => [
            self::mixedAssignment('if (isset($__messageOriginal)) { $message = $__messageOriginal; }', '{ $message', 8, 'message'),
            "<div>\n@php \$open = '{{--'; if (isset(\$__messageOriginal)) { \$message = \$__messageOriginal; } \$close = '--}}'; @endphp\n</div>\n",
        ];
        yield 'author session read' => [self::mixedAssignment("<?php \$value = session()->get('status'); ?>", '$value', 6, 'value')];
        yield 'session read written in the template' => [
            self::mixedAssignment('$value = session()->get($__sessionArgs[0]); ?>', '$value', 6, 'value'),
            "<div>\n@php \$value = session()->get(\$__sessionArgs[0]); @endphp\n</div>\n",
        ];
        yield 'issue class outside the Mixed family' => [new UndefinedVariable('Cannot find referenced variable $attributes', self::selectionLocation('<?php $attributes', 11, $restore))];
    }

    /** `MixedAssignment` on the $width bytes of $snippet that end where $anchor ends. */
    private static function mixedAssignment(string $snippet, string $anchor, int $width, ?string $name = null): MixedAssignment
    {
        return new MixedAssignment(
            $name === null ? 'Unable to determine the type of this assignment' : "Unable to determine the type that \${$name} is being assigned to",
            self::selectionLocation($anchor, $width, $snippet),
        );
    }

    /**
     * A shadow-line-9 location whose snippet is readable without a booted ProjectAnalyzer, selecting
     * the $width bytes that end where $anchor ends. Offset 1000 keeps file and snippet offsets apart.
     */
    private static function selectionLocation(string $anchor, int $width, string $snippet = self::AWARE_SHADOW_LINE, string $filePath = self::SHADOW): CodeLocation
    {
        $end = 1000 + (int) \strpos($snippet, $anchor) + \strlen($anchor);

        return new class ($snippet, [$end - $width, $end], $filePath) extends CodeLocation {
            /** @param array{int, int} $selection */
            public function __construct(
                private readonly string $snippetText,
                private readonly array $selection,
                string $filePath,
            ) {
                // Kept issues get their message remapped, which reads the file path and name.
                $this->file_path = $filePath;
                $this->file_name = \basename($filePath);
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
        yield '?? outside a component view' => [new DocblockTypeContradiction('Cannot resolve types for $footer - docblock-defined type Illuminate\View\ComponentSlot does not contain null', self::staticShadowLocation(9), null), false];
        yield 'another type' => [new RedundantConditionGivenDocblockType('Docblock-defined type string for $title is never null', self::staticShadowLocation(9), null), true];
        yield 'not a null guard' => [new RedundantConditionGivenDocblockType('Docblock-defined type Illuminate\View\ComponentSlot for $footer is always Illuminate\View\ComponentSlot', self::staticShadowLocation(9), null), true];
        yield 'inferred wording' => [new RedundantCondition('Type Illuminate\View\ComponentSlot for $footer is never null', self::staticShadowLocation(9), null), true];
        yield 'docblock wording on another class' => [new RedundantCondition('Docblock-defined type Illuminate\View\ComponentSlot for $footer is never null', self::staticShadowLocation(9), null), true];
        // Narrowed by an outer `isset()`: Psalm renders the same wording, but the declaration is fine.
        yield 'declared optional' => [$guard(), true, "<?php /** @var \\Illuminate\\View\\ComponentSlot|null \$footer = null */ ?>\n\n<p>x</p>\n"];
        yield 'declared nullable' => [$guard(), true, "<?php /** @var ?\\Illuminate\\View\\ComponentSlot \$footer */ ?>\n\n<p>x</p>\n"];
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
        yield 'check split by a Blade comment' => [
            \strlen(self::ATTRIBUTES_CHECK),
            "<div>\n@if (isset(\$attr{{-- x --}}ibutes) && \$attributes instanceof Illuminate\\View\\ComponentAttributeBag) x @endif\n</div>\n",
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

    /**
     * #1909: array access on a possible `ArrayAccess` runs a virtual `offsetGet()` call that Psalm
     * analyzes with `PossiblyInvalidMethodCall`/`MixedMethodCall` suppressed. The re-emit lost that
     * suppression, whatever the variable's origin. A declared `$acct` keeps the `MixedMethodCall`
     * past the provenance gate.
     */
    #[Test]
    #[DataProvider('virtualOffsetCallIssues')]
    public function a_virtual_offset_call_issue_is_dropped(CodeIssue $issue): void
    {
        $this->assertFalse($this->relocate($issue, $this->entry([9 => 3]), untypedNames: []));
    }

    /** @return iterable<string, array{CodeIssue}> */
    public static function virtualOffsetCallIssues(): iterable
    {
        yield 'PossiblyInvalidMethodCall' => [new PossiblyInvalidMethodCall(
            'Cannot call method on possible array{id: mixed, ...<array-key, mixed>} variable $acct',
            self::selectionLocation('$acct', 5, self::OFFSET_SNIPPET),
        )];
        yield 'MixedMethodCall' => [new MixedMethodCall(
            'Cannot determine the type of $acct when calling method offsetGet',
            self::selectionLocation('$acct', 5, self::OFFSET_SNIPPET),
        )];
    }

    /** A real call is positioned on its method name, which `(` follows, never `[`. */
    #[Test]
    public function a_real_possibly_invalid_method_call_keeps_reporting(): void
    {
        $issue = new PossiblyInvalidMethodCall(
            'Cannot call method on possible array<array-key, mixed> variable $d',
            self::selectionLocation('format', 6, "<?php echo e(\$d->format('x')['y']); ?>"),
        );

        $this->assertInstanceOf(PossiblyInvalidMethodCall::class, $this->relocate($issue, $this->entry([9 => 3])));
    }

    /**
     * A static call's `MixedMethodCall` is positioned on the whole call (AtomicStaticCallAnalyzer.php),
     * so an offset read on its result puts `[` after it too; only the instance path's message is
     * the virtual call's.
     */
    #[Test]
    public function a_static_mixed_method_call_followed_by_an_offset_keeps_reporting(): void
    {
        $issue = new MixedMethodCall(
            'Cannot call method on an unknown class',
            self::selectionLocation('$cls::make()', 12, "<?php echo e(\$cls::make()['k']); ?>"),
        );

        $this->assertInstanceOf(MixedMethodCall::class, $this->relocate($issue, $this->entry([9 => 3]), untypedNames: []));
    }

    /**
     * #1909: the prelude types a variable no contract declares as `mixed`, and Psalm narrows or
     * coerces that `mixed` into findings outside the Mixed family. They are dropped when the expression they are about reads only such variables.
     */
    #[Test]
    #[DataProvider('undeclaredSubjectIssues')]
    public function a_finding_about_an_undeclared_variable_is_dropped(CodeIssue $issue, string $name): void
    {
        $this->assertFalse($this->relocate($issue, $this->entry([9 => 3]), undeclaredNames: [$name => true]));
    }

    /** @return iterable<string, array{CodeIssue, string}> */
    public static function undeclaredSubjectIssues(): iterable
    {
        yield 'RiskyTruthyFalsyComparison operand' => [self::riskyOnEndpoint(), 'endpoint'];
        yield 'RiskyTruthyFalsyComparison nullsafe operand' => [new RiskyTruthyFalsyComparison(
            'Operand of type mixed|null contains type mixed, which can be falsy and truthy. This can cause possibly unexpected behavior. Use strict comparison instead.',
            self::selectionLocation('$thing?->prop', 13, '<?php if($thing?->prop): ?>'),
            null,
        ), 'thing'];
        yield 'UndefinedMagicMethod receiver' => [self::magicOnBook(), 'book'];
        yield 'UndefinedMethod nullsafe receiver' => [new UndefinedMethod(
            'Method Base::geturl does not exist',
            self::selectionLocation('getUrl', 6, '<?php echo e($book?->getUrl()); ?>'),
            'Base::geturl',
        ), 'book'];
        yield 'PossiblyInvalidMethodCall receiver' => [new PossiblyInvalidMethodCall(
            'Cannot call method on possible array<array-key, mixed> variable $book',
            self::selectionLocation('getUrl', 6, self::MAGIC_SNIPPET),
        ), 'book'];
        yield 'PossiblyInvalidArgument nested operand' => [self::stripTagsArgument(), 'status'];
        yield 'PossiblyInvalidCast nested operand' => [new PossiblyInvalidCast(
            'array<array-key, string> cannot be cast to string',
            self::selectionLocation("str_replace([\"\\n\"], ' ', \$status['content'])", 44, self::STRIP_SNIPPET),
        ), 'status'];
        yield 'UnhandledMatchCondition subject' => [new UnhandledMatchCondition(
            "This match expression is not exhaustive - consider values 'a'|mixed",
            self::selectionLocation('$kind', 5, "<?php echo e(match(\$kind) { 'a' => 1 }); ?>"),
        ), 'kind'];
        yield 'InvalidStringClass new' => [new InvalidStringClass(
            'String cannot be used as a class',
            self::selectionLocation('new $cls()', 10, '<?php echo e(new $cls()); ?>'),
        ), 'cls'];
        yield 'InvalidStringClass on a non-ASCII name' => [self::newCafe(), 'café'];
        yield 'UndefinedMagicMethod on a non-ASCII receiver' => [new UndefinedMagicMethod(
            'Magic method Base::geturl does not exist',
            self::selectionLocation('getUrl', 6, '<?php echo e($café->getUrl()); ?>'),
            'Base::geturl',
        ), 'café'];
    }

    /** Declared, local, and look-alike subjects keep reporting. */
    #[Test]
    #[DataProvider('authoredSubjectIssues')]
    public function a_finding_not_only_about_undeclared_variables_keeps_reporting(CodeIssue $issue, array $undeclaredNames): void
    {
        $relocated = $this->relocate($issue, $this->entry([9 => 3]), undeclaredNames: $undeclaredNames);

        $this->assertInstanceOf($issue::class, $relocated);
        $this->assertSame(3, $relocated->code_location->getLineNumber());
    }

    /** @return iterable<string, array{CodeIssue, array<string, true>}> */
    public static function authoredSubjectIssues(): iterable
    {
        yield 'Risky on a declared variable' => [self::riskyOnEndpoint(), ['other' => true]];
        yield 'magic method on a declared receiver' => [self::magicOnBook(), ['other' => true]];
        yield 'argument on a declared variable' => [self::stripTagsArgument(), []];
        yield 'Risky without mixed in the operand' => [new RiskyTruthyFalsyComparison(
            "Operand of type ''|0 contains type ''|0, which can be falsy and truthy. This can cause possibly unexpected behavior. Use strict comparison instead.",
            self::selectionLocation("\$endpoint['body_params'] ?? false", 33, self::RISKY_SNIPPET),
            null,
        ), ['endpoint' => true]];
        yield 'match without mixed in the values' => [new UnhandledMatchCondition(
            "This match expression is not exhaustive - consider values 'b'",
            self::selectionLocation('$kind', 5, "<?php echo e(match(\$kind) { 'a' => 1 }); ?>"),
        ), ['kind' => true]];
        yield 'operand mixing a declared variable' => [new PossiblyInvalidArgument(
            'Argument 3 of str_replace expects string, but possibly different type array provided',
            self::selectionLocation('str_replace($decl, \' \', $status)', 32, '<?php echo e(str_replace($decl, \' \', $status)); ?>'),
            'str_replace',
        ), ['status' => true]];
        yield 'receiver chain' => [new UndefinedMagicMethod(
            'Magic method Base::geturl does not exist',
            self::selectionLocation('getUrl', 6, '<?php echo e($book->author->getUrl()); ?>'),
            'Base::geturl',
        ), ['book' => true, 'author' => true]];
        yield 'receiver from a call' => [new UndefinedMagicMethod(
            'Magic method Base::geturl does not exist',
            self::selectionLocation('getUrl', 6, '<?php echo e(find($book)->getUrl()); ?>'),
            'Base::geturl',
        ), ['book' => true]];
        yield 'class name whose ASCII prefix is undeclared' => [self::newCafe(), ['caf' => true]];
        // Arity is not about the argument's type: a missing argument is a real bug whatever it holds.
        yield 'TooFewArguments on an undeclared argument' => [new TooFewArguments(
            'Too few arguments for format - expecting 2 but saw 1',
            self::selectionLocation('format($when)', 13, '<?php echo e(format($when)); ?>'),
            'format',
        ), ['when' => true]];
    }

    /** Taint findings are about where a value flows, never its type, so an undeclared source is real signal. */
    #[Test]
    public function a_taint_finding_on_an_undeclared_variable_is_not_dropped(): void
    {
        $location = self::selectionLocation('$html', 5, '<?php echo $html; ?>');
        $issue = new TaintedHtml('Detected tainted HTML', $location, [
            ['location' => $location, 'label' => 'call to echo', 'entry_path_type' => ''],
        ], 'call to echo');

        $this->assertNotFalse($this->relocate($issue, $this->entry([9 => 3]), undeclaredNames: ['html' => true]));
    }

    private static function newCafe(): InvalidStringClass
    {
        return new InvalidStringClass('String cannot be used as a class', self::selectionLocation('new $café()', \strlen('new $café()'), '<?php echo e(new $café()); ?>'));
    }

    /**
     * #1986: a `Mixed*` finding is dropped when its `mixed` comes from the prelude's fallback: Psalm's
     * origin (traced only while the variable-use graph runs) names an undeclared variable, a template
     * local, or a compiled `$__*` variable, or, without an origin, the expression reads one of them or
     * no variable at all.
     *
     * @param array<string, true>|null $untypedNames
     */
    #[Test]
    #[DataProvider('fallbackMixedIssues')]
    public function a_mixed_finding_derived_from_the_fallback_is_dropped(CodeIssue $issue, ?array $untypedNames): void
    {
        $this->assertFalse($this->relocate($issue, $this->entry([9 => 3]), untypedNames: $untypedNames));
    }

    /** @return iterable<string, array{CodeIssue, array<string, true>|null}> */
    public static function fallbackMixedIssues(): iterable
    {
        $call = '<?php echo e($x->foo()); ?>';
        $origin = self::selectionLocation('$x', 2, $call);

        yield 'origin on an undeclared variable' => [self::mixedCall('$x', 'foo', $call, $origin), ['x' => true]];
        yield 'origin on a template local' => [
            self::mixedCall('$local', 'bar', '<?php echo e($local->bar()); ?>', self::selectionLocation('$local', 6, '<?php $local = $x[\'k\']; ?>')),
            ['local' => true],
        ];
        yield 'origin on the compiled loop variable' => [
            new MixedArgument('Argument 1 of e cannot be mixed', self::selectionLocation('$w', 2, '<?php echo e($w); ?>'), 'e', self::selectionLocation('$__currentLoopData', 18, '<?php $__currentLoopData = $g->list; ?>')),
            [],
        ];
        yield 'no origin, receiver chain on an undeclared variable' => [self::mixedCall('$x->a', 'b', '<?php echo e($x->a->b()); ?>'), ['x' => true]];
        yield 'no origin, argument reading an undeclared variable' => [
            new MixedArgument('Argument 1 of e cannot be mixed', self::selectionLocation('$x->foo()', 9, $call), 'e'),
            ['x' => true],
        ];
        yield 'no origin, declared array read at an undeclared offset' => [
            new MixedArrayOffset('Cannot access value on variable $data using mixed offset', self::selectionLocation('$data[$x]', 9, '<?php echo e($data[$x]); ?>')),
            ['x' => true],
        ];
        yield 'no origin, loop alias' => [self::mixedAssignment('<?php foreach($__currentLoopData as $item): ?>', '$item', 5, 'item'), ['item' => true]];
        yield 'compiled props assignment, origin in a callee' => [
            new MixedAssignment('Unable to determine the type of this assignment', self::selectionLocation('$$__key', 7, '<?php $$__key = $$__key ?? $__value; ?>'), self::stubOrigin()),
            [],
        ];
        yield 'no origin, no variable read' => [
            new MixedArgument('Argument 1 of e cannot be mixed', self::selectionLocation("session('k')", 12, "<?php echo e(session('k')); ?>"), 'e'),
            [],
        ];
        yield 'body unreadable' => [self::mixedCall('$data', 'foo', '<?php echo e($data->foo()); ?>', self::stubOrigin()), null];
    }

    /**
     * #1986 negatives: a `Mixed*` finding from a declared variable or a callee's `mixed` return follows
     * the user's `errorLevel` and `issueHandlers`, as in a PHP file.
     *
     * @param array<string, true> $untypedNames
     */
    #[Test]
    #[DataProvider('declaredMixedIssues')]
    public function a_mixed_finding_from_a_declared_or_computed_value_keeps_reporting(CodeIssue $issue, array $untypedNames): void
    {
        $relocated = $this->relocate($issue, $this->entry([9 => 3]), untypedNames: $untypedNames);

        $this->assertInstanceOf($issue::class, $relocated);
        $this->assertSame(3, $relocated->code_location->getLineNumber());
    }

    /** @return iterable<string, array{CodeIssue, array<string, true>}> */
    public static function declaredMixedIssues(): iterable
    {
        $call = "<?php echo e(\$data['x']->foo()); ?>";

        yield 'origin on a declared variable' => [self::mixedCall("\$data['x']", 'foo', $call, self::selectionLocation('$data', 5, $call)), ['x' => true]];
        yield 'origin on a name an undeclared one prefixes' => [self::mixedCall('$xy', 'foo', '<?php echo e($xy->foo()); ?>', self::selectionLocation('$xy', 3, '<?php echo e($xy->foo()); ?>')), ['x' => true]];
        yield 'origin in a callee outside the shadow' => [
            self::mixedCall('$decoded', 'baz', '<?php echo e($decoded->baz()); ?>', self::stubOrigin()),
            ['decoded' => true],
        ];
        yield 'origin in a callee, a name with an inner double underscore' => [
            new MixedArgument('Argument 1 of e cannot be mixed', self::selectionLocation('$a__b', 5, '<?php echo e($a__b); ?>'), 'e', self::stubOrigin()),
            [],
        ];
        yield 'no origin, declared receiver' => [self::mixedCall("\$data['x']", 'foo', $call), ['x' => true]];
        yield 'no origin, argument reading a declared variable' => [
            new MixedArgument('Argument 1 of e cannot be mixed', self::selectionLocation("\$data['x']->foo()", 17, $call), 'e'),
            ['x' => true],
        ];
    }

    /** #1986: a kept finding's origin names the template line, not the shadow it was found in. */
    #[Test]
    public function a_kept_mixed_finding_names_the_template_as_its_origin(): void
    {
        $call = "<?php echo e(\$data['x']->foo()); ?>";
        $relocated = $this->relocate(self::mixedCall("\$data['x']", 'foo', $call, self::selectionLocation('$data', 5, $call)), $this->entry([9 => 3]), untypedNames: []);

        $this->assertInstanceOf(MixedMethodCall::class, $relocated);
        $this->assertSame(self::TEMPLATE, $relocated->getOriginalLocation()?->file_path);
        $this->assertSame(3, $relocated->getOriginalLocation()->getLineNumber());

        $stub = self::stubOrigin();
        $relocated = $this->relocate(self::mixedCall('$decoded', 'baz', '<?php echo e($decoded->baz()); ?>', $stub), $this->entry([9 => 3]), untypedNames: []);

        $this->assertInstanceOf(MixedMethodCall::class, $relocated);
        $this->assertSame($stub, $relocated->getOriginalLocation());
    }

    /** A callee's `@return mixed`, the origin Psalm gives a `mixed` that a stub or vendor method returns. */
    private static function stubOrigin(): CodeLocation
    {
        return self::selectionLocation('mixed', 5, ' * @return mixed', '/vendor/stub.phpstub');
    }

    private static function mixedCall(string $receiver, string $method, string $snippet, ?CodeLocation $origin = null): MixedMethodCall
    {
        return new MixedMethodCall(
            "Cannot determine the type of {$receiver} when calling method {$method}",
            self::selectionLocation("{$receiver}->{$method}", \strlen($method), $snippet),
            $origin,
        );
    }

    private static function riskyOnEndpoint(): RiskyTruthyFalsyComparison
    {
        return new RiskyTruthyFalsyComparison(self::RISKY_MIXED, self::selectionLocation("\$endpoint['body_params'] ?? false", 33, self::RISKY_SNIPPET), null);
    }

    private static function magicOnBook(): UndefinedMagicMethod
    {
        return new UndefinedMagicMethod('Magic method Base::geturl does not exist', self::selectionLocation('getUrl', 6, self::MAGIC_SNIPPET), 'Base::geturl');
    }

    private static function stripTagsArgument(): PossiblyInvalidArgument
    {
        return new PossiblyInvalidArgument(
            'Argument 1 of strip_tags expects string, but possibly different type array<array-key, string>|string provided',
            self::selectionLocation("str_replace([\"\\n\"], ' ', \$status['content'])", 44, self::STRIP_SNIPPET),
            'strip_tags',
        );
    }
}
