<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

use Psalm\CodeLocation;
use Psalm\CodeLocation\Raw;
use Psalm\Issue\ArgumentIssue;
use Psalm\Issue\CodeIssue;
use Psalm\Issue\DocblockTypeContradiction;
use Psalm\Issue\MissingClosureParamType;
use Psalm\Issue\MissingClosureReturnType;
use Psalm\Issue\MixedIssue;
use Psalm\Issue\PossiblyFalseArgument;
use Psalm\Issue\PossiblyInvalidArgument;
use Psalm\Issue\RedundantCondition;
use Psalm\Issue\RedundantConditionGivenDocblockType;
use Psalm\Issue\TooManyArguments;
use Psalm\Issue\TypeDoesNotContainNull;
use Psalm\Issue\TypeDoesNotContainType;
use Psalm\Issue\UnevaluatedCode;
use Psalm\Issue\UnusedVariable;

/**
 * Rebuilds an issue Psalm found in a shadow file as the same issue positioned on the Blade template
 * that produced it. Split out of {@see BladeIssueRemapHandler} so the decision can be driven without
 * a live Psalm run.
 *
 * The rebuild is reflective on purpose. `CodeIssue::__construct()` takes `(message, code_location)`,
 * but 95 of Psalm 6's 313 concrete issue classes declare a third REQUIRED parameter, and a
 * two-argument `new $class(...)` throws for every one of them — which, inside an event handler, is
 * an issue that disappears with no user-visible trace. Every constructor parameter name matches a
 * readable property on the issue object, so walking the constructor reconstructs any of them;
 * anything that cannot be resolved declines instead, leaving Psalm to report on the shadow path.
 * Noisy, but never silent.
 *
 * `newInstanceWithoutConstructor()` plus property writes is not an option: `CodeIssue::$message` and
 * `$code_location` are readonly and cannot be initialised from outside the declaring scope.
 *
 * A taint issue carries two further arguments describing how the taint travelled; those are
 * remapped by {@see JourneyRemapper} and overridden here, so a relocated taint issue's trace names
 * the template throughout.
 *
 * @internal
 */
final class ShadowIssueRelocator
{
    private const UNMAPPED_SUFFIX = ' (unmapped)';

