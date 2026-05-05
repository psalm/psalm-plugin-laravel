<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Psalm\Codebase;
use Psalm\Plugin\EventHandler\Event\MethodExistenceProviderEvent;
use Psalm\Plugin\EventHandler\Event\MethodParamsProviderEvent;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\Event\MethodVisibilityProviderEvent;
use Psalm\StatementsSource;
use Psalm\Storage\FunctionLikeParameter;
use Psalm\Type;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

/**
 * Resolves Laravel Factory magic methods `for{Relation}()` and `has{Relation}()`
 * that map to relationship methods on the associated Model.
 *
 *   Post::factory()->forUser()->hasComments(3)->create();
 *
 * Laravel's Factory::__call dispatches these dynamically: it strips the for/has prefix,
 * camelCases the remainder, then calls `$this->newModel()->{$relationship}()` to look
 * up the relation on the model. This handler mirrors that resolution at static-analysis
 * time so Psalm doesn't report UndefinedMagicMethod for these chained calls.
 *
 * Registered per-factory class by {@see FactoryRegistrationHandler} because Psalm's
 * provider lookup is exact-class — a handler on Factory::class is not consulted for
 * concrete user subclasses like App\Factories\PostFactory.
 *
 * @internal
 */
final class FactoryMagicMethodHandler
{
    /**
     * Map: lowercased Factory FQCN → associated Model FQCN.
     *
     * Lowercased to match the casing of {@see MethodExistenceProviderEvent::getFqClasslikeName}
     * results (Psalm normalizes class names to the declared casing, but lookups via
     * `strtolower()` are the safe convention across the plugin).
     *
     * @var array<lowercase-string, class-string<Model>>
     */
    private static array $factoryToModel = [];

    /**
     * Memoizes whether a given (model, methodName) pair is a relationship method.
     * Each call site triggers up to four event lookups (existence/visibility/params/
     * return), so caching avoids redundant `getMethodReturnType()` work.
     *
     * @var array<string, bool>
     */
    private static array $relationshipMethodCache = [];

    /**
     * Register the model associated with a Factory subclass. Called by
     * {@see FactoryRegistrationHandler} once per concrete factory whose target
     * model could be resolved.
     *
     * @param class-string<Factory> $factoryClass
     * @param class-string<Model> $modelClass
     * @psalm-external-mutation-free
     */
    public static function registerFactoryToModelMapping(string $factoryClass, string $modelClass): void
    {
        self::$factoryToModel[\strtolower($factoryClass)] = $modelClass;
    }

    public static function doesMethodExist(MethodExistenceProviderEvent $event): ?bool
    {
        return self::magicCallApplies(
            $event->getSource(),
            $event->getFqClasslikeName(),
            $event->getMethodNameLowercase(),
        );
    }

    public static function isMethodVisible(MethodVisibilityProviderEvent $event): ?bool
    {
        return self::magicCallApplies(
            $event->getSource(),
            $event->getFqClasslikeName(),
            $event->getMethodNameLowercase(),
        );
    }

    /**
     * @return list<FunctionLikeParameter>|null
     */
    public static function getMethodParams(MethodParamsProviderEvent $event): ?array
    {
        if (
            self::magicCallApplies(
                $event->getStatementsSource(),
                $event->getFqClasslikeName(),
                $event->getMethodNameLowercase(),
            ) !== true
        ) {
            return null;
        }

        // Variadic mixed mirrors Factory::__call($method, $parameters) — the dispatcher
        // accepts any positional args and dispatches at runtime. Encoding the precise
        // overload set (for: ?array|callable|Factory|Model; has: int|array|callable
        // plus optional second array, plus the sequence-of-arrays form) would be lossy
        // and risk false-positives on legitimate call shapes.
        return [
            new FunctionLikeParameter(
                name: 'parameters',
                by_ref: false,
                type: Type::getMixed(),
                is_variadic: true,
            ),
        ];
    }

    public static function getMethodReturnType(MethodReturnTypeProviderEvent $event): ?Union
    {
        if (
            self::magicCallApplies(
                $event->getSource(),
                $event->getFqClasslikeName(),
                $event->getMethodNameLowercase(),
            ) !== true
        ) {
            return null;
        }

        // Use the called class so subclass chains preserve the most-derived type.
        // Each Factory subclass gets its own handler registered (see
        // FactoryRegistrationHandler), so `getCalledFqClasslikeName()` already
        // returns the most-derived class — no `is_static` flag needed, which would
        // only render as a noisy `WorkOrderFactory&static` intersection.
        $calledClass = $event->getCalledFqClasslikeName() ?? $event->getFqClasslikeName();

        return new Union([new TNamedObject($calledClass)]);
    }

