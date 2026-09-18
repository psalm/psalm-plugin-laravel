<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

use Psalm\CodeLocation;
use Psalm\CodeLocation\Raw;
use Psalm\Issue\CodeIssue;

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
 * Known gap: a `TaintedInput` journey is carried across verbatim, so its individual steps still name
 * the shadow file even though the sink is relocated.
 *
 * @internal
 */
final class ShadowIssueRelocator
{
    private const UNMAPPED_SUFFIX = ' (unmapped)';

    /**
     * @param string $templateSource the template's bytes, which a `Raw` location indexes into
     * @param string $templateName   the display name Psalm's reporters print for the template
     *
     * @return CodeIssue|false|null the issue to re-emit on the template, `false` to drop it, `null`
     *                              to decline and leave Psalm's own handling of the original alone
     */
    public static function relocate(
        CodeIssue $issue,
        ShadowEntry $entry,
        string $templateSource,
        string $templateName,
    ): CodeIssue|false|null {
        $templateLine = $entry->lineMap[$issue->code_location->getLineNumber()] ?? 0;
        $message = $issue->message;

        if ($templateLine < 1) {
            // The prelude and any line the marker pass could not map have no template position.
            // A Mixed* issue there is an artifact of the prelude typing every template variable it
            // cannot resolve as `mixed`, so it is dropped rather than parked on line 1; anything
            // else is worth showing even without an exact line.
            if (\str_starts_with($issue::getIssueType(), 'Mixed')) {
                return false;
            }

            $templateLine = 1;
            $message .= self::UNMAPPED_SUFFIX;
        }

        $bounds = self::lineBounds($templateSource, $templateLine);

        if ($bounds === null) {
            return null;
        }

        [$start, $end] = $bounds;

        return self::rebuild(
            $issue,
            new Raw($templateSource, $entry->templatePath, $templateName, $start, $end),
            $message,
        );
    }

    /**
     * Byte offsets of a 1-based line, or null when the source has no such line.
     *
     * @return array{int, int}|null
     */
    private static function lineBounds(string $source, int $line): ?array
    {
        $start = 0;

        for ($current = 1; $current < $line; $current++) {
            $newline = \strpos($source, "\n", $start);

            if ($newline === false) {
                return null;
            }

            $start = $newline + 1;
        }

        if ($start > \strlen($source)) {
            return null;
        }

        $newline = \strpos($source, "\n", $start);
        $end = $newline === false ? \strlen($source) : $newline;

        return [$start, \max($start, $end - 1)];
    }

    /** Null when any constructor parameter cannot be resolved from the original issue. */
    private static function rebuild(CodeIssue $issue, CodeLocation $location, string $message): ?CodeIssue
    {
        try {
            $reflection = new \ReflectionClass($issue);
            $constructor = $reflection->getConstructor();

            if ($constructor === null) {
                return null;
            }

            $arguments = \array_map(
                static fn(\ReflectionParameter $parameter): mixed
                    => self::argumentFor($issue, $reflection, $parameter, $location, $message),
                $constructor->getParameters(),
            );

            return $reflection->newInstanceArgs($arguments);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * One constructor argument, taken from the original issue's same-named property.
     *
     * Declines by throwing rather than returning a sentinel: `rebuild()` already turns any
     * `Throwable` into a decline, and every legitimate value here is `mixed`, so no sentinel could
     * be told apart from a real argument.
     *
     * @param \ReflectionClass<CodeIssue> $reflection
     *
     * @throws \RuntimeException when the parameter maps to no readable property and has no default
     */
    private static function argumentFor(
        CodeIssue $issue,
        \ReflectionClass $reflection,
        \ReflectionParameter $parameter,
        CodeLocation $location,
        string $message,
    ): mixed {
        $name = $parameter->getName();

        if ($name === 'code_location') {
            return $location;
        }

        if ($name === 'message') {
            return $message;
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
