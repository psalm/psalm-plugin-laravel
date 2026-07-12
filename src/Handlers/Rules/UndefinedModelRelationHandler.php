<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Rules;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use Psalm\Codebase;
use Psalm\CodeLocation;
use Psalm\IssueBuffer;
use Psalm\LaravelPlugin\Handlers\Eloquent\Support\ModelPropertyResolver;
use Psalm\LaravelPlugin\Handlers\Eloquent\Support\RelationResolver;
use Psalm\LaravelPlugin\Issues\UndefinedModelRelation;
use Psalm\Plugin\EventHandler\AfterExpressionAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterExpressionAnalysisEvent;
use Psalm\StatementsSource;
use Psalm\Type\Atomic;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TKeyedArray;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TNull;
use Psalm\Type\Union;

/**
 * Flags relation names passed to eager-loading / relationship-query methods that
 * do not resolve to a method on the model — the typo case that is one of the most
 * common sources of runtime errors in Laravel apps:
 *
 *   User::with('nonExistent')->get();   // Relation 'nonExistent' is not defined on User
 *   $user->load('missingRelation');
 *   User::whereHas('pots', ...);        // typo for 'posts'
 *
 * Handled syntaxes (all carried by the FIRST argument of every targeted method):
 *   - string:        with('posts')
 *   - dot-notation:  with('posts.comments.author')   — each segment resolved against
 *                    the previous segment's related model
 *   - array (list):  with(['posts', 'comments'])
 *   - array (keyed): with(['posts' => fn ($q) => ...]) — the key is the relation
 *   - select syntax: with('posts:id,title')           — the `:columns` part is stripped
 *
 * **Hook choice — AfterExpressionAnalysis, not AfterMethodCallAnalysis.** The
 * issue's headline examples are static calls (`User::with(...)`, `User::has(...)`).
 * `has()`/`whereHas()` are not real static methods on the model — they resolve
 * through Eloquent's `__callStatic` magic — so AfterMethodCallAnalysis is not a
 * reliable place to observe them. AfterExpressionAnalysis fires on the call node
 * regardless of how the method resolves, the same reason
 * {@see UndefinedBuilderMethodHandler} uses it.
 *
 * **Existence-only, deliberately.** The rule reports only when *no* method (real
 * or `@method`) with the name exists. A method that exists but is not a relation
 * is left alone. This targets the typo/missing case the issue describes while
 * keeping false positives near zero on real apps, where relations can be added in
 * ways static analysis cannot see (runtime `Model::resolveRelationUsing()`,
 * package macros). Resolution is also conservative: a non-model receiver, the
 * bare/abstract base `Model`, an ambiguous union, a dynamic class-string, or an
 * unresolvable intermediate model (`morphTo`) all cause the rule to defer.
 *
 * The `withCount()` / `withSum()` family (relation + ` as alias` aggregate
 * sub-selects) is intentionally out of scope for this first pass.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/643
 * @see https://github.com/larastan/larastan RelationExistenceRule — Larastan's analogue
 */
final class UndefinedModelRelationHandler implements AfterExpressionAnalysisInterface
{
    /**
     * Methods whose relation name(s) come from the FIRST argument. That is the
     * common case and the array form (`['posts', 'comments']`) is fully handled.
     * The `load*`/`loadMissing`/`loadCount`/`loadExists` forms are additionally
     * variadic (extra positional relation names are accepted); those extra
     * positions are not validated, only the first argument. Keys are lowercase to
     * match the case-insensitive method-name compare.
     */
    private const RELATION_NAME_METHODS = [
        // Eager loading
        'with' => true,
        // Relationship existence queries
        'has' => true,
        'orhas' => true,
        'doesnthave' => true,
        'ordoesnthave' => true,
        'wherehas' => true,
        'orwherehas' => true,
        'wheredoesnthave' => true,
        'orwheredoesnthave' => true,
        'withwherehas' => true,
        'whererelation' => true,
        'orwhererelation' => true,
        'withwhererelation' => true,
        'wheredoesnthaverelation' => true,
        'orwheredoesnthaverelation' => true,
        'wheremorphrelation' => true,
        'orwheremorphrelation' => true,
        // Lazy eager loading on a loaded model
        'load' => true,
        'loadmissing' => true,
        'loadcount' => true,
        'loadsum' => true,
        'loadavg' => true,
        'loadmax' => true,
        'loadmin' => true,
        'loadexists' => true,
    ];

    /**
     * The subset of {@see RELATION_NAME_METHODS} that resolve through
     * `Model::loadAggregate()` / `Builder::withAggregate()`, which parse a
     * `relation as alias` clause off the name (the alias names the aggregate
     * sub-select column). For these the ` as <alias>` suffix is stripped before the
     * relation lookup; for every other method a space in the name is a genuine error
     * and stays reportable.
     */
    private const AGGREGATE_METHODS = [
        'loadcount' => true,
        'loadsum' => true,
        'loadavg' => true,
        'loadmax' => true,
        'loadmin' => true,
        'loadexists' => true,
    ];

