<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use PhpParser;
use Psalm\Codebase;
use Psalm\Exception\UnpopulatedClasslikeException;
use Psalm\Internal\Analyzer\ClassLikeAnalyzer;
use Psalm\Internal\MethodIdentifier;
use Psalm\LaravelPlugin\Handlers\Magic\ReturnTypeResolver;
use Psalm\LaravelPlugin\Internal\Ast\BodyReturnCollectorVisitor;
use Psalm\LaravelPlugin\Internal\Ast\ClassMethodResolver;
use Psalm\LaravelPlugin\Internal\ClassLineage;
use Psalm\Storage\MethodStorage;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TMixed;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TNull;
use Psalm\Type\Atomic\TObject;
use Psalm\Type\Atomic\TTemplateParam;
use Psalm\Type\Union;

/**
 * Extracts relationship metadata from a model method's AST body and docblock annotations,
 * without invoking the method.
 *
 * This enables property type resolution for relationship accessors even when the
 * return type lacks generic annotations. For example, given:
 *
 *   public function vault(): BelongsTo { return $this->belongsTo(Vault::class); }
 *
 * This parser extracts both "belongsTo" (factory method) and "App\Models\Vault" (related model).
 *
 * Handles:
 * - Direct returns: return $this->belongsTo(Vault::class)
 * - Chained methods: return $this->belongsTo(Vault::class)->withDefault()
 * - No declared return type: public function image() { return $this->morphOne(Image::class, 'imageable'); }
 * - Docblock generics for morphTo: @return MorphTo<User|Post, $this>
 * - Trait-hosted methods: the body is read from the trait, `self::class` / `static::class` bind to
 *   the composing class; declines when composed traits (nested ones included) declare the name twice (#1613)
 * - Several returns (early returns in `if` / loops): only when every one resolves to the same relation;
 *   null exits (`return;` / `return null;`) are skipped and mark the result `nullable`
 * - Delegation to another relation method, dispatched as PHP does (receiver override; private
 *   methods bind to the calling scope):
 *   public function openPosts(): HasMany { return $this->posts()->where('open', true); }
 *   Only under the conditions of {@see parseDelegation()} (#1613)
 *
 * @internal
 */
final class RelationMethodParser
{
    /**
     * Parsed result: the relationship factory method, related model FQCN, and (for "through"
     * relations only) the intermediate model FQCN. For BelongsToMany / MorphToMany only,
     * the chain is also scanned for `->using(Pivot::class)` and `->as('accessor')` mutations
     * which rebind TPivotModel / TAccessor on the relation.
     *
     * relatedModel is null when:
     * - The relation is polymorphic (morphTo) — the related type is not statically determinable
     * - The first argument could not be statically resolved (e.g. a variable or method call)
     *
     * intermediateModel is non-null only for hasOneThrough / hasManyThrough — for every other
     * relation factory it stays null. Resolves the same way as relatedModel: missing or non-
     * literal class-string arguments produce null.
     *
     * pivotModel / accessor are non-null only when an explicit `->using(...)` / `->as(...)` was
     * detected on a BelongsToMany or MorphToMany chain with a statically resolvable argument.
     * Other relations leave them null.
     *
     * @var array<string, ?array{relationClass: class-string<Relation>, relatedModel: ?string, intermediateModel: ?string, pivotModel: ?string, accessor: ?string, nullable: bool}>
     */
    private static array $cache = [];

    /**
     * parse() keys on the current delegation path. Revisiting one means the bodies delegate in a
     * cycle (`a()` returns `$this->b()`, `b()` returns `$this->a()`), which never builds a relation.
     *
     * @var array<string, true>
     */
    private static array $resolving = [];

    /**
     * Eloquent\Builder methods declared `static` / `$this` that return a different instance.
     */
    private const NEW_INSTANCE_METHODS = ['clone', 'applyscopes'];

    /** @var ?list<mixed> Eloquent\Builder::$passthru of the running Laravel; null until first read */
    private static ?array $passthru = null;

    public static function reset(): void
    {
        self::$cache = [];
        self::$resolving = [];
        self::$passthru = null;
    }

    /** @var list<string> Types that should not be resolved as class names in generic params */
    private const NON_CLASS_TYPES = [
        'static',
        'self',
        'parent',
        'null',
        'int',
        'string',
        'bool',
        'float',
        'mixed',
        'array',
        'object',
        'callable',
        'iterable',
        'void',
        'never',
        'true',
        'false',
        'scalar',
        'numeric',
        'resource',
        // Deprecated aliases recognized by Psalm's TypeTokenizer
        'boolean',
        'integer',
        'double',
        'real',
    ];

    /**
     * Maps HasRelationships factory method names to their corresponding Relation class FQCNs.
     *
     * @var array<string, class-string<Relation>>
     */
    private const FACTORY_TO_RELATION = [
        'hasone' => HasOne::class,
        'hasmany' => HasMany::class,
        'hasonethrough' => HasOneThrough::class,
        'hasmanythrough' => HasManyThrough::class,
        'belongsto' => BelongsTo::class,
        'belongstomany' => BelongsToMany::class,
        'morphone' => MorphOne::class,
        'morphmany' => MorphMany::class,
        'morphto' => MorphTo::class,
        'morphtomany' => MorphToMany::class,
        'morphedbymany' => MorphToMany::class,
    ];