    /**
     * @param ShadowTarget                    $target      the shadow the issue was found in
     * @param \Closure(string): ?ShadowTarget $resolve     any OTHER shadow a taint journey passes
     *                                                     through; a journey crosses files, so one
     *                                                     target is not enough
     * @param bool                            $reportMixed `<blade reportMixedIssues="true" />`; false
     *                                                      drops the whole `MixedIssue` family instead
     *                                                      of relocating it (#1495)
     *
     * @return CodeIssue|false|null the issue to re-emit on the template, `false` to drop it, `null`
     *                              to decline and leave Psalm's own handling of the original alone
     */
    public static function relocate(CodeIssue $issue, ShadowTarget $target, \Closure $resolve, bool $reportMixed): CodeIssue|false|null
    {
        // A compiled directive (Livewire tags in particular) injects untyped closures the template
        // author cannot reach, and they dominate this family inside a shadow. Dropped
        // unconditionally rather than snippet-gated like the arity check below, and the trade-off
        // is real rather than free: a closure written inside `@php` or a raw PHP block CAN carry
        // native types and a docblock, so an author's own missing closure type is silenced too.
        // Accepted because it is a signature warning on code that is never called from outside the
        // template, against a family that is otherwise pure precompiler noise (#1498).
        if ($issue instanceof MissingClosureParamType || $issue instanceof MissingClosureReturnType) {
            return false;
        }

        // Compiled bookkeeping the template author never wrote and cannot read back: the
        // `$__componentOriginal*`/`$__attributesOriginal*` tail restores around a `<x-...>` tag and
        // the `$loop = $__env->getLastLoop();` reassignment at the end of `@foreach`/`@forelse` are
        // both standalone writes with no later read (#1500). Dropped unconditionally: there is no
        // narrower signal to gate on, and `UnusedForeachValue` is NOT included here — it fires only
        // on the author-named foreach variable, which is real signal.
        //
        // Trade-off: an author's own dead store inside `@php` and an unused foreach KEY report as
        // `UnusedVariable` too and are silenced along with the compiler noise.
        if ($issue instanceof UnusedVariable) {
            return false;
        }

        // `UnevaluatedCode` is not one shape: a `@switch` arm's `@break` leaves Psalm treating the
        // rest of the compiled switch body as unreachable, flagging the following `@case`/`@default`
        // line with this exact message (StatementsAnalyzer::processStmt(), gated on
        // find_unused_variables) — that shape is compiler noise and dropped. The OTHER shape sharing
        // this class, `'gettype cannot return this value'` (AssertionFinder, ungated), is a genuine
        // author typo in a `gettype()` comparison and must keep reporting, so the drop is gated on
        // the message rather than the class. The message names the Psalm code path that emitted it,
        // not who wrote the unreachable code, so an author's own dead statement after a
        // `return`/`throw`/`continue` in `@php` or raw PHP gets the same message and is silenced
        // too; accepted as a documented limitation, since this method has only the message and
        // location to go on, never the AST.
        if ($issue instanceof UnevaluatedCode && $issue->message === 'Expressions after return/throw/continue') {
            return false;
        }

        // Laravel's own compiled guards on `$attributes`/`$component`/`$slot` (`isset()`, `??=`,
        // `instanceof`) exist to check what {@see PreludeBuilder::componentTypesFor()} now
        // declares as guaranteed inside a component view — most visibly when that view itself
        // renders a NESTED `<x-...>` tag, whose own generated bookkeeping reuses the same three
        // names Psalm has already narrowed. `Reconciler::triggerIssueForImpossible()` picks its
        // issue class by whether the eliminated type came from a docblock or was inferred
        // (`$from_docblock`): `@props` REPLACES the prelude's docblock type with an inferred one
        // (`compileProps()`'s `$attributes = new ComponentAttributeBag($__newAttributes)`), so the
        // nested tag's guard lands in the inferred branch, not the docblock one — both branches'
        // classes (`RedundantCondition`/`RedundantConditionGivenDocblockType`/
        // `DocblockTypeContradiction` and `TypeDoesNotContainNull`/`TypeDoesNotContainType`) are
        // gated, or a `@props` view with a nested tag keeps reporting the inferred-branch half.
        //
        // Gated on the MESSAGE, not the class: `RedundantCondition` on an author's OWN
        // `@if(isset($range))` under their own docblock must keep reporting, and a message-only
        // gate cannot tell that apart from this shape by class alone.
        //
        // `$component`, unlike `$attributes`/`$slot`, is never given a type by
        // `componentTypesFor()` in ANY template — the prelude only ever falls it through to the
        // generic `mixed` bucket for undeclared names — so `isComponentView` carries no signal for
        // it. Its narrowed type comes entirely from the compiled `<Component>::resolve()` call one
        // `<x-...>` tag runs before the NEXT tag's own opening save guard re-checks
        // `isset($component)` against that still-live narrowing; that shape fires for a nested `<x-...>` tag inside a
        // PLAIN page just as much as inside a component view (#1532), so this name drops
        // unconditionally. `$attributes`/`$slot` keep the `isComponentView` requirement: outside a
        // component view neither name is ever declared by the prelude, so a local variable an
        // author happens to name `$attributes`/`$slot` there is untouched (#1525).
        //
        // Trade-off this gate does NOT avoid: it cannot tell compiler-generated bookkeeping apart
        // from an author's OWN `@if (isset($attributes))`/`instanceof`/`@php $component = ...` check
        // written inside their own template — that guard is silenced too, with no trace, because it
        // names one of the same three ambient variables. `PossiblyNullReference` on a subsequent
        // `$attributes->` read is a separate, NOT-gated consequence of the inferred-branch
        // reconciliation above (Psalm keeps the reconciled null in the never-taken arm and re-unions
        // it at the guard's `endif`) and stays visible; see docs/blade.md known limitations.
        //
        // These five classes render the checked name in one of TWO positions, never anywhere else
        // (`Reconciler::triggerIssueForImpossible()` and `AssertionReconciler`/
        // `SimpleNegatedAssertionReconciler` in vendor/vimeo/psalm), so `isAmbientGuardName()`
        // matches only those two anchored shapes rather than searching the whole message for the
        // name: `(Type|Docblock-defined type) <TYPE> for $key is (never|always) <ASSERTION>` (`$key`
        // immediately BEFORE `" is "`) and `Cannot resolve types for $key - <TYPE> does not contain
        // ...` / `... with <TYPE> and !isset assertion` (`$key` immediately AFTER `"for "`, anchored
        // to the message START so a `<TYPE>` string cannot masquerade as the leading `$key`). `<TYPE>`
        // is Psalm's rendered type and, for a `TLiteralString`, can itself contain the literal text
        // `" for $component "` — a bare substring search on the first shape would then drop an
        // unrelated key's guard whose rendered TYPE happens to quote one of the three names (#1532
        // review). Residual gap accepted, not fixed: a literal type string that contains the WHOLE
        // anchored phrase (name + `" is never/always "`, or the `^Cannot resolve types for $name"`
        // prefix) still collides; no message-only gate can rule that out.
        if (
            $issue instanceof RedundantCondition
            || $issue instanceof RedundantConditionGivenDocblockType
            || $issue instanceof DocblockTypeContradiction
            || $issue instanceof TypeDoesNotContainNull
            || $issue instanceof TypeDoesNotContainType
        ) {
            if (self::isAmbientGuardName($issue->message, 'component')) {
                return false;
            }

            if ($target->isComponentView && self::isAmbientGuardName($issue->message, 'attributes|slot')) {
                return false;
            }
        }

        if ($issue instanceof TooManyArguments && self::isGeneratedArityMismatch($issue, $target)) {
            return false;
        }

        // `{{ $x }}` compiles to `echo e($x)` and `{!! $x !!}` to a bare `echo $x`, so a value whose
        // type is only PARTLY echoable (`string|array|null` from `old()`, `string|false` from
        // `parse_url()`) reports against a callee the author never wrote. Their only fix is a cast
        // on every optional field in the template, and the array arm they would be casting away
        // fails loudly at runtime the first time it is hit, so the finding is unactionable in echo
        // position (#1535).
        //
        // `InvalidArgument` is deliberately NOT included: a definite `array` there is a guaranteed
        // `htmlspecialchars()` fatal, not a possibility.
        if (
            ($issue instanceof PossiblyInvalidArgument || $issue instanceof PossiblyFalseArgument)
            && self::isGeneratedEchoArgument($issue, $target)
        ) {
            return false;
        }

        // A template variable the prelude cannot resolve is typed `mixed`, so `MixedIssue` findings
        // inside a shadow are overwhelmingly this artifact rather than a real template bug; suppressed
        // by default, both on a mapped template line and on the prelude's own unmapped lines below.
        if ($issue instanceof MixedIssue && !$reportMixed) {
            return false;
        }

        $templateLine = $target->templateLineFor($issue->code_location->getLineNumber());
        $message = $issue->message;

        if ($templateLine < 1) {
            // The prelude and any line the marker pass could not map have no template position. A
            // `MixedIssue` there is an artifact of the prelude typing every unresolved template
            // variable as `mixed`, so it is dropped rather than parked on line 1 regardless of
            // $reportMixed: opting back in is about seeing the family on real template lines, not
            // about the prelude's own noise. Anything else is worth showing even without an exact
            // line.
            if ($issue instanceof MixedIssue) {
                return false;
            }

            $templateLine = 1;
            $message .= self::UNMAPPED_SUFFIX;
        }

        $location = $target->locationFor($templateLine);

        if (!$location instanceof Raw) {
            return null;
        }

        $overrides = ['code_location' => $location, 'message' => $message];
        $taint = PsalmBridge::taintArguments($issue);

        if ($taint !== null) {
            $journey = JourneyRemapper::remap($taint['journey'], $taint['journey_text'], $issue->code_location, $resolve);

            if ($journey === null) {
                return null;
            }

            $overrides += $journey;
        }

        return self::rebuild($issue, $overrides);
    }

