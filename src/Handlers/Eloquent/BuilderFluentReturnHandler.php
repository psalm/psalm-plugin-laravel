<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use Illuminate\Database\Eloquent\Builder;
use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;
use Psalm\Type\Atomic\TNamedObject;

/**
 * Eloquent\Builder is mutable, so a custom builder method returning the builder is fluent by
 * convention and `$query->published();` discards its result on purpose. Psalm only exempts a
 * method from PossiblyUnusedReturnValue/UnusedReturnValue via `probably_fluent`, which it sets
 * for a literal `return $this;` body and never for `return $this->where(...);`. Setting the flag
 * from the declared return type (`self`, `static`, `$this`, the own class name) covers that shape.
 *
 * Since 7.17.0 (vimeo/psalm#14337) Psalm exempts a verified `@return $this` method itself, but it
 * deliberately keeps reporting a delegating method declared `: self`, `: static` or `@return static`.
 *
 * A union return (`self|Collection`) is declined: the flag exempts the whole method, and the
 * non-builder arm is a result a caller can lose by discarding it.
 *
 * Accepted soundness gap: the body is never inspected, so a method returning a fresh builder
 * (`return clone $this;`) is exempted too.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1448
 */
final class BuilderFluentReturnHandler implements AfterCodebasePopulatedInterface
{
    #[\Override]
    public static function afterCodebasePopulated(AfterCodebasePopulatedEvent $event): void
    {
        $builderLower = \strtolower(Builder::class);

        foreach ($event->getCodebase()->classlike_storage_provider::getAll() as $storage) {
            if (!isset($storage->parent_classes[$builderLower])) {
                continue;
            }

            $ownNameLower = \strtolower($storage->name);

            foreach ($storage->methods as $method_storage) {
                // Psalm checks static methods regardless of probably_fluent.
                $return_type = $method_storage->return_type;
                if ($method_storage->is_static || $return_type === null || !$return_type->isSingle()) {
                    continue;
                }

                $atomic = $return_type->getSingleAtomic();

                // Psalm resolves `self` to the class name at scan time. Docblock `static` and
                // `$this` keep the value 'static'; a native `: static` is the class name with
                // is_static (BuilderNativeStaticReturnTypeHandler may already have rewritten it).
                if ($atomic instanceof TNamedObject
                    && ($atomic->is_static || \in_array(\strtolower($atomic->value), ['static', $ownNameLower], true))
                ) {
                    $method_storage->probably_fluent = true;
                }
            }
        }
    }
}