    /**
     * Parse a relationship method body and extract the relation class, related model, (for
     * through relations only) the intermediate model, and (for BelongsToMany / MorphToMany
     * only) any `->using()` / `->as()` mutations detected on the chain.
     *
     * @return ?array{relationClass: class-string<Relation>, relatedModel: ?string, intermediateModel: ?string, pivotModel: ?string, accessor: ?string, nullable: bool}
     *         null if the method cannot be parsed as a relationship method.
     *         relatedModel is null when polymorphic (morphTo) or when the first argument
     *         could not be statically resolved. intermediateModel is non-null only for
     *         hasOneThrough / hasManyThrough. pivotModel / accessor are non-null only when
     *         an explicit `->using(Pivot::class)` / `->as('alias')` chain mutation was
     *         detected with a statically resolvable argument. nullable is true when the body
     *         also has a null exit, so a call can return null.
     */
    public static function parse(Codebase $codebase, string $className, string $methodName, ?string $receiverClass = null): ?array
    {
        // A delegated `$this->other()` dispatches on the receiver, so one body can resolve per receiver.
        $receiverClass ??= $className;
        $cacheKey = $receiverClass . '>' . $className . '::' . $methodName;

        if (\array_key_exists($cacheKey, self::$cache)) {
            return self::$cache[$cacheKey];
        }

        if (isset(self::$resolving[$cacheKey])) {
            return null;
        }

        self::$resolving[$cacheKey] = true;
        try {
            $result = self::doParse($codebase, $className, $methodName, $receiverClass);
        } finally {
            unset(self::$resolving[$cacheKey]);
        }

        self::$cache[$cacheKey] = $result;

        return $result;
    }

    /**
     * @return ?array{relationClass: class-string<Relation>, relatedModel: ?string, intermediateModel: ?string, pivotModel: ?string, accessor: ?string, nullable: bool}
     */
    private static function doParse(Codebase $codebase, string $className, string $methodName, string $receiverClass): ?array
    {
        $methodId = MethodIdentifier::wrap($className . '::' . $methodName);
        $context = ClassMethodResolver::resolve($codebase, $methodId);
        $scopeClass = $className;

        if ($context === null) {
            // `$this->hasMany()` in a model body dispatches here too. The factories never build a
            // fixed relation, and reading them re-parses vendor HasRelationships.php (Psalm does not
            // cache vendor statements), so skip them before the trait lookup.
            if (isset(self::FACTORY_TO_RELATION[\strtolower($methodName)])) {
                return null;
            }

            // A trait method has no storage on the composing class: read the body from the trait,
            // but bind `self` to the class that composes it.
            $located = self::locateMethod($codebase, $methodId);
            if ($located === null || !$located['isTrait'] || !$located['relationTyped']) {
                return null;
            }

            $context = ClassMethodResolver::resolve($codebase, $located['declaring']);
            if ($context === null) {
                return null;
            }

            $scopeClass = $located['appearingClass'];
        }

        $stmts = $context['classMethod']->stmts;
        if ($stmts === null) {
            return null;
        }

        // Resolve the parent FQCN once so `parent::class` in factory args can be substituted
        // without re-querying class storage per occurrence. See resolveClassConstFetch() for
        // the trade-off.
        $parentClass = self::resolveParentClass($codebase, $scopeClass);

        $topLevelReturn = null;
        foreach ($stmts as $stmt) {
            if ($stmt instanceof PhpParser\Node\Stmt\Return_) {
                $topLevelReturn = $stmt;
                break;
            }
        }

        // Without a top-level return the body may fall through to an implicit null.
        if (!$topLevelReturn instanceof PhpParser\Node\Stmt\Return_) {
            return null;
        }

        $topLevel = $topLevelReturn->expr;

        $methodStorage = $context['methodStorage'];
        $result = null;
        if ($topLevel instanceof \PhpParser\Node\Expr && !self::isNullExit($topLevel)) {
            // Fail fast for the common non-relation method before walking the whole body.
            $result = self::parseReturn($codebase, $topLevel, $methodStorage, $scopeClass, $parentClass, $receiverClass);
            if ($result === null) {
                return null;
            }
        }

        // An early return (in an `if`, a loop, ...) may build another relation, so every return
        // must resolve to the same one. Null exits (`return;` / `return null;`) only make the call
        // nullable: Laravel's property access throws on them rather than yielding another type.
        $collector = new BodyReturnCollectorVisitor(bailOnBareReturn: false);
        $traverser = new PhpParser\NodeTraverser();
        $traverser->addVisitor($collector);
        $traverser->traverse($stmts);

        $nullable = $collector->hasBareReturn();

        foreach ($collector->getReturnExpressions() as $expr) {
            if (self::isNullExit($expr)) {
                $nullable = true;
                continue;
            }

            if ($expr === $topLevel) {
                continue;
            }

            $parsed = self::parseReturn($codebase, $expr, $methodStorage, $scopeClass, $parentClass, $receiverClass);
            if ($parsed === null || ($result !== null && $parsed !== $result)) {
                return null;
            }

            $result = $parsed;
        }

        if ($result === null) {
            return null;
        }

        $result['nullable'] = $nullable;

        return $result;
    }

    /**
     * @psalm-mutation-free
     */
    private static function isNullExit(PhpParser\Node\Expr $expr): bool
    {
        return $expr instanceof PhpParser\Node\Expr\ConstFetch && \strtolower($expr->name->name) === 'null';
    }

