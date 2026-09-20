<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

use Psalm\CodeLocation;
use Psalm\CodeLocation\Raw;
use Psalm\Issue\CodeIssue;
use Psalm\Issue\MissingClosureParamType;
use Psalm\Issue\MissingClosureReturnType;
use Psalm\Issue\MixedIssue;
use Psalm\Issue\TooManyArguments;

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

        if ($issue instanceof TooManyArguments && self::isGeneratedArityMismatch($issue, $target)) {
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

        // No marker stripping here, deliberately. `CodeLocation::$preview_start` is the located
        // node's own `startFilePos`, so the snippet begins AT the callee name and a line-leading
        // marker is already behind it; a marker further along the same shadow line would need the
        // compiler to join two template lines, and a call that does span lines declines above
        // anyway. Stripping instead would cut marker-shaped text out of an author's own string
        // literal, leaving text the template does not contain and dropping a real issue.
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