    /**
     * Rebuilds a taint issue with a remapped journey only, for a sink that already sits on
     * ordinary application code (#1519). None of the shadow-only filters above apply: those drop
     * compiler output the template author never wrote, and here the sink is real app code Psalm
     * found on its own.
     *
     * @param array{journey: list<array{location: ?CodeLocation, label: string, entry_path_type: string}>, journey_text: string} $journey
     */
    public static function relocateJourney(CodeIssue $issue, array $journey): ?CodeIssue
    {
        return self::rebuild($issue, $journey);
    }

    /**
     * `TooManyArguments` gated on the ONE class it applies to, never the whole family: a
     * compiled `@include`/`@extends` chain also expands into calls (`$__env->make()`) that an
     * arity check would then silently drop as "generated" even when they carry a real bug such
     * as `MissingView`.
     *
     * Generated and author-written calls are told apart by the call EXPRESSION, cut out of the
     * shadow line at the issue's own selection offset, because neither end of the shadow line is
     * usable on its own:
     *
     * - The whole line is compiler output. `{{ $x->f(1,2) }}` becomes `<?php echo e($x->f(1,2)); ?>`,
     *   which no template contains, so matching the line would call every compiled syntax but a raw
     *   `<?php ?>` block "generated" and drop real author issues.
     * - The callee name alone (`getSelectedText()`, e.g. `mount`) is shared by every call to that
     *   method, generated or not.
     *
     * Matching is against the WHOLE template source, not the mapped template line: generated code
     * DOES inherit the injecting directive's line (the precompiler rewrites text that sits behind
     * that line's marker), so a per-line match would compare the generated call against the very
     * line whose directive produced it and learn nothing; and a multi-line construct gets no marker
     * of its own ({@see MarkerPrePass::computeSkipLines()}), so an author's multi-line call maps to
     * the line that OPENED it rather than the line the callee sits on.
     */
    private static function isGeneratedArityMismatch(TooManyArguments $issue, ShadowTarget $target): bool
    {
        $call = self::callExpression($issue->code_location);

        if ($call === null) {
            return false;
        }

        return !TemplateSnippetMatcher::occursIn($call, $target->templateSource);
    }

