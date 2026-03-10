<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Psalm\Codebase;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\Type;
use Psalm\Type\Union;

final class RelationsMethodHandler implements MethodReturnTypeProviderInterface
{
    /** @var array<string, bool> Cache: method name → returns Builder? */
    private static array $builderReturnCache = [];

    /**
     * @return list<string>
     * @psalm-pure
     */
    #[\Override]
    public static function getClassLikeNames(): array
    {
        return [
            Relation::class,
            BelongsTo::class,
            BelongsToMany::class,
            HasMany::class,
            HasManyThrough::class,
            HasOne::class,
            HasOneOrMany::class,
            HasOneThrough::class,
        ];
    }

    /** @psalm-external-mutation-free */
    #[\Override]
    public static function getMethodReturnType(MethodReturnTypeProviderEvent $event): ?Union
    {
        $source = $event->getSource();

        if (!$source instanceof StatementsAnalyzer) {
            return null;
        }

        $method_name_lowercase = $event->getMethodNameLowercase();
        $codebase = $source->getCodebase();

        // Relations proxy method calls to the underlying Builder. When the Builder
        // method returns $this/static (i.e., returns a Builder), the Relation should
        // return itself instead, preserving the fluent chain on the Relation type.
        //
        // We look up the Builder method's return type directly from the codebase
        // and check if it contains Builder. This avoids the expensive executeFakeCall()
        // approach which cloned node_data and caused 50+ GB memory explosion on large
        // codebases.

        $template_type_parameters = $event->getTemplateTypeParameters();
        if (!$template_type_parameters) {
            return null;
        }

        if (self::builderMethodReturnsSelf($codebase, $method_name_lowercase)) {
            return new Union([
                new Type\Atomic\TGenericObject($event->getFqClasslikeName(), $template_type_parameters),
            ]);
        }

        // For Builder methods that don't return Builder (e.g., ->first(), ->count()),
        // or methods not found on Builder, let Psalm resolve the return type naturally.
        return null;
    }

    /** @psalm-external-mutation-free */
    private static function builderMethodReturnsSelf(Codebase $codebase, string $method_name_lowercase): bool
    {
        if (\array_key_exists($method_name_lowercase, self::$builderReturnCache)) {
            return self::$builderReturnCache[$method_name_lowercase];
        }

        $result = self::resolveBuilderMethodReturnsSelf($codebase, $method_name_lowercase);
        self::$builderReturnCache[$method_name_lowercase] = $result;

        return $result;
    }

    /** @psalm-mutation-free */
    private static function resolveBuilderMethodReturnsSelf(Codebase $codebase, string $method_name_lowercase): bool
    {
        /** @var lowercase-string $method_name_lowercase */

        // Look up the actual declared method storage (not __call) to get the real return
        // type. Methods like where(), orderBy() are declared in our stubs but
        // Codebase\Methods::methodExists() may resolve them through __call, and
        // getStorage() would then return __call's storage (mixed) instead.
        foreach ([Builder::class, QueryBuilder::class] as $builderClass) {
            try {
                $classStorage = $codebase->classlike_storage_provider->get(\strtolower($builderClass));
            } catch (\InvalidArgumentException) {
                continue;
            }

            $declaringId = $classStorage->declaring_method_ids[$method_name_lowercase] ?? null;
            if ($declaringId === null) {
                continue;
            }

            try {
                $storage = $codebase->methods->getStorage($declaringId);
            } catch (\UnexpectedValueException) {
                continue;
            }

            $returnType = $storage->return_type;
            if ($returnType === null) {
                continue;
            }

            foreach ($returnType->getAtomicTypes() as $atomicType) {
                if ($atomicType instanceof Type\Atomic\TNamedObject) {
                    $fqcn = \strtolower($atomicType->value);
                    // Match Eloquent\Builder or static/$this return types.
                    // Do NOT match Query\Builder — methods like toBase()/getQuery()
                    // return Query\Builder intentionally, not a fluent chain.
                    if (
                        $fqcn === \strtolower(Builder::class)
                        || $fqcn === 'static'
                    ) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}
