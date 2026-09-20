<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

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
        // A compiled directive (Livewire tags in particular) can inject an untyped closure a
        // template author has no docblock position to annotate; every observed instance is
        // compiler-generated, so the whole family is dropped unconditionally (#1498).
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

    /** {@see MarkerPrePass::inject()}'s own per-line marker, absent from the raw template it describes. */
    private const MARKER_PATTERN = '/<\?php\s*\/\*\s*blade:\d+\s*\*\/\s*\?>/';

    /**
     * `TooManyArguments` gated on the ONE class it applies to, never the whole family: a
     * compiled `@include`/`@extends` chain also expands into calls (`$__env->make()`) that an
     * arity check would then silently drop as "generated" even when they carry a real bug such
     * as `MissingView`.
     *
     * `getSelectedText()` is too narrow for this (just the callee name, e.g. `mount`, shared by
     * every call to that method regardless of where it came from); `getSnippet()` returns the
     * shadow's whole line instead, which is exactly the generated statement for a precompiled
     * call, minus the marker comment stripped below.
     *
     * The check compares that snippet against the WHOLE template source (never a per-line match,
     * so a multi-line generated call still resolves) rather than the mapped template line: a
     * precompiler-generated call has no line of its own to be mapped from in the first place.
     */
    private static function isGeneratedArityMismatch(TooManyArguments $issue, ShadowTarget $target): bool
    {
        try {
            $snippet = $issue->code_location->getSnippet();
        } catch (\Throwable) {
            // Fail open: an unreadable snippet is never grounds to drop the issue.
            return false;
        }

        $snippet = (string) \preg_replace(self::MARKER_PATTERN, '', $snippet);

        return !TemplateSnippetMatcher::occursIn($snippet, $target->templateSource);
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
