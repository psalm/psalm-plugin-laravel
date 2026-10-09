<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use Psalm\Codebase;
use Psalm\Internal\Analyzer\ClassLikeAnalyzer;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Internal\MethodIdentifier;
use Psalm\LaravelPlugin\Handlers\Eloquent\Support\RelationFacts;
use Psalm\LaravelPlugin\Handlers\Eloquent\Support\RelationQueryReceiver;
use Psalm\LaravelPlugin\Internal\Arg as ArgUtil;
use Psalm\LaravelPlugin\Internal\CallStash;
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
     * Calls awaiting their params lookup, keyed by the call's first Arg.
     *
     * @psalm-var CallStash<MethodCall|NullsafeMethodCall|StaticCall>|null
     */
    private static ?CallStash $calls = null;

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
            (self::$calls ??= new CallStash())->put($args[0], $expr);
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
        $literal = ArgUtil::closureLiteral(ArgUtil::boundTo($args, $paramName, $position));
        $relation = ArgUtil::boundTo($args, 'relation', 0)?->value;
        $call = self::$calls?->get($args[0]);
        $source = $event->getStatementsSource();

        if ($literal === null
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
        $receiver = RelationQueryReceiver::receiverModel($source, $call, $dispatched);
        if ($receiver === null) {
            return null;
        }

        [$model, $forwards] = $receiver;
        $codebase = $source->getCodebase();

        // Only Laravel's own signature is rewritten: a userland override (a builder that wraps the callback, or
        // one whose params Psalm inherits from the parent) keeps its own contract. A static or relation-forwarded
        // call dispatches through the base Builder but runs on the model's own builder, so that class is checked too.
        $declaring = RelationQueryReceiver::laravelDeclaring($codebase, $dispatched, $method);
        if (!$declaring instanceof MethodIdentifier
            || ($forwards && !RelationQueryReceiver::laravelDeclaring($codebase, ModelMethodHandler::getBuilderClassForModel($model), $method) instanceof MethodIdentifier)
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
     * Whether `$name` is a direct (undotted) MorphTo of `$model`: a morph method on anything else is a runtime error.
     *
     * @param class-string<Model> $model
     */
    private static function isDirectMorphTo(Codebase $codebase, string $model, string $name): bool
    {
        return !\str_contains($name, '.') && RelationFacts::of($codebase, $model, $name)?->isMorphTo === true;
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
            $facts = RelationFacts::of($codebase, $model, $segment);

            if (!$facts instanceof RelationFacts || $facts->isMorphTo || $facts->relatedModel === null) {
                return null;
            }

            // The slot embeds the Relation itself, which only the factory-call parser can type exactly.
            if ($eager && $index === $last) {
                if (!$facts->type instanceof TGenericObject) {
                    return null;
                }

                $relation = $facts->type;
            }

            $model = $facts->relatedModel;
        }

        return [$model, $relation];
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
