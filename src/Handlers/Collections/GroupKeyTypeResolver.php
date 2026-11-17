<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Collections;

use Psalm\Codebase;
use Psalm\Exception\UnpopulatedClasslikeException;
use Psalm\Type;
use Psalm\Type\Atomic\TBool;
use Psalm\Type\Atomic\TInt;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

/**
 * The outer key type of `Collection::groupBy()` / `keyBy()` for a group value of a known type, shared by the direct
 * `groupBy('field')` form and the higher-order `groupBy->field` proxy.
 *
 * Laravel turns bool into int and a UnitEnum into its backed value, while PHP casts a numeric-string array key to int
 * on write, so a `string` value is not provably a string key and gets no answer. Only values that always end up as an
 * `int` key are resolved.
 *
 * @internal
 */
final class GroupKeyTypeResolver
{
    /**
     * `int` when every atomic of the group value is an int, a bool, or a single int-backed enum; null (not proven) otherwise.
     *
     * @psalm-capabilities read-props
     */
    public static function resolve(?Union $groupValue, Codebase $codebase): ?Union
    {
        if (!$groupValue instanceof Union) {
            return null;
        }

        $atomics = $groupValue->getAtomicTypes();
        $atomic = \reset($atomics);
        if (\count($atomics) === 1 && $atomic instanceof TNamedObject) {
            try {
                return $codebase->classlike_storage_provider->get($atomic->value)->enum_type === 'int'
                    ? Type::getInt()
                    : null;
            } catch (\InvalidArgumentException|UnpopulatedClasslikeException) {
                return null;
            }
        }

        foreach ($atomics as $atomic) {
            if (!$atomic instanceof TInt && !$atomic instanceof TBool) {
                return null;
            }
        }

        return Type::getInt();
    }
}