    /**
     * Whether the argument an issue points at is enclosed by an echo construct the Blade compiler
     * synthesized, rather than one the author wrote.
     *
     * The shadow line alone can never answer that: `@php echo e(old('k')); @endphp` and
     * `{{ old('k') }}` compile to BYTE-IDENTICAL lines. The discriminator is the same one
     * {@see self::isGeneratedArityMismatch()} uses — does this piece of the shadow occur in the raw
     * template? — only cut BACKWARDS, because an argument-position issue locates the argument and
     * the callee sits before it.
     *
     * The callee comes from `ArgumentIssue::$function_id` rather than the message, so a namespaced
     * `Fx\e()` is excluded without parsing rendered text and the gate does not ride on Psalm's
     * message wording. The argument POSITION has no property to read and is taken from the message
     * prefix: `e($value, $doubleEncode)` has a second parameter an author can pass, and
     * `@php echo $a, $b; @endphp` produces `Argument 2 of echo`.
     *
     * A missing call is only evidence about the CALLEE when the ARGUMENT survived compilation
     * verbatim, so that is checked first. Blade rewrites raw text inside an author's own
     * expression: `compileStatement()` unescapes `@@foo` to `@foo` before echos are compiled, and
     * `compileString()` strips the `##BEGIN-COMPONENT-CLASS##` markers from the finished output
     * after `@php` blocks have been restored into it — so `{{ e(old('@@foo')) }}` reaches the
     * analyzer as `echo e(e(old('@foo')))`, and the author's own inner call is absent from their
     * template. Without this check that call is read as the compiler's and a real issue vanishes.
     *
     * Gating on the argument rather than mirroring those rewrites onto the template covers the
     * rewrites that cannot be mirrored at all — a registered precompiler (Livewire) or a
     * `prepareStringsForCompilationUsing()` callback may rewrite anything — and it cannot widen
     * what the gate drops. Caveat it accepts: when Blade rewrote the argument of a genuinely
     * GENERATED echo (`{{ old('@@foo') }}`), the callee can no longer be proven and the issue is
     * kept. Noise on a rare shape, the same direction every other decline here takes.
     */
    private static function isGeneratedEchoArgument(ArgumentIssue $issue, ShadowTarget $target): bool
    {
        $callee = $issue->function_id;

        if ($callee !== 'e' && $callee !== 'echo') {
            return false;
        }

        if (!\str_starts_with($issue->message, 'Argument 1 ')) {
            return false;
        }

        $slice = self::echoArgumentSlice($issue->code_location, $callee);

        if ($slice === null) {
            return false;
        }

        [$call, $argument] = $slice;

        if (!TemplateSnippetMatcher::occursIn($argument, $target->templateSource)) {
            return false;
        }

        return !TemplateSnippetMatcher::occursIn($call, $target->templateSource);
    }

