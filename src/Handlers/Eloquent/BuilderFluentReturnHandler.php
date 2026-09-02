<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use Illuminate\Database\Eloquent\Builder;
use Psalm\Internal\Analyzer\ClassLikeAnalyzer;
use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;
use Psalm\Type\Atomic\TNamedObject;

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
                // is_static is already exempt in ClassLikes::checkMethodReferences(), and an
                // already-fluent method (Psalm's own `return $this;` detection) needs no help.
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

                foreach ($return_type->getAtomicTypes() as $atomic) {
                    if (!$atomic instanceof TNamedObject) {
                        continue;
                    }

                    // is_static covers native `: static` (reflected with the declaring class's
                    // own FQN and is_static=true); the literal value check covers `self`,
                    // docblock-only `@return static` (parses to value='static', is_static=false),
                    // and the builder's own class name spelled out explicitly.
                    $value_lower = \strtolower($atomic->value);
                    if ($atomic->is_static
                        || $value_lower === 'self'
                        || $value_lower === 'static'
                        || $value_lower === $ownNameLower
                    ) {
                        $method_storage->probably_fluent = true;
                        break;
                    }
                }
            }
        }
    }
}
