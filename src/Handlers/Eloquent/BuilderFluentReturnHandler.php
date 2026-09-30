<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use Illuminate\Database\Eloquent\Builder;
use Psalm\Internal\Analyzer\ClassLikeAnalyzer;
use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

/**
 * A custom Eloquent\Builder method that returns self/static/its own class name is fluent by
 * convention (`$query->published();` discards the return value on purpose), but Psalm's
 * PossiblyUnusedReturnValue/UnusedReturnValue only recognizes a literal `return $this;` body.
 * Setting `MethodStorage::$probably_fluent` from the declared return type covers the common
 * `return $this->where(...);` shape without inspecting the method body.
 *
 * Scoped to every class transitively extending Eloquent\Builder (matches
 * {@see BuilderNativeStaticReturnTypeHandler}), declared (not inherited) methods only.
 *
 * Accepted soundness gap: the exemption keys on the declared return type, never on the body,
 * so a method returning a FRESH builder (`return clone $this;`) is exempted as well, and a
 * discarded return there really is a bug Psalm will no longer report. Telling that apart from
 * mutate-and-return-$this needs body analysis, and every real-world custom builder method is
 * the latter; pinned by the clonedQuery() element of the BuilderFluentReturn fixture.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1448
 */
final class BuilderFluentReturnHandler implements AfterCodebasePopulatedInterface
{
    #[\Override]
    public static function afterCodebasePopulated(AfterCodebasePopulatedEvent $event): void
    {
        $codebase = $event->getCodebase();
        $builderLower = \strtolower(Builder::class);

        foreach ($codebase->classlike_storage_provider::getAll() as $storage) {
            if (!isset($storage->parent_classes[$builderLower])) {
                continue;
            }

            $ownNameLower = \strtolower($storage->name);

            foreach ($storage->methods as $method_storage) {
                // ClassLikes::checkMethodReferences() gates on `is_static || !probably_fluent`:
                // a static method is ALWAYS subject to the unused-return check regardless of
                // probably_fluent (is_static short-circuits the OR to true), so setting the flag
                // on one would be a pure no-op — skip them. An already-fluent method (Psalm's own
                // `return $this;` detection) needs no help either.
                if ($method_storage->is_static || $method_storage->probably_fluent) {
                    continue;
                }

                if ($method_storage->visibility !== ClassLikeAnalyzer::VISIBILITY_PUBLIC) {
                    continue;
                }

                $return_type = $method_storage->return_type;
                if ($return_type === null) {
                    continue;
                }

                if (self::isFluentReturnType($return_type, $ownNameLower)) {
                    $method_storage->probably_fluent = true;
                }
            }
        }
    }

    /**
     * A union (`self|Collection`) describes DIFFERENT possible returned objects depending on
     * which path was taken, so one non-builder arm means discarding the return can lose a real
     * result — every arm must match before the whole method is exempted. An intersection
     * (`Foo&Bar`) instead describes the SAME returned object under multiple types, so a single
     * builder member already proves the returned value is the builder.
     *
     * An intersection type (`Foo&Bar`) stores only its first-listed member as the top-level
     * atomic; the rest live in that atomic's own `extra_types`
     * (TypeParser::getTypeFromIntersectionTree()), so `Contract&self` needs the same match
     * applied there too — the builder type can be either side.
     *
     * @psalm-pure
     */
    private static function isFluentReturnType(Union $return_type, string $ownNameLower): bool
    {
        foreach ($return_type->getAtomicTypes() as $atomic) {
            if (!$atomic instanceof TNamedObject) {
                return false;
            }

            if (self::matchesFluentReturn($atomic, $ownNameLower)) {
                continue;
            }

            $matchedExtra = false;
            foreach ($atomic->extra_types as $extra_type) {
                if ($extra_type instanceof TNamedObject && self::matchesFluentReturn($extra_type, $ownNameLower)) {
                    $matchedExtra = true;
                    break;
                }
            }

            if (!$matchedExtra) {
                return false;
            }
        }

        return true;
    }

    /**
     * is_static covers native `: static` (reflected with the declaring class's own FQN and
     * is_static=true); the literal value check covers `self`, docblock-only `@return static`
     * (parses to value='static', is_static=false), and the builder's own class name spelled out
     * explicitly.
     *
     * @psalm-pure
     */
    private static function matchesFluentReturn(TNamedObject $atomic, string $ownNameLower): bool
    {
        $value_lower = \strtolower($atomic->value);

        return $atomic->is_static
            || $value_lower === 'self'
            || $value_lower === 'static'
            || $value_lower === $ownNameLower;
    }
}
