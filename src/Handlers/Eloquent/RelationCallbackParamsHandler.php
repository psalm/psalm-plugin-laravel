<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use PhpParser\Node\Arg;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use Psalm\Codebase;
use Psalm\Exception\UnpopulatedClasslikeException;
use Psalm\Internal\Analyzer\ClassLikeAnalyzer;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Internal\MethodIdentifier;
use Psalm\LaravelPlugin\Handlers\Eloquent\Support\ModelPropertyResolver;
use Psalm\LaravelPlugin\Handlers\Eloquent\Support\RelationPath;
use Psalm\LaravelPlugin\Handlers\Eloquent\Support\RelationResolver;
use Psalm\LaravelPlugin\Internal\Arg as ArgUtil;
use Psalm\LaravelPlugin\Internal\ClassLineage;
use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\BeforeExpressionAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;
use Psalm\Plugin\EventHandler\Event\BeforeExpressionAnalysisEvent;
use Psalm\Plugin\EventHandler\Event\MethodParamsProviderEvent;
use Psalm\Storage\FunctionLikeParameter;
use Psalm\Type;
use Psalm\Type\Atomic\TArray;
use Psalm\Type\Atomic\TClassString;
use Psalm\Type\Atomic\TClosure;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TKeyedArray;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TNull;
use Psalm\Type\Union;

/**
 * Types the closure-literal callback of Eloquent's relation-query methods (`whereHas()`, `has()`,
 * `whereRelation()`, `withWhereHas()`, `whereHasMorph()`, ...) as `Closure(<related builder>)`:
 * `$q` is the related model's builder (its custom builder when it has one), so `fn (VehicleBuilder $q)`
 * is accepted and `$q->whereElectric()` resolves. The stub's bare `\Closure` leaves `$q` mixed, and
 * Laravel's own docblock names `Builder<TRelatedModel>` with no custom-builder knowledge.
 *
 * Slots (Laravel's runtime calls, identical v12 to v13):
 *  - plain families: `$q` is the related builder;
 *  - `withWhereHas()`/`withWhereRelation()`: the callback also runs as the eager-load constraint, which
 *    receives the Relation, so `$q` is `Builder|Relation` and `fn (Builder $q)` is reported;
 *  - `*Morph()`: `($q, $type)` per literal type (one builder per type, `$type` the resolved class-string),
 *    after `Relation::getMorphedModel()` resolved morph-map aliases.
 *
 * Why the moving parts:
 *  - Params providers dispatch on the CALLED class and carry no receiver. Builder receivers, static
 *    `Model::whereHas()` and Relation receivers (`@mixin`) all fire `Builder::whereHas`; a custom
 *    builder fires only its own class, so one closure is registered per Builder subclass after
 *    population. Hosts are every class extending Builder.
 *  - The receiver comes from the call node: {@see beforeExpressionAnalysis()} stashes the call keyed by
 *    its first Arg (the only call-identifying object the event exposes), like
 *    {@see \Psalm\LaravelPlugin\Handlers\Support\ConditionableCallbackParamsHandler}.
 *  - Only a Closure/ArrowFunction literal gets the typed slot; a passed-through callable or first-class
 *    callable keeps the stub (it may declare other params, which Psalm would reject).
 *
 * Declines (Psalm's stub signature stands) on:
 *  - receivers: unions (a closure is re-analyzed per atomic, last one wins), a model that cannot be read from the
 *    receiver's own type params, `static`/`self`/`parent`/dynamic static classes;
 *  - call shape: unpacked args, a non-literal relation name, a `$types` list that is not made of literal model
 *    class-strings/aliases (`'*'` included);
 *  - relations: an unresolvable segment, a declared union of relations, a MorphTo on a dot path or under a plain
 *    method (and a morph method on a non-MorphTo), a parsed related model that is an ancestor of the receiver (the
 *    parser pins `static::class` to the declaring class), an eager-load slot with no parsed Relation type;
 *  - signature: a userland override of the method on the dispatched class, a trait other than Laravel's, or, for
 *    static and Relation receivers, on the model's own builder.
 *
 * Eager-load constraints (#1945, {@see getEagerLoadParams()}): a closure value of a literal `with()`/`withOnly()`/`load()`/
 * `loadMissing()` relations array is typed as the Relation its dot-path key resolves to. Receivers are a Builder (custom
 * ones included), a static `Model::with()`, a model instance and an Eloquent Collection, each exactly one known model.
 * Declines: the lists above (a MorphTo anywhere on the path included), a template-typed or intersection receiver,
 * `with('a', fn)` on a model, a model whose builder or custom collection overrides the delegated `with()`/`load()`,
 * a closure on a `:columns` key, on a repeated flattened path or in a nested `loadMissing()` array, and a computed
 * key or spread anywhere in the literal, or a related model on the path whose builder overrides `with()`. A declined
 * pass revokes earlier inference.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1676
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1945
 */