    /**
     * @return ?array{relationClass: class-string<Relation>, relatedModel: ?string, intermediateModel: ?string, pivotModel: ?string, accessor: ?string, nullable: bool}
     */
    private static function parseReturn(
        Codebase $codebase,
        PhpParser\Node\Expr $expr,
        MethodStorage $methodStorage,
        string $scopeClass,
        ?string $parentClass,
        string $receiverClass,
    ): ?array {
        $pivotModel = null;
        $accessor = null;
        $chain = [];
        $parsed = self::findRelationCallInExpr($expr, $scopeClass, $parentClass, $pivotModel, $accessor, $chain);
        if ($parsed !== null) {
            return self::applyDirectChain($codebase, $parsed, $chain, $methodStorage->return_type, $methodStorage->signature_return_type);
        }

        return self::parseDelegation(
            $codebase,
            $expr,
            $methodStorage->return_type ?? $methodStorage->signature_return_type,
            $scopeClass,
            $parentClass,
            $receiverClass,
        );
    }

    /**
     * Resolve a returned `$this->other()->chain()` to other()'s relation. Each condition proves
     * the runtime result is that relation with the same generics:
     * - the method declares exactly that relation class (`->one()` would change it);
     * - every chain call keeps the relation (`->when()` / `->getRelated()` may replace it);
     * - an outer `->using()` / `->as()` is statically resolvable; it runs after other()'s own, so it wins.
     *
     * @return ?array{relationClass: class-string<Relation>, relatedModel: ?string, intermediateModel: ?string, pivotModel: ?string, accessor: ?string, nullable: bool}
     */
    private static function parseDelegation(
        Codebase $codebase,
        PhpParser\Node\Expr $expr,
        ?Union $declaredReturnType,
        string $scopeClass,
        ?string $parentClass,
        string $receiverClass,
    ): ?array {
        $declaredClass = self::singleDeclaredClass($declaredReturnType);
        if ($declaredClass === null) {
            return null;
        }

        /** @var list<array{lowercase-string, PhpParser\Node\Expr\MethodCall}> $chain outermost call first */
        $chain = [];
        while ($expr instanceof PhpParser\Node\Expr\MethodCall && $expr->name instanceof PhpParser\Node\Identifier) {
            $name = \strtolower($expr->name->name);
            if ($expr->var instanceof PhpParser\Node\Expr\Variable && $expr->var->name === 'this') {
                $parsed = self::parseDelegatedTarget($codebase, $scopeClass, $receiverClass, $name);

                return $parsed !== null && \strcasecmp($parsed['relationClass'], $declaredClass) === 0
                    ? self::applyChain($codebase, $parsed, $chain, $scopeClass, $parentClass)
                    : null;
            }

            $chain[] = [$name, $expr];
            $expr = $expr->var;
        }

        return null;
    }

    /**
     * @param array{relationClass: class-string<Relation>, relatedModel: ?string, intermediateModel: ?string, pivotModel: ?string, accessor: ?string, nullable: bool} $parsed
     * @param list<array{lowercase-string, PhpParser\Node\Expr\MethodCall}> $chain
     * @return ?array{relationClass: class-string<Relation>, relatedModel: ?string, intermediateModel: ?string, pivotModel: ?string, accessor: ?string, nullable: bool}
     */
    private static function applyChain(
        Codebase $codebase,
        array $parsed,
        array $chain,
        string $scopeClass,
        ?string $parentClass,
    ): ?array {
        // MorphToMany extends BelongsToMany; both carry the pivot / accessor slots.
        $pivotAware = \is_a($parsed['relationClass'], BelongsToMany::class, true);
        $pivotModel = null;
        $accessor = null;

        foreach ($chain as [$name, $call]) {
            // The outermost mutator runs last, so it wins; a dynamic argument leaves the slot unknown.
            if ($pivotAware && $name === 'using') {
                $pivotModel ??= self::firstClassStringArg($call, $scopeClass, $parentClass);
                if ($pivotModel === null) {
                    return null;
                }
            } elseif ($pivotAware && $name === 'as') {
                $accessor ??= self::firstStringLiteralArg($call);
                if ($accessor === null) {
                    return null;
                }
            } elseif (self::chainCallKeepsRelation($codebase, $parsed['relationClass'], $name) !== true) {
                return null;
            }
        }

        $parsed['pivotModel'] = $pivotModel ?? $parsed['pivotModel'];
        $parsed['accessor'] = $accessor ?? $parsed['accessor'];

        return $parsed;
    }

    /**
     * Resolve `$this->$methodName()` as PHP dispatches it: a private method of the calling scope
     * (the class declaring or composing the body) binds lexically; anything else dispatches on the
     * receiver, so a child override wins.
     *
     * @return ?array{relationClass: class-string<Relation>, relatedModel: ?string, intermediateModel: ?string, pivotModel: ?string, accessor: ?string, nullable: bool}
     */
    private static function parseDelegatedTarget(
        Codebase $codebase,
        string $scopeClass,
        string $receiverClass,
        string $methodName,
    ): ?array {
        $target = self::locateMethod($codebase, MethodIdentifier::wrap($scopeClass . '::' . $methodName));
        if ($target === null || !$target['private'] || \strcasecmp($target['appearingClass'], $scopeClass) !== 0) {
            $target = self::locateMethod($codebase, MethodIdentifier::wrap($receiverClass . '::' . $methodName));
            // Someone else's private method is not callable from this scope.
            if ($target === null || $target['private']) {
                return null;
            }
        }

        if (!$target['relationTyped']) {
            return null;
        }

        // A trait body parses through its composing class so `self` binds there.
        $bodyClass = $target['isTrait'] ? $target['appearingClass'] : $target['declaring']->fq_class_name;

        // A nullable target would leave the delegating chain calling into null.
        $parsed = self::parse($codebase, $bodyClass, $methodName, $receiverClass);

        return $parsed !== null && !$parsed['nullable'] ? $parsed : null;
    }