    /**
     * The enclosing `$callee(` plus the argument an issue points at, and that argument on its own,
     * read out of the shadow; null to decline. Same fail-open contract as
     * {@see self::callExpression()}.
     *
     * @return array{string, string}|null `[enclosing call, argument]`
     */
    private static function echoArgumentSlice(CodeLocation $location, string $callee): ?array
    {
        try {
            $snippet = $location->getSnippet();
            [$selectionStart, $selectionEnd] = $location->getSelectionBounds();
            [$snippetStart] = $location->getSnippetBounds();
        } catch (\Throwable) {
            return null;
        }

        $argumentStart = $selectionStart - $snippetStart;
        $argumentEnd = $selectionEnd - $snippetStart;

        $call = TemplateSnippetMatcher::enclosingCallAt($snippet, $argumentStart, $argumentEnd, $callee);

        // A non-null call means enclosingCallAt() already validated the bounds against $snippet.
        return $call === null ? null : [$call, \substr($snippet, $argumentStart, $argumentEnd - $argumentStart)];
    }

    /**
     * Whether one of `$names` (a `|`-separated alternation, no leading `$`) is the checked KEY in an
     * ambient-guard issue message, matched at the two anchored positions documented above, never as
     * a bare substring search.
     */
    private static function isAmbientGuardName(string $message, string $names): bool
    {
        return \preg_match('/ for \$(?:' . $names . ') is (?:never|always) /', $message) === 1
            || \preg_match('/^Cannot resolve types for \$(?:' . $names . ')(?=[\s,]|$)/', $message) === 1;
    }

    /**
     * The called expression an issue points at, read out of the shadow, or null to decline —
     * fail open, since dropping a real author issue is the costly direction and an unreadable
     * location is no evidence of anything.
     */
    private static function callExpression(CodeLocation $location): ?string
    {
        try {
            $snippet = $location->getSnippet();
            [$selectionStart] = $location->getSelectionBounds();
            [$snippetStart] = $location->getSnippetBounds();
        } catch (\Throwable) {
            return null;
        }

        // No marker stripping here, deliberately. The snippet is the WHOLE shadow line
        // (`calculateRealLocation()` resets `preview_start` to the line start, overwriting the
        // node's own `startFilePos` the constructor put there), so it does carry the line-leading
        // `blade:HASH:N` marker comment — but slicing from the selection offset leaves that
        // behind, and a marker further along the same line would need the compiler to join two
        // template lines, which a multi-line call declines on anyway. Stripping instead would cut
        // marker-shaped text out of an author's own string literal, leaving text the template does
        // not contain and dropping a real issue.
        //
        // {@see self::echoArgumentSlice()} depends on the preceding text being there.
        return TemplateSnippetMatcher::callExpressionAt($snippet, $selectionStart - $snippetStart);
    }

    /**
     * Null when any constructor parameter cannot be resolved from the original issue.
     *
     * @param array<string, mixed> $overrides constructor parameter name => value to use instead of
     *                                        the original issue's own
     */
    private static function rebuild(CodeIssue $issue, array $overrides): ?CodeIssue
    {
        try {
            $reflection = new \ReflectionClass($issue);
            $constructor = $reflection->getConstructor();

            if ($constructor === null) {
                return null;
            }

            $arguments = \array_map(
                static fn(\ReflectionParameter $parameter): mixed
                    => self::argumentFor($issue, $reflection, $parameter, $overrides),
                $constructor->getParameters(),
            );

            return $reflection->newInstanceArgs($arguments);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * One constructor argument: an override when there is one, otherwise the original issue's
     * same-named property.
     *
     * Declines by throwing rather than returning a sentinel: `rebuild()` already turns any
     * `Throwable` into a decline, and every legitimate value here is `mixed`, so no sentinel could
     * be told apart from a real argument.
     *
     * @param \ReflectionClass<CodeIssue> $reflection
     * @param array<string, mixed>        $overrides
     *
     * @throws \RuntimeException when the parameter maps to no readable property and has no default
     */
    private static function argumentFor(
        CodeIssue $issue,
        \ReflectionClass $reflection,
        \ReflectionParameter $parameter,
        array $overrides,
    ): mixed {
        $name = $parameter->getName();

        if (\array_key_exists($name, $overrides)) {
            return $overrides[$name];
        }

        $property = $reflection->hasProperty($name) ? $reflection->getProperty($name) : null;

        if ($property !== null && !$property->isStatic() && $property->isInitialized($issue)) {
            return $property->getValue($issue);
        }

        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        throw new \RuntimeException("cannot resolve constructor parameter \${$name} of " . $issue::class);
    }
}