    /** @inheritDoc */
    #[\Override]
    public static function afterExpressionAnalysis(AfterExpressionAnalysisEvent $event): ?bool
    {
        $expr = $event->getExpr();

        if (!$expr instanceof MethodCall && !$expr instanceof StaticCall) {
            return null;
        }

        // Dynamic `->{$name}()` has no statically known method name.
        if (!$expr->name instanceof Identifier) {
            return null;
        }

        $methodNameLc = \strtolower($expr->name->name);
        if (!isset(self::RELATION_NAME_METHODS[$methodNameLc])) {
            return null;
        }

        // Mirror Psalm's own gating: stay silent where method checks are disabled
        // (e.g. inside isset()/empty() operands), matching the core call analyzer.
        if (!$event->getContext()->check_methods) {
            return null;
        }

        $args = $expr->getArgs();
        if ($args === []) {
            return null;
        }

        $source = $event->getStatementsSource();
        $codebase = $event->getCodebase();

        $modelFqcn = self::resolveBaseModel($expr, $source, $codebase);
        if ($modelFqcn === null) {
            return null;
        }

        $allowsAlias = isset(self::AGGREGATE_METHODS[$methodNameLc]);

        foreach (self::extractRelationNames($source, $args[0]) as $relationName) {
            self::validateRelationPath($codebase, $source, $modelFqcn, $relationName, $args[0], $allowsAlias);
        }

        return null;
    }

    /**
     * Resolve the model the relation names should be validated against.
     *
     * Static call (`User::with(...)`)   → the called class, when it is a concrete model.
     * Instance call (`$builder->with()`) → the receiver type: `Builder<TModel>` /
     * `Relation<TModel, ...>` first generic parameter, or a `Model` receiver directly.
     *
     * @return ?class-string<Model>
     */
    private static function resolveBaseModel(MethodCall|StaticCall $expr, StatementsSource $source, Codebase $codebase): ?string
    {
        if ($expr instanceof StaticCall) {
            if (!$expr->class instanceof Name) {
                return null;
            }

            $fqcn = self::resolveClassName($expr->class, $source);

            return $fqcn !== null ? self::concreteModel($codebase, $fqcn) : null;
        }

        $receiverType = $source->getNodeTypeProvider()->getType($expr->var);
        if (!$receiverType instanceof Union) {
            return null;
        }

        return self::resolveModelFromType($codebase, $receiverType);
    }

    /**
     * Resolve a `Name` class reference to an FQCN. `self` / `static` resolve to the
     * enclosing class; `parent` is not resolved (relations on a parent reached via
     * static call are rare and `getFQCLN()` would point at the wrong class).
     */
    private static function resolveClassName(Name $class, StatementsSource $source): ?string
    {
        if ($class->isSpecialClassName()) {
            return match ($class->toLowerString()) {
                'self', 'static' => $source->getFQCLN(),
                default => null,
            };
        }

        // Psalm's name resolver stores the FQCN as a string on this attribute.
        /** @psalm-var ?string $resolved */
        $resolved = $class->getAttribute('resolvedName');

        return \is_string($resolved) ? $resolved : $class->toString();
    }

    /**
     * Mirror Larastan's `findModelReflectionFromType`: require a single concrete
     * model. A union mixing distinct models, or any atomic that does not map to a
     * model, is ambiguous and defers. `null` atomics (`Builder<Post>|null`) are
     * skipped.
     *
     * @return ?class-string<Model>
     * @psalm-mutation-free
     */
    private static function resolveModelFromType(Codebase $codebase, Union $type): ?string
    {
        $candidate = null;

        foreach ($type->getAtomicTypes() as $atomic) {
            if ($atomic instanceof TNull) {
                continue;
            }

            $model = self::modelFromAtomic($codebase, $atomic);
            if ($model === null) {
                return null;
            }

            if ($candidate !== null && \strtolower($candidate) !== \strtolower($model)) {
                return null;
            }

            $candidate = $model;
        }

        return $candidate;
    }

    /**
     * Map one atomic type to the concrete model it queries, or null when it is not
     * a model-bearing type. `Builder<TModel>` and `Relation<TModel, ...>` expose the
     * model as their first generic parameter; a `Model` atomic is the model itself.
     *
     * @return ?class-string<Model>
     * @psalm-mutation-free
     */
    private static function modelFromAtomic(Codebase $codebase, Atomic $atomic): ?string
    {
        if ($atomic instanceof TGenericObject) {
            if (\is_a($atomic->value, EloquentBuilder::class, true) || \is_a($atomic->value, Relation::class, true)) {
                $model = ModelPropertyResolver::extractModelFromUnion($atomic->type_params[0] ?? null);

                return $model !== null ? self::concreteModel($codebase, $model) : null;
            }

            if (\is_a($atomic->value, Model::class, true)) {
                return self::concreteModel($codebase, $atomic->value);
            }

            return null;
        }

        if ($atomic instanceof TNamedObject && \is_a($atomic->value, Model::class, true)) {
            return self::concreteModel($codebase, $atomic->value);
        }

        return null;
    }

