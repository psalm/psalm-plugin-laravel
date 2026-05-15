<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use Psalm\Storage\FunctionLikeParameter;
use Psalm\Type;
use Psalm\Type\Atomic\TArray;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TString;
use Psalm\Type\Union;

/**
 * SoftDeletingScope runtime macros that have no Builder-returning @method declaration.
 *
 * Hardcoded list mirrors Larastan's EloquentBuilderForwardsCallsExtension. Generic
 * AST scanning of SoftDeletingScope::extend() would generalise to other scope-extension
 * traits but requires running closure return-type inference inside our handler — much
 * higher implementation cost for a single Laravel trait. See issue #929.
 *
 * Values match Psalm's lowercased method-name convention so {@see self::tryFrom()}
 * accepts the result of {@see \Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent::getMethodNameLowercase()}
 * directly.
 *
 * @internal Used by {@see BuilderScopeHandler} only.
 */
enum SoftDeletesMacro: string
{
    case Restore         = 'restore';
    case RestoreOrCreate = 'restoreorcreate';
    case CreateOrRestore = 'createorrestore';

    /**
     * Return type for the macro when invoked on a Builder<TModel> instance.
     *
     * - restore returns int (count of restored rows from Builder::update).
     * - restoreOrCreate / createOrRestore return TModel (firstOrCreate / createOrFirst).
     *
     * @param class-string $modelClass
     * @psalm-mutation-free
     */
    public function returnType(string $modelClass): Union
    {
        return match ($this) {
            self::Restore => Type::getInt(),
            self::RestoreOrCreate, self::CreateOrRestore => new Union([new TNamedObject($modelClass)]),
        };
    }

    /**
     * Synthetic params mirroring the closures in SoftDeletingScope::addRestore* :
     * restore takes no args; restoreOrCreate / createOrRestore take
     * (array $attributes = [], array $values = []) typed array<string, mixed>.
     *
     * Required so Psalm's checkMethodArgs does not crash when looking up params for
     * the magic-call route (Builder::restore / restoreOrCreate / createOrRestore have
     * no real declaration).
     *
     * @return list<FunctionLikeParameter>
     * @psalm-external-mutation-free
     */
    public function params(): array
    {
        return match ($this) {
            self::Restore => [],
            self::RestoreOrCreate, self::CreateOrRestore => self::createOrRestoreParams(),
        };
    }

    /**
     * Lazily-built and method-locally cached so we do not allocate Union/TArray
     * instances unless a restore-family macro actually appears in the analysed code.
     *
     * @return list<FunctionLikeParameter>
     * @psalm-external-mutation-free
     */
    private static function createOrRestoreParams(): array
    {
        /** @var list<FunctionLikeParameter>|null $cached */
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        // Type::getString() is not @psalm-pure (unlike its int / mixed siblings), so
        // building array<string, mixed> via direct construction keeps callers in pure
        // / mutation-free contexts when they need it.
        $arrayType = new Union([new TArray([new Union([new TString()]), Type::getMixed()])]);
        $emptyArray = Type::getEmptyArray();

        return $cached = [
            new FunctionLikeParameter(
                name: 'attributes',
                by_ref: false,
                type: $arrayType,
                signature_type: $arrayType,
                is_optional: true,
                default_type: $emptyArray,
            ),
            new FunctionLikeParameter(
                name: 'values',
                by_ref: false,
                type: $arrayType,
                signature_type: $arrayType,
                is_optional: true,
                default_type: $emptyArray,
            ),
        ];
    }
}