    /**
     * Whether a chain call returns the relation itself: true when the method Laravel dispatches
     * returns just `$this` / `static` (a real method on the relation, else what Relation::__call
     * forwards to: Eloquent builder, then query builder), false when it returns something else.
     * null when no such method resolves (scopes, macros, custom builder methods).
     *
     * @param class-string<Relation> $relationClass
     */
    private static function chainCallKeepsRelation(Codebase $codebase, string $relationClass, string $methodName): ?bool
    {
        // The Conditionable stub types these `$this` to keep chains, but they return the
        // callback's result whenever it is not null.
        if ($methodName === 'when' || $methodName === 'unless') {
            return false;
        }

        $onRelation = ReturnTypeResolver::declaredMethodReturnsOnlySelf($codebase, $relationClass, $methodName, false);
        if ($onRelation !== null) {
            return $onRelation;
        }

        $onEloquent = ReturnTypeResolver::declaredMethodReturnsOnlySelf($codebase, EloquentBuilder::class, $methodName, true);
        if ($onEloquent === null && ReturnTypeResolver::declaredMethodReturnsOnlySelf($codebase, QueryBuilder::class, $methodName, true) === null) {
            return null;
        }

        // Relation::forwardDecoratedCallTo() maps only the query itself back to the relation, so a
        // method returning a clone escapes it. Eloquent\Builder::__call() returns the base query's
        // result for its passthru methods and discards it (returning itself) for the rest, so a
        // method only the base query declares keeps the relation whatever it declares to return.
        if (\in_array($methodName, self::NEW_INSTANCE_METHODS, true) || self::isPassthru($methodName)) {
            return false;
        }

        return $onEloquent ?? true;
    }

    /**
     * Read from the running Laravel rather than copied: the list grows between releases.
     */
    private static function isPassthru(string $lowerMethodName): bool
    {
        self::$passthru ??= \array_values((array) (new \ReflectionProperty(EloquentBuilder::class, 'passthru'))->getDefaultValue());

        return \in_array($lowerMethodName, self::$passthru, true);
    }

    /**
     * Follow a direct factory chain (innermost call first) to the relation class it produces: `one()`
     * converts it, a call that provably returns something else declines, and a call that does not
     * resolve (scope / macro via `__call`) is accepted as keeping the relation. A declaration none
     * of whose alternatives admits the result also declines.
     *
     * @param array{relationClass: class-string<Relation>, relatedModel: ?string, intermediateModel: ?string, pivotModel: ?string, accessor: ?string, nullable: bool} $parsed
     * @param list<lowercase-string> $chain outermost call first
     * @return ?array{relationClass: class-string<Relation>, relatedModel: ?string, intermediateModel: ?string, pivotModel: ?string, accessor: ?string, nullable: bool}
     */
    private static function applyDirectChain(Codebase $codebase, array $parsed, array $chain, ?Union $docblockType, ?Union $nativeType): ?array
    {
        foreach (\array_reverse($chain) as $name) {
            $sibling = $name === 'one' ? self::singleResultSibling($parsed['relationClass']) : null;
            if ($sibling !== null) {
                $parsed['relationClass'] = $sibling;
            } elseif (self::chainCallKeepsRelation($codebase, $parsed['relationClass'], $name) === false) {
                return null;
            }
        }

        return self::declarationAdmits($docblockType, $parsed['relationClass']) && self::declarationAdmits($nativeType, $parsed['relationClass']) ? $parsed : null;
    }

