<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use Illuminate\Database\Eloquent\Builder;
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
use PhpParser\Node\Scalar\String_;
use Psalm\Codebase;
use Psalm\Exception\UnpopulatedClasslikeException;
use Psalm\Internal\Analyzer\ClassLikeAnalyzer;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Internal\MethodIdentifier;
use Psalm\LaravelPlugin\Handlers\Eloquent\Support\ModelPropertyResolver;
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
use Psalm\Type\Atomic\TClassString;
use Psalm\Type\Atomic\TClosure;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TNull;
use Psalm\Type\Atomic\TTemplateParam;
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
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1676
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

    public static function reset(): void
    {
        self::$calls = null;
        self::$registered = [];
    }

    #[\Override]
    public static function afterCodebasePopulated(AfterCodebasePopulatedEvent $event): void
    {
        $codebase = $event->getCodebase();
        $builderLower = \strtolower(Builder::class);

        foreach ($codebase->classlike_storage_provider::getAll() as $storage) {
            $key = \strtolower($storage->name);

            if (isset(self::$registered[$key]) || ($key !== $builderLower && !isset($storage->parent_classes[$builderLower]))) {
                continue;
            }

            self::$registered[$key] = true;
            $codebase->methods->params_provider->registerClosure($storage->name, self::getMethodParams(...));
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

        if ($args !== [] && $expr->name instanceof Identifier && isset(self::SLOTS[$expr->name->toLowerString()])) {
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

        // Only Laravel's own signature is rewritten: a userland override (a builder that wraps the callback, or
        // one whose params Psalm inherits from the parent) keeps its own contract. A static or relation-forwarded
        // call dispatches through the base Builder but runs on the model's own builder, so that class is checked too.
        $declaring = self::laravelDeclaring($codebase, $dispatched, $method);
        if (!$declaring instanceof MethodIdentifier
            || ($forwards && !self::laravelDeclaring($codebase, ModelMethodHandler::getBuilderClassForModel($model), $method) instanceof MethodIdentifier)
        ) {
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

        try {
            $params = $codebase->methods->getStorage($declaring)->params;
        } catch (\UnexpectedValueException|\InvalidArgumentException) {
            return null;
        }

        $callback = new Union([new TClosure(self::queryParams($slots), Type::getMixed())]);
        $result = [];
        foreach ($params as $param) {
            $result[] = $param->name === $paramName ? $param->setType($callback) : $param;
        }

        return $result;
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

        $receiver = $source->getNodeTypeProvider()->getType($call->var);
        $atomics = $receiver instanceof Union
            ? \array_values(\array_filter($receiver->getAtomicTypes(), static fn($atomic): bool => !$atomic instanceof TNull))
            : [];
        $atomic = $atomics[0] ?? null;

        if (\count($atomics) !== 1 || !$atomic instanceof TNamedObject) {
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

    /**
     * The model a receiver binds an ancestor's template to (`Builder::TModel`, `Relation::TRelatedModel`): the ancestor's
     * own argument, a concrete model the subclass extends it with, or the receiver's type param at the position of the
     * template it forwards, matched by the defining class (a name match alone would read another slot). Anything else
     * names no model.
     *
     * @param class-string $ancestor
     * @return class-string<Model>|null
     * @psalm-capabilities read-props
     */
    private static function projectedModel(Codebase $codebase, TNamedObject $atomic, string $ancestor, string $template): ?string
    {
        $typeParams = $atomic instanceof TGenericObject ? $atomic->type_params : [];
        if (\strtolower($atomic->value) === \strtolower($ancestor)) {
            return ModelPropertyResolver::extractExactlyOneModelFromUnion($typeParams[0] ?? null, $codebase);
        }

        try {
            $storage = $codebase->classlike_storage_provider->get($atomic->value);
        } catch (\InvalidArgumentException|UnpopulatedClasslikeException) {
            return null;
        }

        // `template_extended_params` states each ancestor's template in its DIRECT child's terms, so a forwarded
        // template (`TRelatedModel:HasOneOrMany`) is followed up the chain until the receiver's own template.
        $value = $storage->template_extended_params[$ancestor][$template] ?? null;
        for ($hops = 0; $hops < 8 && $value instanceof Union; ++$hops) {
            $concrete = ModelPropertyResolver::extractExactlyOneModelFromUnion($value, $codebase);
            $forwarded = $value->isSingle() ? $value->getSingleAtomic() : null;
            if ($concrete !== null || !$forwarded instanceof TTemplateParam) {
                return $concrete;
            }

            if (\strcasecmp($forwarded->defining_class, $storage->name) === 0) {
                $position = \array_search($forwarded->param_name, \array_keys($storage->template_types ?? []), true);

                return $position === false ? null : ModelPropertyResolver::extractExactlyOneModelFromUnion($typeParams[$position] ?? null, $codebase);
            }

            $value = $storage->template_extended_params[$forwarded->defining_class][$forwarded->param_name] ?? null;
        }

        return null;
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
        $segments = \explode('.', $name);
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
