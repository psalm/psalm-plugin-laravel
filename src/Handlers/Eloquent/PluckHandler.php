<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\Type;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

/**
 * Narrows the return type of pluck() when called on an Eloquent Builder with a
 * string literal column name that maps to a known model @property annotation.
 *
 * Without this handler, Builder::pluck('email') returns Collection<array-key, mixed>.
 * With it, if the model declares `@property string $email`, the return becomes
 * Collection<int, string>.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/486
 * @internal
 */
final class PluckHandler implements MethodReturnTypeProviderInterface
{
    /**
     * @return list<string>
     * @psalm-pure
     */
    #[\Override]
    public static function getClassLikeNames(): array
    {
        return [Builder::class];
    }

    #[\Override]
    public static function getMethodReturnType(MethodReturnTypeProviderEvent $event): ?Union
    {
        if ($event->getMethodNameLowercase() !== 'pluck') {
            return null;
        }

        $args = $event->getCallArgs();
        if ($args === []) {
            return null;
        }

        // Extract the column name from the first argument
        $columnName = self::extractStringLiteral($event, $args[0]);
        if ($columnName === null) {
            return null;
        }

        // Resolve the model class from Builder<TModel> template parameters
        $modelClass = self::resolveModelClass($event->getTemplateTypeParameters());
        if ($modelClass === null) {
            return null;
        }

        // Look up the property type on the model
        $codebase = $event->getSource()->getCodebase();
        $propertyType = self::resolvePropertyType($codebase, $modelClass, $columnName);
        if ($propertyType === null) {
            return null;
        }

        // Determine key type: int when no $key argument, array-key when $key is provided.
        // Laravel does NOT apply casts/mutators to the key column — keys come from raw PDO
        // results and are always string|int. We use array-key regardless of whether the key
        // argument is a literal or variable, since any non-null key argument causes Laravel
        // to use that column's values as array keys at runtime.
        $keyType = Type::getInt();
        if (\count($args) >= 2) {
            $keyType = Type::getArrayKey();
        }

        return new Union([
            new TGenericObject(Collection::class, [$keyType, $propertyType]),
        ]);
    }

    /**
     * Extract a string literal value from a call argument.
     */
    private static function extractStringLiteral(
        MethodReturnTypeProviderEvent $event,
        \PhpParser\Node\Arg $arg,
    ): ?string {
        $argType = $event->getSource()->getNodeTypeProvider()->getType($arg->value);
        if ($argType === null || !$argType->isSingleStringLiteral()) {
            return null;
        }

        return $argType->getSingleStringLiteral()->value;
    }

    /**
     * Resolve the model class from Builder template type parameters.
     *
     * @param non-empty-list<Union>|null $templateTypeParameters
     * @return class-string<Model>|null
     * @psalm-mutation-free
     */
    private static function resolveModelClass(?array $templateTypeParameters): ?string
    {
        if ($templateTypeParameters === null) {
            return null;
        }

        foreach ($templateTypeParameters as $type) {
            foreach ($type->getAtomicTypes() as $atomic) {
                if ($atomic instanceof TNamedObject && \is_a($atomic->value, Model::class, true)) {
                    return $atomic->value;
                }
            }
        }

        return null;
    }

    /**
     * Look up the type of a model property from @property / @property-read PHPDoc annotations.
     *
     * @param class-string<Model> $modelClass
     * @psalm-mutation-free
     */
    private static function resolvePropertyType(
        \Psalm\Codebase $codebase,
        string $modelClass,
        string $propertyName,
    ): ?Union {
        try {
            $classStorage = $codebase->classlike_storage_provider->get($modelClass);
        } catch (\InvalidArgumentException) {
            return null;
        }

        return $classStorage->pseudo_property_get_types['$' . $propertyName] ?? null;
    }
}