    /**
     * Whether the call qualifies as a Factory magic for*()/has*() invocation that
     * resolves to a relationship method on the registered model.
     *
     * Returns true on success; null otherwise (which causes the corresponding
     * provider to decline the event so the next handler / native lookup runs).
     * Null is the correct decline signal — returning false would assert "this
     * method definitely does not exist," shadowing real Factory methods.
     *
     * Critically, this also declines when the method is declared natively on the
     * factory class (or inherited from {@see Factory}). Psalm's
     * {@see \Psalm\Internal\Analyzer\Statements\Expression\Call\Method\MethodCallReturnTypeFetcher}
     * consults registered providers for *every* method call on the class — not
     * just unrecognized ones — so without this guard our params/return-type
     * providers would shadow real methods like `Factory::hasAttached()` whenever
     * the model happens to expose a relation named `attached`.
     */
    private static function magicCallApplies(
        ?StatementsSource $source,
        string $factoryClass,
        string $methodNameLowercase,
    ): ?bool {
        if (!$source instanceof StatementsSource) {
            return null;
        }

        // Cheap rejects first — most calls on factories are non-magic methods
        // (`make`, `create`, `count`, `state`, ...), so failing fast on the prefix
        // shape avoids the `strtolower` + map lookup for the common case.
        $relationshipName = self::extractRelationshipName($methodNameLowercase);
        if ($relationshipName === null) {
            return null;
        }

        $modelClass = self::$factoryToModel[\strtolower($factoryClass)] ?? null;
        if ($modelClass === null) {
            return null;
        }

        $codebase = $source->getCodebase();

        // Decline when the method exists natively on the factory class. This
        // includes both inherited Factory methods (`hasAttached`, `forEachSequence`,
        // etc.) and user-defined overrides on the factory subclass — both must
        // win over our magic resolution to preserve their real signatures.
        //
        // use_method_existence_provider: false is critical here: Psalm's
        // methodExists() defaults to consulting the existence_provider, which
        // would call back into THIS handler with identical args and recurse
        // unboundedly. We only care about native (storage-based) existence here.
        // The string-form method id sidesteps MethodIdentifier's lowercase-string
        // constraint; Psalm's $codebase->methodExists() lowercases internally.
        if ($codebase->methodExists(
            $factoryClass . '::' . $methodNameLowercase,
            use_method_existence_provider: false,
        )) {
            return null;
        }

        if (!self::isRelationshipMethod($codebase, $modelClass, $relationshipName)) {
            return null;
        }

        return true;
    }

    /**
     * Extract the relationship-method name from a magic for*()/has*() call.
     *
     *   "foruser"     → "user"
     *   "hascomments" → "comments"
     *
     * Returns null for the bare "for" / "has" methods (which exist natively on
     * Factory and never reach this handler) and for any other prefix.
     *
     * Laravel applies `Str::camel(Str::substr($method, 3))` to the original (cased)
     * method name. We work with the lowercased form because Psalm's method lookup is
     * case-insensitive — `methodExists('App\Models\Post::user')` matches a `user()`
     * declaration regardless of the cased input.
     *
     * @psalm-pure
     */
    private static function extractRelationshipName(string $methodNameLowercase): ?string
    {
        if (\strlen($methodNameLowercase) <= 3) {
            return null;
        }

        $prefix = \substr($methodNameLowercase, 0, 3);
        if ($prefix !== 'for' && $prefix !== 'has') {
            return null;
        }

        return \substr($methodNameLowercase, 3);
    }

    /**
     * Check whether $relationshipName on $modelClass is a relationship method.
     *
     * Mirrors {@see ModelRelationshipPropertyHandler::relationExists()} — accept both
     * generic (`HasMany<Comment, $this>`) and non-generic (`HasMany`) Relation return
     * types, and parse the method body via {@see RelationMethodParser} when no return
     * type is declared (e.g. `public function image() { return $this->morphOne(...); }`).
     */
    private static function isRelationshipMethod(Codebase $codebase, string $modelClass, string $relationshipName): bool
    {
        $methodId = $modelClass . '::' . $relationshipName;
        if (\array_key_exists($methodId, self::$relationshipMethodCache)) {
            return self::$relationshipMethodCache[$methodId];
        }

        if (!$codebase->methodExists($methodId)) {
            return self::$relationshipMethodCache[$methodId] = false;
        }

        // getMethodReturnType() takes $self_class by reference and may set it to null,
        // so use a disposable copy to protect $modelClass.
        $selfClass = $modelClass;
        try {
            $returnType = $codebase->getMethodReturnType($methodId, $selfClass);
        } catch (\InvalidArgumentException $e) {
            $codebase->progress->debug("Laravel plugin: could not get return type for {$methodId}: {$e->getMessage()}\n");
            return self::$relationshipMethodCache[$methodId] = false;
        }

        if ($returnType instanceof Union) {
            foreach ($returnType->getAtomicTypes() as $type) {
                if ($type instanceof TNamedObject && \is_a($type->value, Relation::class, true)) {
                    return self::$relationshipMethodCache[$methodId] = true;
                }
            }
        }

        // No return type declared — fall back to AST inspection of the method body.
        if (!$returnType instanceof Union
            && RelationMethodParser::parse($codebase, $modelClass, $relationshipName) !== null
        ) {
            return self::$relationshipMethodCache[$methodId] = true;
        }

        return self::$relationshipMethodCache[$methodId] = false;
    }
}
