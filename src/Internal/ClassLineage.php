<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Internal;

use Psalm\Codebase;

/**
 * Lineage checks on analysis-time class names, answered from Psalm's class storage.
 *
 * Never use `\is_a($class, X::class, true)` / `\is_subclass_of()` on a Psalm-inferred or declared
 * type: it autoloads $class, and any load-time diagnostic (e.g. an E_USER_DEPRECATED at file top)
 * is turned into a thrown exception by Psalm's ErrorHandler, crashing the whole run (#1253, #1652).
 *
 * @internal
 * @psalm-immutable
 */
final class ClassLineage
{
    /**
     * $class is $ancestor, extends it, implements it, or (as an interface) extends it.
     * Reflexive like `is_a()`; `class_alias()` aliases of $class resolve to their target first.
     * Classes without storage yield false. Meant for analysis-time callers (post-population):
     * on an unpopulated class only the scanner-recorded direct parents would be visible.
     *
     * @template T of object
     * @param class-string<T> $ancestor
     * @psalm-assert-if-true class-string<T> $class
     * @psalm-mutation-free
     */
    public static function isA(Codebase $codebase, string $class, string $ancestor): bool
    {
        // Non-autoloading alias map lookup; interfaceExtends() does not unalias its subject itself.
        $subject = $codebase->classlikes->getUnAliasedName($class);

        if (\strtolower($subject) === \strtolower($ancestor)) {
            return true;
        }

        try {
            // classExtendsOrImplements() returns false (no throw) for interface subjects, which
            // have storage; interfaceExtends() then covers interface-to-interface lineage.
            return $codebase->classExtendsOrImplements($subject, $ancestor)
                || $codebase->interfaceExtends($subject, $ancestor);
        } catch (\InvalidArgumentException) {
            // No storage for $class.
            return false;
        }
    }
}