final class RelationCallbackParamsHandler implements
    AfterCodebasePopulatedInterface,
    BeforeExpressionAnalysisInterface
{
    private const PLAIN = 0;

    private const EAGER = 1;

    private const MORPH = 2;

    /**
     * Declaring classes whose signature is Laravel's own (the plugin stub and the trait it mirrors).
     *
     * @var list<lowercase-string>
     */
    private const LARAVEL_DECLARING_CLASSES = [
        'illuminate\\database\\eloquent\\builder',
        'illuminate\\database\\eloquent\\concerns\\queriesrelationships',
    ];

    /**
     * Method => [callback position, callback param name, slot kind].
     *
     * @var array<lowercase-string, array{int, string, int}>
     */
    private const SLOTS = [
        'has' => [4, 'callback', self::PLAIN],
        'doesnthave' => [2, 'callback', self::PLAIN],
        'wherehas' => [1, 'callback', self::PLAIN],
        'orwherehas' => [1, 'callback', self::PLAIN],
        'wheredoesnthave' => [1, 'callback', self::PLAIN],
        'orwheredoesnthave' => [1, 'callback', self::PLAIN],
        'whererelation' => [1, 'column', self::PLAIN],
        'orwhererelation' => [1, 'column', self::PLAIN],
        'wheredoesnthaverelation' => [1, 'column', self::PLAIN],
        'orwheredoesnthaverelation' => [1, 'column', self::PLAIN],
        'withwherehas' => [1, 'callback', self::EAGER],
        'withwhererelation' => [1, 'column', self::EAGER],
        'hasmorph' => [5, 'callback', self::MORPH],
        'doesnthavemorph' => [3, 'callback', self::MORPH],
        'wherehasmorph' => [2, 'callback', self::MORPH],
        'orwherehasmorph' => [2, 'callback', self::MORPH],
        'wheredoesnthavemorph' => [2, 'callback', self::MORPH],
        'orwheredoesnthavemorph' => [2, 'callback', self::MORPH],
    ];

    /**
     * Eager-load methods whose relations argument may carry constraint closures => the host families that declare
     * them. `with()` is a real static method on Model (so a static call dispatches on the model class) and an
     * instance method on Builder.
     *
     * @var array<lowercase-string, non-empty-list<class-string>>
     */
    private const EAGER_LOAD = [
        'with' => [Builder::class, Model::class],
        'withonly' => [Builder::class],
        'load' => [Model::class, EloquentCollection::class],
        'loadmissing' => [Model::class, EloquentCollection::class],
    ];

    /**
     * Calls awaiting their params lookup, keyed by the call's first Arg. Both ends are weak: the call owns its Arg,
     * so a strong value would keep the key alive and the entry would outlive its AST.
     *
     * @psalm-var \WeakMap<Arg, \WeakReference<MethodCall|NullsafeMethodCall|StaticCall>>|null
     */
    private static ?\WeakMap $calls = null;

    /**
     * Builder classes already registered. Psalm's MethodParamsProvider constructor clears its handlers per
     * Codebase, so this must be cleared with it ({@see reset()}) or a second Codebase gets no registration.
     *
     * @var array<lowercase-string, true>
     */
    private static array $registered = [];

    /**
     * Closure params this handler typed. The param object itself is the key, so storage Psalm replaced on a file
     * reload is not ours, and only our own write is ever revoked.
     *
     * @psalm-var \WeakMap<FunctionLikeParameter, true>|null
     */
    private static ?\WeakMap $written = null;

    public static function reset(): void
    {
        self::$calls = null;
        self::$registered = [];
        self::$written = null;
    }

    #[\Override]
    public static function afterCodebasePopulated(AfterCodebasePopulatedEvent $event): void
    {
        $codebase = $event->getCodebase();
        $builderLower = \strtolower(Builder::class);
        $modelLower = \strtolower(Model::class);
        $collectionLower = \strtolower(EloquentCollection::class);

        foreach ($codebase->classlike_storage_provider::getAll() as $storage) {
            $key = \strtolower($storage->name);

            if (isset(self::$registered[$key])) {
                continue;
            }

            $provider = match (true) {
                $key === $builderLower || isset($storage->parent_classes[$builderLower]) => self::getMethodParams(...),
                isset($storage->parent_classes[$modelLower]),
                $key === $collectionLower,
                isset($storage->parent_classes[$collectionLower]) => self::getEagerLoadParams(...),
                default => null,
            };

            if ($provider !== null) {
                self::$registered[$key] = true;
                $codebase->methods->params_provider->registerClosure($storage->name, $provider);
            }
        }
    }

    #[\Override]
    public static function beforeExpressionAnalysis(BeforeExpressionAnalysisEvent $event): ?bool
    {
        $expr = $event->getExpr();

        if (!$expr instanceof MethodCall && !$expr instanceof NullsafeMethodCall && !$expr instanceof StaticCall) {
            return null;
        }

        $args = $expr->isFirstClassCallable() ? [] : $expr->getArgs();

        if ($args !== [] && $expr->name instanceof Identifier && (isset(self::SLOTS[$expr->name->toLowerString()]) || isset(self::EAGER_LOAD[$expr->name->toLowerString()]))) {
            if (!self::$calls instanceof \WeakMap) {
                /** @psalm-var \WeakMap<Arg, \WeakReference<MethodCall|NullsafeMethodCall|StaticCall>> $fresh */
                $fresh = new \WeakMap();
                self::$calls = $fresh;
            }

            self::$calls->offsetSet($args[0], \WeakReference::create($expr));
        }

        return null;
    }

    /** @return list<FunctionLikeParameter>|null */
    public static function getMethodParams(MethodParamsProviderEvent $event): ?array
    {
        $method = $event->getMethodNameLowercase();

        if (isset(self::EAGER_LOAD[$method])) {
            return self::getEagerLoadParams($event);
        }

        $slot = self::SLOTS[$method] ?? null;

        if ($slot === null) {
            return null;
        }

        $args = $event->getCallArgs();
        if ($args === null || $args === []) {
            return null;
        }

        foreach ($args as $arg) {
            if ($arg->unpack) {
                return null;
            }
        }

        [$position, $paramName, $kind] = $slot;
        $literal = ArgUtil::boundTo($args, $paramName, $position)?->value;
        $relation = ArgUtil::boundTo($args, 'relation', 0)?->value;
        $call = (self::$calls[$args[0]] ?? null)?->get();
        $source = $event->getStatementsSource();

        if ((!$literal instanceof Closure && !$literal instanceof ArrowFunction)
            || ($literal->params[0]->variadic ?? false)
            || !$relation instanceof String_
            || $call === null
            || !$source instanceof StatementsAnalyzer
        ) {
            return null;
        }

        $morphClasses = $kind === self::MORPH ? self::morphClasses($source, ArgUtil::boundTo($args, 'types', 1)?->value) : null;
        if ($kind === self::MORPH && $morphClasses === null) {
            return null;
        }

        $dispatched = $event->getFqClasslikeName();
        $receiver = self::receiverModel($source, $call, $dispatched);
        if ($receiver === null) {
            return null;
        }

        [$model, $forwards] = $receiver;
        $codebase = $source->getCodebase();

        // Only Laravel's own signature is rewritten, see builderDeclaring().
        $declaring = self::builderDeclaring($codebase, $dispatched, $method, $model, $forwards);
        if (!$declaring instanceof MethodIdentifier) {
            return null;
        }

        $name = $method === 'withwherehas' ? \explode(':', $relation->value, 2)[0] : $relation->value;

        if ($morphClasses !== null) {
            $morphModels = self::isDirectMorphTo($codebase, $model, $name) ? self::morphModels($codebase, $morphClasses) : null;
            $slots = $morphModels === null ? null : self::morphSlots($codebase, $morphModels);
        } else {
            $resolved = self::resolveRelation($codebase, $model, $name, $kind === self::EAGER);
            $slots = $resolved === null ? null : self::relationSlots($codebase, $resolved[0], $resolved[1]);
        }

        if ($slots === null) {
            return null;
        }

        return self::withParamType($codebase, $declaring, $paramName, new Union([new TClosure(self::queryParams($slots), Type::getMixed())]));
    }

    /**
     * The Builder-family method Laravel's own signature stands behind, or null when a userland override (a builder
     * that wraps the callback, or one whose params Psalm inherits from the parent) keeps its own contract. A static
     * or relation-forwarded call dispatches through the base Builder but runs on the model's own builder, so that
     * class is checked too.
     *
     * @param class-string<Model> $model
     */
    private static function builderDeclaring(Codebase $codebase, string $dispatched, string $method, string $model, bool $forwards): ?MethodIdentifier
    {
        $declaring = self::laravelDeclaring($codebase, $dispatched, $method);

        return $declaring instanceof MethodIdentifier
            && (!$forwards || self::laravelDeclaring($codebase, ModelMethodHandler::getBuilderClassForModel($model), $method) instanceof MethodIdentifier)
            ? $declaring
            : null;
    }

    /**
     * The declaring method's params with one param's type replaced.
     *
     * @return list<FunctionLikeParameter>|null
     * @psalm-capabilities read-props
     */
    private static function withParamType(Codebase $codebase, MethodIdentifier $declaring, string $paramName, Union $type): ?array
    {
        try {
            $params = $codebase->methods->getStorage($declaring)->params;
        } catch (\UnexpectedValueException|\InvalidArgumentException) {
            return null;
        }

        $result = [];
        foreach ($params as $param) {
            $result[] = $param->name === $paramName ? $param->setType($type) : $param;
        }

        return $result;
    }

    /**
     * Eager-load calls (`with()`, `withOnly()`, `load()`, `loadMissing()`): a closure value of a literal relations
     * array gets the Relation its key resolves to as its first param. Laravel runs the constraint as
     * `$constraints($relation)` on the LAST segment of the key's dot path and flattens nested arrays into that path
     * (`prepareNestedWithRelationships()`). A `:columns` suffix only selects columns on an array-valued key or a
     * plain name; on a closure-valued key it stays in the relation name, so those closures decline.
     *
     * An untyped param is written into the closure's storage (see {@see inferClosureParam()}); the params answer
     * mirrors the literal so a declared type that is not that Relation is reported like a direct closure argument.
     * `with('relation', fn)` types the second argument as a plain slot. Declines (null) as the class docblock lists,
     * and every pass over a closure that declines or resolves differently revokes the earlier inference.
     *
     * @return list<FunctionLikeParameter>|null
     */
    public static function getEagerLoadParams(MethodParamsProviderEvent $event): ?array
    {
        $args = $event->getCallArgs();
        $source = $event->getStatementsSource();

        if ($args === null || $args === [] || !$source instanceof StatementsAnalyzer) {
            return null;
        }

        if (!isset(self::EAGER_LOAD[$event->getMethodNameLowercase()])) {
            return null;
        }

        $relations = ArgUtil::boundTo($args, 'relations', 0)?->value;
        $callback = ArgUtil::boundTo($args, 'callback', 1)?->value;
        $resolved = [];
        $result = self::eagerLoadParams($event, $args, $source, $resolved);

        $closures = $relations instanceof Array_ ? self::closureLiterals($relations) : [];
        if ($relations instanceof String_ && $event->getMethodNameLowercase() === 'with' && ($callback instanceof Closure || $callback instanceof ArrowFunction)) {
            $closures[] = $callback;
        }

        foreach ($closures as $closure) {
            self::inferClosureParam($source, $closure, $resolved[\spl_object_id($closure)] ?? null);
        }

        return $result;
    }

    /**
     * @param non-empty-list<Arg> $args
     * @param array<int, Union> $resolved closure node id => the Relation its param takes
     * @return list<FunctionLikeParameter>|null
     */
    private static function eagerLoadParams(MethodParamsProviderEvent $event, array $args, StatementsAnalyzer $source, array &$resolved): ?array
    {
        $method = $event->getMethodNameLowercase();
        $hosts = self::EAGER_LOAD[$method];

        foreach ($args as $arg) {
            if ($arg->unpack) {
                return null;
            }
        }

        $relations = ArgUtil::boundTo($args, 'relations', 0)?->value;
        $callback = $method === 'with' ? ArgUtil::boundTo($args, 'callback', 1)?->value : null;
        $callbackForm = $relations instanceof String_
            && !\str_contains($relations->value, ':')
            && ($callback instanceof Closure || $callback instanceof ArrowFunction)
            && !($callback->params[0]->variadic ?? false);
        $call = (self::$calls[$args[0]] ?? null)?->get();

        if ($call === null || !$relations instanceof Array_ && !$callbackForm) {
            return null;
        }

        $dispatched = $event->getFqClasslikeName();
        $codebase = $source->getCodebase();
        $host = null;
        foreach ($hosts as $candidate) {
            if (ClassLineage::isA($codebase, $dispatched, $candidate)) {
                $host = $candidate;
                break;
            }
        }

        // `Model::with('a', fn)` is `func_get_args()`: the closure sits under a numeric key and is never applied.
        $target = $host === null || ($callbackForm && $host !== Builder::class)
            ? null
            : self::eagerTarget($source, $call, $dispatched, $method, $host);
        if ($target === null) {
            return null;
        }

        [$model, $declaring] = $target;

        if ($relations instanceof String_ && $callback instanceof Expr) {
            $relation = self::resolveRelation($codebase, $model, $relations->value, true)[1] ?? null;
            if (!$relation instanceof TGenericObject) {
                return null;
            }

            $resolved[\spl_object_id($callback)] = new Union([$relation]);

            return self::withParamType($codebase, $declaring, 'callback', new Union([new TClosure(self::queryParams([new Union([$relation])]), Type::getMixed())]));
        }

        if (!$relations instanceof Array_) {
            return null;
        }

        $counts = [];
        self::countPaths($relations, '', $counts);
        $properties = self::walkEagerLoad($source, $model, $relations, '', $method !== 'loadmissing', $counts, $resolved);
        // A spread or computed key anywhere may hide a repeated path: no closure of the call is typed.
        if ($properties === null) {
            $resolved = [];
        }

        $shape = $properties === null ? null : self::eagerShape($properties, self::declaredElement($codebase, $declaring));

        return $shape instanceof Union ? self::withParamType($codebase, $declaring, 'relations', $shape) : null;
    }

    /**
     * The model the eager-load call runs on and Laravel's own declaration of the method (a userland override keeps
     * its own contract and declines).
     *
     * @param class-string $host the family that declares the method: Builder, Model or Eloquent Collection
     * @return array{class-string<Model>, MethodIdentifier}|null
     */
    private static function eagerTarget(StatementsAnalyzer $source, MethodCall|NullsafeMethodCall|StaticCall $call, string $dispatched, string $method, string $host): ?array
    {
        $codebase = $source->getCodebase();

        // Every entry point ends in the model's builder `with()`: that delegate must be Laravel's too.
        if ($host === Builder::class) {
            $atomic = $call instanceof StaticCall ? null : self::receiverAtomic($source, $call);
            $receiver = $atomic instanceof TNamedObject && $atomic->extra_types !== [] ? null : self::receiverModel($source, $call, $dispatched);
            if ($receiver === null) {
                return null;
            }

            [$model, $forwards] = $receiver;
            $declaring = self::builderDeclaring($codebase, $dispatched, $method, $model, $forwards);
            $delegate = $method === 'withonly' ? self::builderDeclaring($codebase, $dispatched, 'with', $model, $forwards) : $declaring;

            return $declaring instanceof MethodIdentifier && $delegate instanceof MethodIdentifier ? [$model, $declaring] : null;
        }

        $model = self::declaredReceiverModel($source, $call, $dispatched, $method, $host);
        $declaring = $model === null ? null : self::declaredBy($codebase, $dispatched, $method, $host);
        $collection = $host === Model::class ? CustomCollectionHandler::getCollectionClassForModel($model ?? '') ?? EloquentCollection::class : $dispatched;

        return $model !== null
            && $declaring instanceof MethodIdentifier
            && self::laravelDeclaring($codebase, ModelMethodHandler::getBuilderClassForModel($model), 'with') instanceof MethodIdentifier
            && ($method !== 'loadmissing' || self::collectionIsLaravels($codebase, $collection))
            ? [$model, $declaring]
            : null;
    }

    /**
     * Whether the collection class runs Laravel's own `loadMissing()` and the `load()` it delegates to.
     *
     * @psalm-capabilities read-props
     */
    private static function collectionIsLaravels(Codebase $codebase, string $class): bool
    {
        return self::declaredBy($codebase, $class, 'loadmissing', EloquentCollection::class) instanceof MethodIdentifier
            && self::declaredBy($codebase, $class, 'load', EloquentCollection::class) instanceof MethodIdentifier;
    }

    /**
     * The model of a Model or Eloquent Collection receiver that is exactly the dispatched class: a static
     * `Model::with()`, an instance `load()`/`loadMissing()`, or a Collection's `TModel`. Unions, intersections, template
     * types, `static`/`self` class names and a Collection without a known model name none.
     *
     * @param class-string $host
     * @return class-string<Model>|null
     */
    private static function declaredReceiverModel(StatementsAnalyzer $source, MethodCall|NullsafeMethodCall|StaticCall $call, string $dispatched, string $method, string $host): ?string
    {
        $codebase = $source->getCodebase();

        if ($call instanceof StaticCall) {
            $class = $call->class instanceof Name ? ClassLikeAnalyzer::getFQCLNFromNameObject($call->class, $source->getAliases()) : null;

            return $method === 'with' && $class !== null && \strcasecmp($class, $dispatched) === 0 && ClassLineage::isA($codebase, $class, Model::class)
                ? $class
                : null;
        }

        $atomic = $method === 'with' ? null : self::receiverAtomic($source, $call);
        if (!$atomic instanceof TNamedObject || $atomic->extra_types !== [] || \strcasecmp($atomic->value, $dispatched) !== 0) {
            return null;
        }

        return $host === Model::class
            ? (ClassLineage::isA($codebase, $atomic->value, Model::class) ? $atomic->value : null)
            : self::projectedModel($codebase, $atomic, EloquentCollection::class, 'TModel');
    }

    /**
     * Whether `$class::$method` is declared by `$declaringClass` itself: a userland override, or a trait that
     * supplies one, makes another class the declaring one.
     *
     * @psalm-capabilities read-props
     */
    private static function declaredBy(Codebase $codebase, string $class, string $method, string $declaringClass): ?MethodIdentifier
    {
        $declaring = $codebase->getDeclaringMethodId($class . '::' . $method);
        $declaring = $declaring === null ? null : MethodIdentifier::wrap($declaring);

        return $declaring instanceof MethodIdentifier && \strcasecmp($declaring->fq_class_name, $declaringClass) === 0 ? $declaring : null;
    }

    /**
     * Counts how often each flattened path occurs, as `prepareNestedWithRelationships()` builds them: an
     * array-valued key (a prefix, `:columns` stripped), any other string key, and a plain numeric-keyed name.
     * Laravel composes the constraints of a repeated path, so none of its closures receives the bare Relation.
     *
     * @param array<string, int> $counts
     * @psalm-capabilities read-props|write-this-props|write-refs
     */
    private static function countPaths(Array_ $array, string $prefix, array &$counts): void
    {
        foreach ($array->items as $item) {
            if (!$item instanceof ArrayItem) {
                continue;
            }

            if ($item->key instanceof String_) {
                $path = $prefix . RelationPath::withoutColumns($item->key->value);
            } elseif ((!$item->key instanceof \PhpParser\Node\Expr || $item->key instanceof Int_) && $item->value instanceof String_) {
                $path = $prefix . RelationPath::withoutColumns($item->value->value);
            } else {
                continue;
            }

            $counts[$path] = ($counts[$path] ?? 0) + 1;

            if ($item->key instanceof String_ && $item->value instanceof Array_) {
                self::countPaths($item->value, $path . '.', $counts);
            }
        }
    }

    /**
     * Resolves the closures of an eager-load array literal as Laravel flattens it into `$resolved`, and collects the
     * param shape that mirrors them: closure-valued literal keys that resolved to a Relation.
     *
     * Declines a closure (it stays out of `$resolved` and the shape) on a `:columns` key, a repeated path, a nested
     * array where `$allowNested` is false, a path through an overriding builder, or a path that does not resolve to a Relation.
     *
     * @param class-string<Model> $model
     * @param array<string, int> $counts
     * @param array<int, Union> $resolved
     * @return array<string, Union>|null null when an entry is not a plain literal (a spread, a computed key): the
     *         shape would reject what the literal really holds, or hide a repeated path: the call types no closure.
     */
    private static function walkEagerLoad(StatementsAnalyzer $source, string $model, Array_ $array, string $prefix, bool $allowNested, array $counts, array &$resolved): ?array
    {
        $properties = [];
        $clean = true;

        foreach ($array->items as $item) {
            if (!$item instanceof ArrayItem || $item->unpack || $item->byRef) {
                $clean = false;
            } elseif ($item->key instanceof String_) {
                $key = $item->key->value;
                $path = $prefix . RelationPath::withoutColumns($key);
                $value = $item->value;

                if ($value instanceof Array_) {
                    $nested = $allowNested || $prefix === '' ? self::walkEagerLoad($source, $model, $value, $path . '.', $allowNested, $counts, $resolved) : [];
                    $shape = $nested === null ? null : self::eagerShape($nested, Type::getMixed());
                    $clean = $clean && $nested !== null;
                    if ($shape instanceof Union) {
                        $properties[$key] = $shape;
                    }
                } elseif ($value instanceof Closure || $value instanceof ArrowFunction) {
                    $relation = !\str_contains($key, ':') && ($counts[$path] ?? 0) === 1 && ($allowNested || $prefix === '') && self::pathBuildersAreLaravels($source->getCodebase(), $model, $path)
                        ? self::resolveRelation($source->getCodebase(), $model, $path, true)[1] ?? null
                        : null;

                    if ($relation instanceof TGenericObject) {
                        $relationType = new Union([$relation]);
                        $resolved[\spl_object_id($value)] = $relationType;
                        $properties[$key] = new Union([new TClosure(self::queryParams([$relationType]), Type::getMixed())]);
                    }
                }
            } elseif ($item->key instanceof Expr && !$item->key instanceof Int_) {
                $clean = false;
            }
        }

        return $clean ? $properties : null;
    }

    /**
     * The element type the declared `$relations` param gives an array entry (mixed when it is not an array type):
     * entries the literal does not mirror keep exactly that.
     *
     * @psalm-capabilities read-props
     */
    private static function declaredElement(Codebase $codebase, MethodIdentifier $declaring): Union
    {
        try {
            $params = $codebase->methods->getStorage($declaring)->params;
        } catch (\UnexpectedValueException|\InvalidArgumentException) {
            return Type::getMixed();
        }

        foreach ($params as $param) {
            foreach ($param->name === 'relations' && $param->type instanceof Union ? $param->type->getAtomicTypes() : [] as $atomic) {
                if ($atomic instanceof TArray) {
                    return $atomic->type_params[1];
                }
            }
        }

        return Type::getMixed();
    }

    /**
     * `array{key?: Closure(Relation): mixed, ...<array-key, $element>}`: the mirrored entries optional (a
     * spread-free literal always has them). Null: nothing resolved, so there is nothing to check beyond the stub.
     *
     * @param array<string, Union> $properties
     * @psalm-capabilities read-props
     */
    private static function eagerShape(array $properties, Union $element): ?Union
    {
        if ($properties === []) {
            return null;
        }

        return new Union([TKeyedArray::make(
            \array_map(static fn(Union $property): Union => $property->setPossiblyUndefined(true), $properties),
            null,
            [Type::getArrayKey(), $element],
        )]);
    }

    /**
     * The closure literals anywhere in a relations array, nested arrays included.
     *
     * @return list<Closure|ArrowFunction>
     * @psalm-capabilities read-props
     */
    private static function closureLiterals(Array_ $array): array
    {
        $closures = [];

        foreach ($array->items as $item) {
            if (!$item instanceof ArrayItem) {
                continue;
            }

            if ($item->value instanceof Closure || $item->value instanceof ArrowFunction) {
                $closures[] = $item->value;
            } elseif ($item->value instanceof Array_) {
                \array_push($closures, ...self::closureLiterals($item->value));
            }
        }

        return $closures;
    }

    /**
     * Psalm infers an untyped closure param from the expected type only for a closure that is itself the argument
     * (`ArgumentsAnalyzer::handleClosureArg()`); inside an array literal it stays mixed whatever shape the params
     * answer expects (probed, decisions.md). So the same write happens here: type and `type_inferred` on the
     * closure's storage, before the closure is analyzed.
     *
     * The storage outlives a pass (Psalm re-analyzes loops and branches, and a trait body once per consuming class),
     * so every pass restates the closure's Relation: it writes the pass's type, or revokes this handler's earlier
     * write when the pass declines. A type the handler did not write is never touched.
     */
    private static function inferClosureParam(StatementsAnalyzer $source, Closure|ArrowFunction $closure, ?Union $type): void
    {
        $closureId = \strtolower($source->getFilePath()) . ':' . $closure->getLine() . ':' . (int) $closure->getAttribute('startFilePos') . ':-:closure';

        try {
            $param = $source->getCodebase()->getClosureStorage($source->getFilePath(), $closureId)->params[0] ?? null;
        } catch (\UnexpectedValueException) {
            return;
        }

        if (!$param instanceof FunctionLikeParameter || $param->is_variadic) {
            return;
        }

        if (!self::$written instanceof \WeakMap) {
            /** @psalm-var \WeakMap<FunctionLikeParameter, true> $fresh */
            $fresh = new \WeakMap();
            self::$written = $fresh;
        }

        $written = self::$written;
        $ours = isset($written[$param]);

        if ($type instanceof Union && ($ours || !$param->type instanceof Union)) {
            $param->type = $type;
            $param->type_inferred = true;
            $written[$param] = true;
        } elseif ($ours) {
            $param->type = null;
            $param->type_inferred = false;
            unset($written[$param]);
        }
    }

    /**
     * Whether every related model a dot path passes through runs its nested constraints through Laravel's own builder
     * `with()`: Laravel hands a nested constraint to the related model's builder, so a userland override there keeps
     * its own contract.
     *
     * @param class-string<Model> $model
     */
    private static function pathBuildersAreLaravels(Codebase $codebase, string $model, string $path): bool
    {
        $segments = \explode('.', $path);
        \array_pop($segments);
        $prefix = '';

        foreach ($segments as $segment) {
            $prefix .= ($prefix === '' ? '' : '.') . $segment;
            $related = self::resolveRelation($codebase, $model, $prefix, false)[0] ?? null;

            if ($related === null || !self::laravelDeclaring($codebase, ModelMethodHandler::getBuilderClassForModel($related), 'with') instanceof MethodIdentifier) {
                return false;
            }
        }

        return true;
    }

    /**
     * The declaring method of `$class::$method` when it is Laravel's own: declared by Eloquent's Builder or its
     * `QueriesRelationships` trait, and not replaced by another trait. Psalm ignores `insteadof`
     * (vimeo/psalm#12113), so such an override still reports Laravel's trait as the declaring one. Unknown
     * classes decline.
     *
     * @psalm-capabilities read-props
     */
    private static function laravelDeclaring(Codebase $codebase, string $class, string $method): ?MethodIdentifier
    {
        $declaring = $codebase->getDeclaringMethodId($class . '::' . $method);
        $declaring = $declaring === null ? null : MethodIdentifier::wrap($declaring);

        return $declaring instanceof MethodIdentifier
            && \in_array(\strtolower($declaring->fq_class_name), self::LARAVEL_DECLARING_CLASSES, true)
            && !self::traitOverrides($codebase, $class, $method)
            ? $declaring
            : null;
    }

    /**
     * The model the receiver's relations belong to, and whether the call forwards through that model's own builder
     * (a static call, or a Relation) rather than running on the receiver itself: a single-atomic Builder of exactly
     * the dispatched class, a Relation (it forwards to `Builder`) via its TRelatedModel, or the named model of a
     * static call.
     *
     * @return array{class-string<Model>, bool}|null
     */
    private static function receiverModel(
        StatementsAnalyzer $source,
        MethodCall|NullsafeMethodCall|StaticCall $call,
        string $dispatched,
    ): ?array {
        $codebase = $source->getCodebase();
        $isBuilderDispatch = \strtolower($dispatched) === \strtolower(Builder::class);

        if ($call instanceof StaticCall) {
            $class = $call->class instanceof Name
                ? ClassLikeAnalyzer::getFQCLNFromNameObject($call->class, $source->getAliases())
                : null;

            return $isBuilderDispatch
                && $class !== null
                && ClassLineage::isA($codebase, $class, Model::class)
                ? [$class, true]
                : null;
        }

        $atomic = self::receiverAtomic($source, $call);
        if (!$atomic instanceof TNamedObject) {
            return null;
        }

        $forwards = ClassLineage::isA($codebase, $atomic->value, Relation::class);
        if (!$forwards && \strtolower($atomic->value) !== \strtolower($dispatched)) {
            return null;
        }

        $model = $forwards
            ? ($isBuilderDispatch ? self::projectedModel($codebase, $atomic, Relation::class, 'TRelatedModel') : null)
            : self::projectedModel($codebase, $atomic, Builder::class, 'TModel');

        return $model === null ? null : [$model, $forwards];
    }

    /** The receiver's one non-null atomic when it is a named object; unions of classes are not narrowed. */
    private static function receiverAtomic(StatementsAnalyzer $source, MethodCall|NullsafeMethodCall $call): ?TNamedObject
    {
        $receiver = $source->getNodeTypeProvider()->getType($call->var);
        $atomics = $receiver instanceof Union
            ? \array_values(\array_filter($receiver->getAtomicTypes(), static fn($atomic): bool => !$atomic instanceof TNull))
            : [];

        return \count($atomics) === 1 && $atomics[0] instanceof TNamedObject ? $atomics[0] : null;
    }

    /**
     * The model a receiver binds an ancestor's template to (`Builder::TModel`, `Relation::TRelatedModel`), see
     * {@see ClassLineage::projectedTemplate()}. Anything that is not exactly one model names no model.
     *
     * @param class-string $ancestor
     * @return class-string<Model>|null
     * @psalm-capabilities read-props
     */
    private static function projectedModel(Codebase $codebase, TNamedObject $atomic, string $ancestor, string $template): ?string
    {
        return ModelPropertyResolver::extractExactlyOneModelFromUnion(
            ClassLineage::projectedTemplate($codebase, $atomic, $ancestor, $template),
            $codebase,
        );
    }

    /**
     * Whether `$name` is a direct (undotted) MorphTo of `$model`: a morph method on anything else is a runtime error.
     *
     * @param class-string<Model> $model
     */
    private static function isDirectMorphTo(Codebase $codebase, string $model, string $name): bool
    {
        if (\str_contains($name, '.')) {
            return false;
        }

        $class = self::relationClass($codebase, $model, $name, self::relationType($codebase, $model, $name));

        return $class !== null && ClassLineage::isA($codebase, $class, MorphTo::class);
    }

    /**
     * Walk the dot path from `$model`; the callback applies to the LAST segment, which for an eager-load slot must
     * also yield its Relation type. A MorphTo has no single related model, so none may appear on the path.
     *
     * @param class-string<Model> $model
     * @return array{class-string<Model>, ?TGenericObject}|null the last segment's related model and, when eager, its
     *         Relation type; null declines
     */
    private static function resolveRelation(Codebase $codebase, string $model, string $name, bool $eager): ?array
    {
        $segments = RelationPath::segments($name, true, false);
        $last = \array_key_last($segments);
        $relation = null;

        foreach ($segments as $index => $segment) {
            // The type the plugin's return provider gives a call of this method: a parsed body reads the exact
            // related model, also for a relation inherited from a parent or hosted by a trait.
            $type = self::relationType($codebase, $model, $segment);
            $class = self::relationClass($codebase, $model, $segment, $type);

            if ($class === null || ClassLineage::isA($codebase, $class, MorphTo::class)) {
                return null;
            }

            // Body unparseable: fall back to the declared generic. relationClass() already declined a union.
            $related = $type instanceof TGenericObject
                ? ModelPropertyResolver::extractExactlyOneModelFromUnion($type->type_params[0] ?? null, $codebase)
                : RelationResolver::relatedModel($codebase, $model, $segment);
            if ($related === null || !ClassLineage::isA($codebase, $related, Model::class)) {
                return null;
            }

            // The parser pins `static::class` to the declaring class, so a parsed related model that is a proper
            // ancestor of the receiver may be that leak, however the relation is reached (`self::class` declines too).
            if ($type instanceof TGenericObject && \strcasecmp($related, $model) !== 0 && ClassLineage::isA($codebase, $model, $related)) {
                return null;
            }

            // The slot embeds the Relation itself, which only the factory-call parser can type exactly.
            if ($eager && $index === $last) {
                if (!$type instanceof TGenericObject) {
                    return null;
                }

                $relation = $type;
            }

            $model = $related;
        }

        return [$model, $relation];
    }

    /**
     * The Relation class of a relation method: the parsed call's (`$type`), else the declared native or docblock
     * return type, which a `morphTo()` has no related model for. A declared union of Relations
     * (`HasMany<A>|HasOne<B>`) names no single class or model: null.
     */
    private static function relationClass(Codebase $codebase, string $model, string $method, ?TGenericObject $type): ?string
    {
        if ($type instanceof TGenericObject) {
            return $type->value;
        }

        $methodId = \strtolower($method);
        $selfClass = $model;

        try {
            $declared = $codebase->getMethodReturnType($model . '::' . $methodId, $selfClass);
        } catch (\InvalidArgumentException|\UnexpectedValueException) {
            return null;
        }

        $relations = [];
        foreach ($declared instanceof Union ? $declared->getAtomicTypes() : [] as $atomic) {
            if ($atomic instanceof TNamedObject && ClassLineage::isA($codebase, $atomic->value, Relation::class)) {
                $relations[] = $atomic->value;
            }
        }

        if (\count($relations) > 1) {
            return null;
        }

        return RelationMethodParser::parse($codebase, $model, $methodId)['relationClass'] ?? $relations[0] ?? null;
    }

    /**
     * The type the plugin's return provider gives a call of the relation method (null: no parseable factory
     * call). The method's APPEARING class is the one that reads the body: a trait-hosted relation has none of
     * its own storage and binds `self` to the class that composes the trait.
     */
    private static function relationType(Codebase $codebase, string $model, string $method): ?TGenericObject
    {
        $methodId = \strtolower($method);

        try {
            $appearing = $codebase->methods->getAppearingMethodId(MethodIdentifier::wrap($model . '::' . $methodId))->fq_class_name ?? null;
        } catch (\InvalidArgumentException|\UnexpectedValueException|UnpopulatedClasslikeException) {
            return null;
        }

        $type = $appearing === null ? null : ModelRelationReturnTypeHandler::relationType($codebase, $appearing, $model, $methodId, false);

        foreach ($type instanceof Union ? $type->getAtomicTypes() : [] as $atomic) {
            if ($atomic instanceof TGenericObject && ClassLineage::isA($codebase, $atomic->value, Relation::class)) {
                return $atomic;
            }
        }

        return null;
    }

    /**
     * Whether a trait other than Laravel's `QueriesRelationships` declares the method on the class or an ancestor.
     * Psalm ignores `insteadof` (vimeo/psalm#12113), so such an override still reports Laravel's trait as the
     * declaring one. Unknown classes count as overridden.
     *
     * @psalm-capabilities read-props
     */
    private static function traitOverrides(Codebase $codebase, string $class, string $method): bool
    {
        $provider = $codebase->classlike_storage_provider;
        $seen = [];

        try {
            foreach ([$class, ...\array_values($provider->get($class)->parent_classes)] as $name) {
                $queue = \array_values($provider->get($name)->used_traits);
                while (($trait = \array_pop($queue)) !== null) {
                    if (isset($seen[\strtolower($trait)])) {
                        continue;
                    }

                    $seen[\strtolower($trait)] = true;
                    $traitStorage = $provider->get($trait);

                    if (isset($traitStorage->methods[$method])) {
                        if (\strtolower($trait) !== self::LARAVEL_DECLARING_CLASSES[1]) {
                            return true;
                        }
                    } else {
                        \array_push($queue, ...\array_values($traitStorage->used_traits));
                    }
                }
            }
        } catch (\InvalidArgumentException|UnpopulatedClasslikeException) {
            return true;
        }

        return false;
    }

    /**
     * The callback's params, one per slot type.
     *
     * @param list<Union> $slots
     * @return list<FunctionLikeParameter>
     * @psalm-pure
     */
    private static function queryParams(array $slots): array
    {
        return \array_map(
            static fn(Union $slot): FunctionLikeParameter => new FunctionLikeParameter('query', false, $slot, $slot, is_optional: false),
            $slots,
        );
    }

    /**
     * `($q)`: the related model's builder, plus the Relation for an eager-load slot, whose constraint runs the same
     * closure with it.
     *
     * @param class-string<Model> $related
     * @return list<Union>
     */
    private static function relationSlots(Codebase $codebase, string $related, ?TGenericObject $relation): array
    {
        $builder = ModelMethodHandler::resolvedBuilderTypeFor($related, $codebase);

        return [new Union($relation instanceof TGenericObject ? [$builder, $relation] : [$builder])];
    }

    /**
     * `($q, $type)`: one builder and one class-string per literal type.
     *
     * @param non-empty-list<class-string<Model>> $types
     * @return list<Union>
     */
    private static function morphSlots(Codebase $codebase, array $types): array
    {
        return [
            new Union(\array_map(
                static fn(string $type): TNamedObject => ModelMethodHandler::resolvedBuilderTypeFor($type, $codebase),
                $types,
            )),
            new Union(\array_map(
                static fn(string $type): TClassString => new TClassString($type, new TNamedObject($type)),
                $types,
            )),
        ];
    }

    /**
     * The literal `$types` of a morph call as written (strings and `Foo::class`), or null when any entry is
     * something else (`'*'`, a variable, `static::class`, a spread).
     *
     * @return non-empty-list<string>|null
     */
    private static function morphClasses(StatementsAnalyzer $source, ?Expr $expr): ?array
    {
        $classes = [];

        foreach ($expr instanceof Array_ ? $expr->items : [$expr] as $item) {
            if ($item instanceof ArrayItem && ($item->unpack || $item->byRef)) {
                return null;
            }

            $value = $item instanceof ArrayItem ? $item->value : $item;

            if ($value instanceof String_ && $value->value !== '*') {
                $classes[] = \ltrim($value->value, '\\');
            } elseif ($value instanceof ClassConstFetch
                && $value->class instanceof Name
                && $value->name instanceof Identifier
                && $value->name->toLowerString() === 'class'
            ) {
                $classes[] = ClassLikeAnalyzer::getFQCLNFromNameObject($value->class, $source->getAliases());
            } else {
                return null;
            }
        }

        return $classes === [] ? null : $classes;
    }

    /**
     * The models behind literal morph types. Laravel resolves every type through the morph map, whether it was
     * written as an alias or a class name; an entry that is not a model declines.
     *
     * @param non-empty-list<string> $classes
     * @return non-empty-list<class-string<Model>>|null
     */
    private static function morphModels(Codebase $codebase, array $classes): ?array
    {
        $models = [];

        foreach ($classes as $class) {
            $class = Relation::getMorphedModel($class) ?? $class;

            if (!ClassLineage::isA($codebase, $class, Model::class)) {
                return null;
            }

            $models[\strtolower($class)] = $class;
        }

        return \array_values($models);
    }
}
