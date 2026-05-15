<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use PhpParser\Node\Arg;
use Psalm\Codebase;
use Psalm\NodeTypeProvider;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Type;
use Psalm\Type\Atomic\TKeyedArray;
use Psalm\Type\Union;

/**
 * Narrows the return type of Model::only() to a TKeyedArray when the keys argument
 * resolves to literal strings.
 *
 * Without this handler, $user->only(['email', 'name']) returns array<string, mixed>.
 * With it, the type becomes array{email: string, name: string} when the model declares
 * @property string $email and @property string $name. Unknown keys (no @property hint)
 * resolve to mixed, but the shape — which keys are present — is still known.
 *
 * Mirrors Laravel's runtime behavior on `HasAttributes::only()`:
 * - When the first argument is an array, that array's elements are used.
 * - Otherwise, all positional arguments are taken as string keys.
 *
 * Registered per concrete Model class by {@see ModelRegistrationHandler} because
 * Psalm's provider lookup uses exact class name matching. Both `only()` and `except()`
 * live on Laravel's `HasAttributes` trait, hence the "AttributeSubset" name — `except()`
 * can be added here once a complete attribute enumeration becomes available; for now
 * `except()` cannot be soundly narrowed from `@property` declarations alone (the
 * @property set is usually a strict subset of the actual database attributes).
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/931
 * @internal
 */
final class ModelAttributeSubsetHandler
{
    public static function getReturnType(MethodReturnTypeProviderEvent $event): ?Union
    {
        if ($event->getMethodNameLowercase() !== 'only') {
            return null;
        }

        $args = $event->getCallArgs();
        if ($args === []) {
            return null;
        }

        $source = $event->getSource();
        $keys = self::collectLiteralKeys($args, $source->getNodeTypeProvider());
        if ($keys === null || $keys === []) {
            return null;
        }

        return self::buildKeyedArray(
            $source->getCodebase(),
            $event->getFqClasslikeName(),
            $keys,
        );
    }

    /**
     * Collect literal string keys from the call arguments.
     * Returns null when any argument is not statically resolvable to literal strings.
     *
     * Laravel branches on whether the first argument is an array:
     *   only(['a', 'b'])     → iterate ['a', 'b']
     *   only('a', 'b')       → iterate func_get_args() = ['a', 'b']
     *   only(['a','b'], 'c') → first arg is array, 'c' is ignored
     *
     * @param list<Arg> $args
     * @return list<string>|null
     */
    private static function collectLiteralKeys(array $args, NodeTypeProvider $ntp): ?array
    {
        $firstArgType = $ntp->getType($args[0]->value);
        if (!$firstArgType instanceof Union) {
            return null;
        }

        if (self::isArrayLike($firstArgType)) {
            return self::extractLiteralStringsFromArray($firstArgType);
        }

        $keys = [];
        foreach ($args as $arg) {
            $argType = $ntp->getType($arg->value);
            if (!$argType instanceof Union || !$argType->isSingleStringLiteral()) {
                return null;
            }
            $keys[] = $argType->getSingleStringLiteral()->value;
        }

        return $keys;
    }

    /** @psalm-mutation-free */
    private static function isArrayLike(Union $type): bool
    {
        foreach ($type->getAtomicTypes() as $atomic) {
            if ($atomic instanceof TKeyedArray || $atomic instanceof Type\Atomic\TArray) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract literal string values from an array-typed Union.
     *
     * Only sealed literal array shapes (a single TKeyedArray atomic with no
     * fallback_params and no possibly-undefined entries) are narrowed. Falls back
     * to Laravel's default signature in these cases:
     * - Multi-atomic Union (e.g., `$cond ? ['id'] : ['name']`), because flattening
     *   atomics into one shape would widen the runtime contract.
     * - Non-`TKeyedArray` atomic (plain `TArray<K, V>`), which carries no literal keys.
     * - Unsealed shape (`array{known: T, ...<K, V>}`, including `list<'a'|'b'>` that
     *   Psalm models with fallback_params), which admits unknown extra keys.
     * - Possibly-undefined entries, because the key may be absent at runtime.
     *
     * @return list<string>|null
     * @psalm-mutation-free
     */
    private static function extractLiteralStringsFromArray(Union $type): ?array
    {
        $atomics = $type->getAtomicTypes();
        if (\count($atomics) !== 1) {
            return null;
        }

        $atomic = \reset($atomics);
        if (!$atomic instanceof TKeyedArray || $atomic->fallback_params !== null) {
            return null;
        }

        $keys = [];
        foreach ($atomic->properties as $propType) {
            if ($propType->possibly_undefined || !$propType->isSingleStringLiteral()) {
                return null;
            }
            $keys[] = $propType->getSingleStringLiteral()->value;
        }

        return $keys;
    }

    /**
     * Build a TKeyedArray with each key's value type pulled from the model's
     * @property declaration; missing keys fall back to mixed.
     *
     * @param non-empty-list<string> $keys
     * @psalm-mutation-free
     */
    private static function buildKeyedArray(Codebase $codebase, string $modelClass, array $keys): ?Union
    {
        try {
            $classStorage = $codebase->classlike_storage_provider->get($modelClass);
        } catch (\InvalidArgumentException) {
            return null;
        }

        $properties = [];
        foreach ($keys as $key) {
            $properties[$key] = $classStorage->pseudo_property_get_types['$' . $key] ?? Type::getMixed();
        }

        return new Union([TKeyedArray::make($properties)]);
    }
}