    /**
     * Whether the declared return type leaves room for $relationClass: no declaration, `mixed` /
     * `object`, a template whose bound leaves room, or a plain class it is / extends (`null` aside).
     * Scalars and intersections never admit it.
     *
     * @psalm-capabilities read-props
     */
    private static function declarationAdmits(?Union $declared, string $relationClass): bool
    {
        if (!$declared instanceof Union) {
            return true;
        }

        foreach ($declared->getAtomicTypes() as $atomic) {
            if ($atomic instanceof TNull) {
                continue;
            }

            if ($atomic instanceof TMixed || $atomic::class === TObject::class) {
                return true;
            }

            if ($atomic instanceof TTemplateParam && !$atomic->extra_types && self::declarationAdmits($atomic->as, $relationClass)) {
                return true;
            }

            if ($atomic instanceof TNamedObject && !$atomic->extra_types) {
                /** @psalm-var class-string $declaredClass */
                $declaredClass = $atomic->value;
                if (\is_a($relationClass, $declaredClass, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * What `->one()` converts a *-many relation to; related / intermediate / declaring models carry over.
     *
     * @psalm-pure
     * @return ?class-string<Relation>
     */
    private static function singleResultSibling(string $relationClass): ?string
    {
        return match ($relationClass) {
            HasMany::class => HasOne::class,
            MorphMany::class => MorphOne::class,
            HasManyThrough::class => HasOneThrough::class,
            default => null,
        };
    }

    /**
     * The class named by the declared return type when it is a single class (`null` aside).
     *
     * @psalm-mutation-free
     */
    private static function singleDeclaredClass(?Union $declared): ?string
    {
        if (!$declared instanceof Union) {
            return null;
        }

        $class = null;
        foreach ($declared->getAtomicTypes() as $atomic) {
            if ($atomic instanceof TNull) {
                continue;
            }

            if (!$atomic instanceof TNamedObject || $class !== null) {
                return null;
            }

            $class = $atomic->value;
        }

        return $class;
    }

    /**
     * Where $methodId's body lives (own, inherited or trait-hosted) and the class it appears in, or
     * null when that is unknown. `relationTyped` is false for a non-relation return type, so callers
     * decline before loading the AST: Psalm re-parses vendor files on every statements lookup.
     *
     * @return ?array{declaring: MethodIdentifier, appearingClass: string, isTrait: bool, private: bool, relationTyped: bool}
     * @psalm-capabilities read-props
     */
    private static function locateMethod(Codebase $codebase, MethodIdentifier $methodId): ?array
    {
        try {
            $declaring = $codebase->methods->getDeclaringMethodId($methodId);
            $appearing = $codebase->methods->getAppearingMethodId($methodId);
            if (!$declaring instanceof MethodIdentifier || !$appearing instanceof MethodIdentifier) {
                return null;
            }

            $storage = $codebase->methods->getStorage($declaring);
            $appearingStorage = $codebase->classlike_storage_provider->get($appearing->fq_class_name);
            $isTrait = $codebase->classlike_storage_provider->get($declaring->fq_class_name)->is_trait;

            // Psalm records the declaring trait method ignoring `insteadof` (vimeo/psalm#12113:
            // ClassLikeNodeScanner::handleTraitUse() applies only `as` adaptations), so it may name the
            // losing body. Decline when two composed traits, at any nesting depth, declare it.
            if ($isTrait && self::countTraitOwners($codebase, $appearingStorage->used_traits, $declaring->method_name) > 1) {
                return null;
            }

            $returnType = $storage->return_type ?? $storage->signature_return_type;
            $visibility = $appearingStorage->trait_visibility_map[$methodId->method_name] ?? $storage->visibility;

            return [
                'declaring' => $declaring,
                'appearingClass' => $appearing->fq_class_name,
                'isTrait' => $isTrait,
                'private' => $visibility === ClassLikeAnalyzer::VISIBILITY_PRIVATE,
                'relationTyped' => !$returnType instanceof Union || self::declaresRelation($returnType),
            ];
        } catch (\InvalidArgumentException|\UnexpectedValueException|UnpopulatedClasslikeException) {
            return null;
        }
    }

    /**
     * How many traits reached from $usedTraits declare $methodName in their own body. A trait's
     * own method overrides the ones it composes, so the walk stops there; a trait reached twice
     * counts once.
     *
     * @param array<array-key, string> $usedTraits
     * @param lowercase-string $methodName
     * @psalm-capabilities read-props
     */
    private static function countTraitOwners(Codebase $codebase, array $usedTraits, string $methodName): int
    {
        $owners = 0;
        $visited = [];
        $queue = \array_values($usedTraits);
        while (($traitName = \array_pop($queue)) !== null) {
            $key = \strtolower($traitName);
            if (isset($visited[$key])) {
                continue;
            }

            $visited[$key] = true;
            $storage = $codebase->classlike_storage_provider->get($traitName);
            if (isset($storage->methods[$methodName])) {
                ++$owners;
            } else {
                \array_push($queue, ...\array_values($storage->used_traits));
            }
        }

        return $owners;
    }

    /**
     * @psalm-capabilities read-props
     */
    private static function declaresRelation(Union $declared): bool
    {
        foreach ($declared->getAtomicTypes() as $atomic) {
            if ($atomic instanceof TNamedObject && \is_a($atomic->value, Relation::class, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Look up the immediate parent FQCN of `$className`, or null when the class has no
     * parent (or storage isn't available). Used to substitute `parent::class` in relation
     * factory arguments. The InvalidArgumentException catch mirrors the surrounding
     * fail-soft style — a missing parent yields null and the handler defers, rather than
     * crashing the analysis run.
     */
    private static function resolveParentClass(Codebase $codebase, string $className): ?string
    {
        try {
            $storage = $codebase->classlike_storage_provider->get($className);
        } catch (\InvalidArgumentException $invalidArgumentException) {
            $codebase->progress->debug(
                "Laravel plugin: could not resolve parent class for {$className}: {$invalidArgumentException->getMessage()}\n",
            );
            return null;
        }

        return $storage->parent_class;
    }

    /**
     * Recursively search an expression for a relationship factory method call.
     * Handles both direct calls and method chains. While unwrapping the chain on the way
     * to the inner factory call, also collect any `->using(Pivot::class)` and
     * `->as('accessor')` mutations — these rebind TPivotModel / TAccessor on
     * BelongsToMany and MorphToMany — and record every call's name in $chain for
     * applyDirectChain(). A call with a dynamic method name makes the chain unprovable.
     *
     * @param ?string $pivotModel out-parameter: FQCN captured from `->using(...)` if found
     * @param ?string $accessor   out-parameter: literal string captured from `->as(...)` if found
     * @param list<lowercase-string> $chain out-parameter: names of the calls above the factory, outermost first
     * @return ?array{relationClass: class-string<Relation>, relatedModel: ?string, intermediateModel: ?string, pivotModel: ?string, accessor: ?string, nullable: bool}
     */
    private static function findRelationCallInExpr(
        PhpParser\Node\Expr $expr,
        string $declaringClass,
        ?string $parentClass,
        ?string &$pivotModel,
        ?string &$accessor,
        array &$chain,
    ): ?array {
        if (!$expr instanceof PhpParser\Node\Expr\MethodCall) {
            return null;
        }

        // A dynamic name (`->{'one'}()`) could be any method, including a class-changing one.
        if (!$expr->name instanceof PhpParser\Node\Identifier) {
            return null;
        }

        // Check if this is a relationship factory call (e.g. $this->belongsTo(...))
        $lowerName = \strtolower($expr->name->name);
        $relationClass = self::FACTORY_TO_RELATION[$lowerName] ?? null;

        if ($relationClass !== null) {
            return [
                'relationClass' => $relationClass,
                'relatedModel' => self::extractClassStringArg($expr, $lowerName, 0, 'related', $declaringClass, $parentClass),
                // Through relations carry the intermediate model as the second class-string
                // argument: hasOneThrough(Related, Intermediate) / hasManyThrough(Related, Intermediate).
                // Every other factory leaves this null.
                'intermediateModel' => \in_array($lowerName, ['hasonethrough', 'hasmanythrough'], true)
                    ? self::extractClassStringArg($expr, $lowerName, 1, 'through', $declaringClass, $parentClass)
                    : null,
                'pivotModel' => $pivotModel,
                'accessor' => $accessor,
                'nullable' => false,
            ];
        }

        // Pivot / accessor mutators: capture the first statically resolvable argument.
        // Outside-in recursion visits the outermost call first, so the outermost
        // `using()` / `as()` wins via `??=` — inner ones cannot overwrite once set.
        if ($lowerName === 'using') {
            $pivotModel ??= self::firstClassStringArg($expr, $declaringClass, $parentClass);
        } elseif ($lowerName === 'as') {
            $accessor ??= self::firstStringLiteralArg($expr);
        }

        $chain[] = $lowerName;

        // Not a relationship call — try the inner expression (unwrap chain).
        // e.g. for $this->belongsTo(X::class)->withDefault(), $expr->var is $this->belongsTo(X::class)
        return self::findRelationCallInExpr($expr->var, $declaringClass, $parentClass, $pivotModel, $accessor, $chain);
    }

    /**
     * Resolve the first argument of `->using(Pivot::class)` to its FQCN, or null when the
     * argument is dynamic (a variable, a method call, etc.). The first physical arg is
     * read regardless of whether it carries a name token — `using()` is a single-parameter
     * method, so named (`->using(class: P::class)`) and positional (`->using(P::class)`)
     * forms are both honored.
     */
    private static function firstClassStringArg(PhpParser\Node\Expr\MethodCall $call, string $declaringClass, ?string $parentClass): ?string
    {
        $first = $call->args[0] ?? null;
        if (!$first instanceof PhpParser\Node\Arg) {
            return null;
        }

        return self::resolveClassConstFetch($first->value, $declaringClass, $parentClass);
    }

    /**
     * Resolve the first argument of `->as('accessor')` to its literal string value, or null
     * when the argument is dynamic. Only literal scalars produce a usable TAccessor binding —
     * variables and concatenation cannot be statically pinned.
     *
     * @psalm-mutation-free
     */
    private static function firstStringLiteralArg(PhpParser\Node\Expr\MethodCall $call): ?string
    {
        $first = $call->args[0] ?? null;
        if (!$first instanceof PhpParser\Node\Arg) {
            return null;
        }

        $value = $first->value;
        if ($value instanceof PhpParser\Node\Scalar\String_) {
            return $value->value;
        }

        return null;
    }

    /**
     * Extract the class-string argument at the given position from a relationship factory call.
     *
     * Resolves named arguments (`hasManyThrough(through: Vehicle::class, related: WorkOrder::class)`)
     * by `$paramName` first, then falls back to positional lookup at `$positionalIndex` when the
     * call uses bare positional args. The two arg-naming worlds are kept separate: a positional
     * lookup ignores any arg that carries a `name` token, since named args may have shifted the
     * positions and indexing into them would mis-identify the intended arg.
     *
     * morphTo() is special: it may have no arguments (Laravel infers the type from the method name),
     * or it may have string arguments rather than a class-string. Returns null for morphTo()
     * since the related model type is polymorphic and not statically determinable.
     *
     * $positionalIndex=0 / $paramName='related' captures the related model. $positionalIndex=1 /
     * $paramName='through' captures the intermediate model on through relations.
     */
    private static function extractClassStringArg(
        PhpParser\Node\Expr\MethodCall $call,
        string $lowerMethodName,
        int $positionalIndex,
        string $paramName,
        string $declaringClass,
        ?string $parentClass,
    ): ?string {
        // morphTo() doesn't take a class-string<Model> as first arg — the related type is polymorphic
        if ($lowerMethodName === 'morphto') {
            return null;
        }

        $args = $call->args;

        // Named-arg form: search by param name regardless of position.
        foreach ($args as $arg) {
            if (
                $arg instanceof PhpParser\Node\Arg
                && $arg->name instanceof PhpParser\Node\Identifier
                && $arg->name->name === $paramName
            ) {
                return self::resolveClassConstFetch($arg->value, $declaringClass, $parentClass);
            }
        }

        // Positional form: only valid when the arg at $positionalIndex is itself positional
        // (named args may have shifted positions, so a positional lookup is unreliable).
        if (
            !isset($args[$positionalIndex])
            || !$args[$positionalIndex] instanceof PhpParser\Node\Arg
            || $args[$positionalIndex]->name instanceof \PhpParser\Node\Identifier
        ) {
            return null;
        }

        return self::resolveClassConstFetch($args[$positionalIndex]->value, $declaringClass, $parentClass);
    }

    /**
     * Resolve a `ClassName::class` expression to the resolved FQCN, or null when the
     * expression is anything else (a variable, a method call, a string literal, etc.).
     *
     * Handles the `self` / `static` / `parent` keywords specifically. PhpParser's
     * `NameResolver` deliberately leaves them unresolved (`resolvedName` attribute is
     * null) because they are context-sensitive — without substituting them here,
     * `toString()` would return the literal keyword and the parser would emit, e.g.,
     * `HasMany<self, User>` instead of `HasMany<User, User>` (#879).
     *
     * Substitution rules:
     * - `self::class` → `$declaringClass` (always correct: PHP resolves `self` to the
     *   class where the method body is declared, or to the class composing the trait).
     * - `static::class` → `$declaringClass` (conservative). Late static binding would
     *   resolve this to the receiver's runtime class, but the parser is keyed by the
     *   declaring class only, so we cannot vary the result per binding without
     *   defeating the cache. Yields a strictly better answer than leaking `'static'`.
     * - `parent::class` → `$parentClass` (or null when the declaring class has no
     *   parent — same path as a dynamic arg, which makes the upstream handler defer).
     *   When the declaring class extends `Illuminate\Database\Eloquent\Model` directly,
     *   `parent::class` legitimately resolves to that abstract base — that matches PHP's
     *   runtime semantics; the resulting `Relation<Model, Self>` is well-formed but
     *   semantically thin. Filtering Model out here would break faithfulness to the
     *   user's source.
     */
    private static function resolveClassConstFetch(PhpParser\Node\Expr $expr, string $declaringClass, ?string $parentClass): ?string
    {
        if (
            !$expr instanceof PhpParser\Node\Expr\ClassConstFetch
            || !$expr->class instanceof PhpParser\Node\Name
            || !$expr->name instanceof PhpParser\Node\Identifier
            || $expr->name->name !== 'class'
        ) {
            return null;
        }

        if ($expr->class->isSpecialClassName()) {
            return match ($expr->class->toLowerString()) {
                'self', 'static' => $declaringClass,
                'parent' => $parentClass,
                default => null,
            };
        }

        // Prefer the FQCN resolved by Psalm's name-resolution pass
        /** @var string|null $resolved */
        $resolved = $expr->class->getAttribute('resolvedName');
        if (\is_string($resolved)) {
            return $resolved;
        }

        return $expr->class->toString();
    }

    /**
     * TRelatedModel of a declared `MorphTo<X, …>` return type (method storage, docblock merged), when
     * every alternative of X is a concrete model class or an intersection with one (`Model&Contract`).
     *
     * Only slot 1 is read: slot 2 can still hold an unresolved `self` (trait methods resolve it when
     * composed) or `static`, so callers bind the declaring model from the call receiver. Declines a
     * nullable or union return, a MorphTo subclass, and a template, `static`, or non-model X.
     *
     * @psalm-mutation-free
     */
    public static function declaredMorphToRelatedModelType(Codebase $codebase, ?Union $declaredReturn): ?Union
    {
        if (!$declaredReturn instanceof Union || !$declaredReturn->isSingle()) {
            return null;
        }

        $relation = $declaredReturn->getSingleAtomic();
        if (
            !$relation instanceof TGenericObject
            || \strtolower($relation->value) !== \strtolower(MorphTo::class)
            || !isset($relation->type_params[0])
        ) {
            return null;
        }

        $related = $relation->type_params[0];
        foreach ($related->getAtomicTypes() as $atomic) {
            if (!$atomic instanceof TNamedObject) {
                return null;
            }

            $isModel = false;
            foreach ([$atomic, ...$atomic->extra_types] as $part) {
                $isModel = $isModel || ($part instanceof TNamedObject && ClassLineage::isA($codebase, $part->value, Model::class));
            }

            if (!$isModel) {
                return null;
            }
        }

        return $related;
    }

    /**
     * Extract TRelatedModel from the method's docblock generic return type annotation.
     *
     * Used for morphTo relations where the related model can't be determined from the
     * factory call arguments but may be annotated via @return MorphTo<User|Post, $this>.
     *
     * When Psalm resolves $this in generic params, it may collapse the type to a
     * non-generic TNamedObject, losing the generic info. This method reads the raw
     * docblock to recover it.
     *
     * @return ?Union The related model type (e.g. User|Post), or null if not annotated
     */
    public static function extractDocblockRelatedModelType(Codebase $codebase, string $className, string $methodName): ?Union
    {
        $context = ClassMethodResolver::resolve($codebase, MethodIdentifier::wrap($className . '::' . $methodName));
        if ($context === null) {
            return null;
        }

        $docComment = $context['classMethod']->getDocComment();
        if (!$docComment instanceof \PhpParser\Comment\Doc) {
            return null;
        }

        // Extract the first generic param from @psalm-return (preferred), @phpstan-return, or @return
        $firstParam = self::extractFirstGenericParam($docComment->getText());
        if ($firstParam === null) {
            return null;
        }

        // Resolve short class names against the file's use statements
        $useMap = self::buildUseMap($context['fileStmts']);
        $namespace = self::extractNamespace($className);

        return self::resolveTypeNames($firstParam, $useMap, $namespace);
    }

    /**
     * Extract the first generic type parameter from a docblock @return annotation.
     *
     * Checks @psalm-return, @phpstan-return, then @return (matching Psalm's priority).
     * e.g. "@psalm-return MorphTo<User|Post, $this>" → "User|Post"
     *
     * @psalm-pure
     */
    private static function extractFirstGenericParam(string $docblock): ?string
    {
        // Psalm's priority: @psalm-return > @phpstan-return > @return
        foreach (['@psalm-return', '@phpstan-return', '@return'] as $tag) {
            if (\preg_match('/' . $tag . '\s+\S+<([^,>]+)/', $docblock, $matches)) {
                return \trim($matches[1]);
            }
        }

        return null;
    }

    /**
     * Build a map of short class name → FQCN from use statements in the file AST.
     *
     * Handles both regular use statements and group use statements (use App\Models\{User, Post}).
     *
     * @param list<PhpParser\Node\Stmt> $stmts
     * @return array<string, string> alias → FQCN
     */
    private static function buildUseMap(array $stmts): array
    {
        $map = [];

        foreach ($stmts as $stmt) {
            self::collectUseStatements($stmt, $map);

            // Also check inside namespace blocks
            if ($stmt instanceof PhpParser\Node\Stmt\Namespace_) {
                foreach ($stmt->stmts as $nsStmt) {
                    self::collectUseStatements($nsStmt, $map);
                }
            }
        }

        return $map;
    }

    /**
     * Collect use and group-use statements into the alias map.
     *
     * @param array<string, string> $map
     */
    private static function collectUseStatements(PhpParser\Node\Stmt $stmt, array &$map): void
    {
        // Only collect class imports (TYPE_NORMAL), skip use function/use const
        if ($stmt instanceof PhpParser\Node\Stmt\Use_ && $stmt->type === PhpParser\Node\Stmt\Use_::TYPE_NORMAL) {
            foreach ($stmt->uses as $use) {
                $alias = $use->alias?->toString() ?? $use->name->getLast();
                $map[$alias] = $use->name->toString();
            }
        } elseif ($stmt instanceof PhpParser\Node\Stmt\GroupUse) {
            $prefix = $stmt->prefix->toString();
            foreach ($stmt->uses as $use) {
                // Combine statement-level and item-level type via bitwise OR, matching
                // PhpParser's NameResolver. For "use App\Models\{User}" the GroupUse
                // has TYPE_UNKNOWN and items have TYPE_NORMAL. For "use function App\{foo}"
                // the GroupUse has TYPE_FUNCTION and items have TYPE_UNKNOWN.
                $type = $stmt->type | $use->type;
                if (
                    $type === PhpParser\Node\Stmt\Use_::TYPE_FUNCTION
                    || $type === PhpParser\Node\Stmt\Use_::TYPE_CONSTANT
                ) {
                    continue;
                }

                $alias = $use->alias?->toString() ?? $use->name->getLast();
                $map[$alias] = $prefix . '\\' . $use->name->toString();
            }
        }
    }

    /**
     * Extract the namespace from a FQCN (everything before the last backslash).
     *
     * @psalm-pure
     */
    private static function extractNamespace(string $className): string
    {
        $lastSlash = \strrpos($className, '\\');

        return $lastSlash !== false ? \substr($className, 0, $lastSlash) : '';
    }

    /**
     * Resolve a pipe-separated type string (e.g. "User|Post") to a Psalm Union type.
     *
     * Each name is resolved against the use map, falling back to the current namespace.
     *
     * @param array<string, string> $useMap
     * @psalm-pure
     */
    private static function resolveTypeNames(string $typeString, array $useMap, string $namespace): ?Union
    {
        $names = \array_map(\trim(...), \explode('|', $typeString));
        $atomics = [];

        foreach ($names as $name) {
            // Skip non-class types ($this, static, self, scalar types)
            if ($name === '' || $name[0] === '$' || \in_array(\strtolower($name), self::NON_CLASS_TYPES, true)) {
                continue;
            }

            // Already fully qualified
            if ($name[0] === '\\') {
                $atomics[] = new TNamedObject(\ltrim($name, '\\'));
                continue;
            }

            // Check use map
            if (isset($useMap[$name])) {
                $atomics[] = new TNamedObject($useMap[$name]);
                continue;
            }

            // Fall back to current namespace
            $fqcn = $namespace !== '' ? $namespace . '\\' . $name : $name;
            $atomics[] = new TNamedObject($fqcn);
        }

        if ($atomics === []) {
            return null;
        }

        return new Union($atomics);
    }

}
