<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Internal;

use Psalm\Codebase;
use Psalm\Exception\UnpopulatedClasslikeException;

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
     * Reflexive like `is_a()`; unknown or unpopulated classes yield false.
     *
     * @template T of object
     * @param class-string<T> $ancestor
     * @psalm-assert-if-true class-string<T> $class
     * @psalm-mutation-free
     */
    public static function isA(Codebase $codebase, string $class, string $ancestor): bool
    {
        if (\strtolower($class) === \strtolower($ancestor)) {
            return true;
        }

        try {
            // classExtendsOrImplements() returns false (no throw) for interface subjects, which
            // have storage; interfaceExtends() then covers interface-to-interface lineage.
            return $codebase->classExtendsOrImplements($class, $ancestor)
                || $codebase->interfaceExtends($class, $ancestor);
        } catch (\InvalidArgumentException|UnpopulatedClasslikeException) {
            return false;
        }
    }
}