    /**
     * Accept only a concrete, non-abstract model subclass. The bare base `Model`
     * (an un-narrowed `Builder<Model>`) and abstract base models are rejected:
     * their relation set is unknown / lives in subclasses, so validating against
     * them would be a false positive.
     *
     * @return ?class-string<Model>
     * @psalm-mutation-free
     */
    private static function concreteModel(Codebase $codebase, string $fqcn): ?string
    {
        if (!\is_a($fqcn, Model::class, true) || $fqcn === Model::class) {
            return null;
        }

        try {
            $storage = $codebase->classlike_storage_provider->get($fqcn);
        } catch (\InvalidArgumentException) {
            return null;
        }

        if ($storage->abstract) {
            return null;
        }

        /** @var class-string<Model> $fqcn */
        return $fqcn;
    }

    /**
     * Extract statically-known relation names from the first argument.
     *
     * Single string literal → one name. Constant array → string keys (the
     * `['posts' => fn]` closure form) plus single-string-literal values (the
     * `['posts', 'comments']` list form). Anything dynamic yields nothing and the
     * call is left unvalidated.
     *
     * @return list<string>
     */
    private static function extractRelationNames(StatementsSource $source, Arg $arg): array
    {
        $argType = $source->getNodeTypeProvider()->getType($arg->value);
        if (!$argType instanceof Union) {
            return [];
        }

        if ($argType->isSingleStringLiteral()) {
            return [$argType->getSingleStringLiteral()->value];
        }

        $names = [];

        foreach ($argType->getAtomicTypes() as $atomic) {
            if (!$atomic instanceof TKeyedArray) {
                continue;
            }

            foreach ($atomic->properties as $key => $valueType) {
                // Keyed form `['posts' => fn ($q) => ...]`: the string key is the relation.
                if (\is_string($key)) {
                    $names[] = $key;
                }

                // List form `['posts', 'comments']`: the string-literal value is the relation.
                if ($valueType->isSingleStringLiteral()) {
                    $names[] = $valueType->getSingleStringLiteral()->value;
                }
            }
        }

        return $names;
    }

    /**
     * Validate one (possibly dotted) relation path, emitting at the first segment
     * that does not resolve to a relation method. Walking stops early when an
     * intermediate related model cannot be determined (polymorphic / dynamic), so
     * the deeper segments are left unvalidated rather than guessed.
     *
     * @param bool $allowsAlias whether the calling method accepts a `relation as alias`
     *                          clause (the aggregate `load*` family) that must be
     *                          stripped before the relation lookup
     */
    private static function validateRelationPath(
        Codebase $codebase,
        StatementsSource $source,
        string $modelFqcn,
        string $relationName,
        Arg $arg,
        bool $allowsAlias,
    ): void {
        $segments = \explode('.', $relationName);
        $lastIndex = \count($segments) - 1;
        $current = $modelFqcn;

        foreach ($segments as $index => $segment) {
            // Strip the `:col1,col2` select syntax (with()/load() eager-load columns).
            $name = \trim(\explode(':', $segment, 2)[0]);

            // Strip the ` as <alias>` aggregate clause (loadCount/loadSum/...): the
            // alias names the sub-select column, not part of the relation name. A real
            // relation maps to a PHP method, which never contains a space, so this can
            // only ever remove alias syntax, never mask a typo.
            if ($allowsAlias) {
                $name = \explode(' ', $name, 2)[0];
            }

            if ($name === '') {
                return;
            }

            if (!RelationResolver::relationMethodExists($codebase, $current, $name)) {
                IssueBuffer::accepts(
                    new UndefinedModelRelation(
                        "Relation '{$name}' is not defined on {$current}.",
                        new CodeLocation($source, $arg->value),
                    ),
                    $source->getSuppressedIssues(),
                );

                return;
            }

            if ($index === $lastIndex) {
                return;
            }

            $related = RelationResolver::relatedModel($codebase, $current, $name);
            if ($related === null) {
                return;
            }

            // Gate the intermediate model exactly like the receiver: the bare base
            // Model and abstract bases (e.g. a relation declared as
            // `hasMany(Model::class)`) have an unknown / subclass-owned relation set,
            // so deeper segments must defer rather than be validated against them.
            $current = self::concreteModel($codebase, $related);
            if ($current === null) {
                return;
            }
        }
    }
}
