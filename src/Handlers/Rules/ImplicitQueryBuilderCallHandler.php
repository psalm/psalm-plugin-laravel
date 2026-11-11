<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Rules;

use Illuminate\Database\Eloquent\Model;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use Psalm\Codebase;
use Psalm\CodeLocation;
use Psalm\Context;
use Psalm\Exception\UnpopulatedClasslikeException;
use Psalm\FileManipulation;
use Psalm\IssueBuffer;
use Psalm\LaravelPlugin\Handlers\Eloquent\BuilderScopeHandler;
use Psalm\LaravelPlugin\Handlers\Eloquent\ModelMethodHandler;
use Psalm\LaravelPlugin\Internal\ModelCallGuard;
use Psalm\LaravelPlugin\Internal\PsalterFix;
use Psalm\LaravelPlugin\Issues\ImplicitQueryBuilderCall;
use Psalm\Plugin\EventHandler\AfterExpressionAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterExpressionAnalysisEvent;
use Psalm\StatementsSource;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

/**
 * Opt-in rule: flags query builder and local scope methods invoked directly on an Eloquent
 * model — statically (`User::where(...)`, `User::active()`) or on an instance
 * (`$user->where(...)`) — instead of through an explicit `query()` entry point.
 *
 * Such calls are forwarded by Laravel through `__callStatic` / `__call` to a fresh query
 * builder. Teams that want to minimise this magic enable the rule to require the explicit
 * `Model::query()->...` form, which keeps the call chain concrete and easy to follow.
 *
 * Registered only when `<reportImplicitQueryBuilderCalls value="true" />` is set (see
 * {@see \Psalm\LaravelPlugin\Plugin::registerHandlers()}). Sibling of {@see ModelMakeHandler},
 * which flags the single `Model::make()` case; this generalises the idea to the whole forwarded
 * surface, while deferring `make()` itself back to that handler (its correct fix is `new Model()`,
 * not a query).
 *
 * ## What is flagged
 *
 * The rule reuses the plugin's own forwarding-resolution logic so it flags exactly what the
 * plugin already recognises as a forwarded call, and nothing it would report as
 * `UndefinedMagicMethod`:
 *
 *  - **Query builder methods** — Eloquent `Builder` methods reached via the `@mixin`
 *    (`where`, `find`, `create`, `first`, `get`), and `Query\Builder` methods forwarded through
 *    `Builder::__call` (`orderBy`, `whereIn`), plus resolvable dynamic `where{Column}()` clauses.
 *    Resolved by {@see ModelMethodHandler::forwardsToQueryBuilder()}.
 *  - **Custom builder methods** — methods declared on a model's dedicated Eloquent builder
 *    (registered via `newEloquentBuilder()` or `#[UseEloquentBuilder]`), and trait-provided
 *    `@method` builder helpers on such custom-builder models. Also via
 *    {@see ModelMethodHandler::forwardsToQueryBuilder()}. (Trait builder macros on a plain
 *    base-`Builder` model — e.g. `SoftDeletes::withTrashed()` — are runtime macros the plugin
 *    does not resolve as forwarded, so they are not flagged.)
 *  - **Local scopes** — legacy `scopeActive()` invoked by its forwarded bare name `active()`,
 *    and modern `#[Scope]` attribute methods. Detected by
 *    {@see BuilderScopeHandler::hasScopeMethod()}; a scope call that is actually a direct,
 *    accessible invocation passing the builder explicitly (scope composition like
 *    `$this->other($query, ...)`) is excluded via {@see BuilderScopeHandler::isDirectScopeCall()}
 *    so legitimate direct calls are not flagged.
 *
 * A real method declared on the framework `Model` base (`save()`, `all()`, `with()`, `query()`,
 * `destroy()`, ...) and any real user-defined method are never magic forwarding and are left
 * alone. A genuinely undefined method matches none of the above and is left to Psalm's
 * `UndefinedMagicMethod` rather than mislabelled as a "use query()" suggestion.
 *
 * ### Known limitation — public `#[Scope]` methods
 *
 * A `public` `#[Scope]` method is accessible from every call site, so its forwarded form cannot
 * be told apart from a direct call by accessibility alone, and is therefore not flagged here.
 * Laravel's convention wants scopes `protected` (which the rule does flag when forwarded), and a
 * `public` `#[Scope]` is independently reported by {@see PublicScopeAccessorVisibilityHandler} as
 * {@see \Psalm\LaravelPlugin\Issues\PublicModelScope}.
 *
 * ## Hook choice — AfterExpressionAnalysis
 *
 * Matches {@see ModelMakeHandler}: it fires for every expression regardless of how the called
 * method was resolved, so it sees magic-forwarded calls that a method-resolution provider hook
 * might never be consulted for. The `instanceof` / `Identifier` / `method_exists` guards reject
 * the vast majority of expressions before any codebase lookup.
 *
 * ## Psalter fix
 *
 * `psalter --issues=ImplicitQueryBuilderCall` inserts `query()->` (static call) or `newQuery()->`
 * (instance call) before the method name, as one edit; `__callStatic` -> `__call` -> `newQuery()` is the
 * path Laravel already takes, so behavior is unchanged. These are reported, not fixed:
 *  - a first-class callable and a `self::` / `static::` / `parent::` call (object context would keep the
 *    instance's state; `query()` drops it);
 *  - a named static call in a non-static method or closure whose `$this` is, or may be, an instance of the
 *    target model (see {@see ModelCallGuard::mayRunInObjectContextOf()}): PHP runs it as `$this->__call()` there;
 *  - a model whose class chain overrides `__call`, `__callStatic`, `query` or `newQuery` (see
 *    {@see ModelCallGuard::overridesAny()}): the insertion would bypass that code.
 */
final class ImplicitQueryBuilderCallHandler implements AfterExpressionAnalysisInterface
{
    /** @inheritDoc */
    #[\Override]
    public static function afterExpressionAnalysis(AfterExpressionAnalysisEvent $event): ?bool
    {
        $expr = $event->getExpr();

        // Only static (`Model::method()`) and instance (`$model->method()`) calls are in scope.
        if (!$expr instanceof StaticCall && !$expr instanceof MethodCall) {
            return null;
        }

        // Named methods only — `Model::$method()` / `$model->$method()` are not statically known.
        if (!$expr->name instanceof Identifier) {
            return null;
        }

        // PHP method names are case-insensitive; the plugin's resolution keys are lowercase and
        // method_exists() is case-insensitive too.
        $methodNameLower = \strtolower($expr->name->name);

        // Fast reject + stub-independent correctness: a method that genuinely exists on the
        // framework Model base (save/all/with/query/destroy/increment/...) is never magic
        // forwarding, so it is left alone before any per-call-site codebase lookup. Resolved
        // against the loaded real class, this holds even when the Model stub omits the method.
        if (\method_exists(Model::class, $methodNameLower)) {
            return null;
        }

        // `make()` is forwarded to Builder::make(), but it is a "new instance" operation, not a
        // query — its proper fix is `new Model(...)`, which the always-on ModelMakeHandler already
        // reports as ModelMakeDiscouraged. Suggesting `Model::query()->make(...)` here would be
        // nonsensical and duplicate that handler, so defer to it.
        if ($methodNameLower === 'make') {
            return null;
        }

        $codebase = $event->getCodebase();

        $modelClass = $expr instanceof StaticCall
            ? self::staticReceiverModel($expr, $event->getStatementsSource(), $codebase)
            : self::instanceReceiverModel($expr, $event, $codebase);

        if ($modelClass === null) {
            return null;
        }

        if (!self::isMagicForwardedCall($modelClass, $methodNameLower, $event, $codebase)) {
            return null;
        }

        $shortName = self::shortClassName($modelClass);
        $methodName = $expr->name->name;
        $source = $event->getStatementsSource();
        $isStatic = $expr instanceof StaticCall;

        // An instance call names no class to call `query()` on, and `$model->newQuery()` keeps the
        // instance's connection and table, so that is the advice (and the fix) for it.
        $entryPoint = $isStatic ? "{$shortName}::query()" : '$model->newQuery()';

        $issue = new ImplicitQueryBuilderCall(
            "{$shortName}::{$methodName}() is forwarded to the query builder through Laravel's "
            . "__callStatic/__call magic. Use {$entryPoint}->{$methodName}(...) instead.",
            new CodeLocation($source, $expr),
        );

        // Reported, never fixed:
        //  - the first-class callable `Model::where(...)` is a callable on the model, not a call, so
        //    prefixing `query()->` would not be a behavior-preserving rewrite;
        //  - `self::` / `static::` / `parent::` may run in object context, where the call dispatches to
        //    `$this->__call()` and keeps the instance's connection and table, while `static::query()`
        //    builds a fresh model and drops that state.
        $isSpecialClass = $expr instanceof StaticCall && $expr->class instanceof Name && $expr->class->isSpecialClassName();

        if ($expr->isFirstClassCallable() || $isSpecialClass) {
            IssueBuffer::accepts($issue, $source->getSuppressedIssues());

            return null;
        }

        $nameStart = $expr->name->getStartFilePos();
        $replacements = PsalterFix::fixOrReport(
            $codebase,
            $source,
            [$issue],
            static fn(): array => self::rewriteKeepsBehavior($modelClass, $isStatic, $source, $event->getContext(), $codebase)
                ? [new FileManipulation($nameStart, $nameStart, $isStatic ? 'query()->' : 'newQuery()->')]
                : [],
        );

        if ($replacements !== null) {
            $event->setFileReplacements($replacements);
        }

        return null;
    }

    /**
     * Whether inserting `query()->` / `newQuery()->` keeps what the forwarded call does. When not, the
     * issue is still reported (an empty edit list makes {@see PsalterFix::fixOrReport()} report it).
     *
     * @param class-string<Model> $modelClass
     */
    private static function rewriteKeepsBehavior(
        string $modelClass,
        bool $isStatic,
        StatementsSource $source,
        Context $context,
        Codebase $codebase,
    ): bool {
        if (ModelCallGuard::overridesAny($codebase, $modelClass, ['__call', '__callstatic', 'query', 'newquery'])) {
            return false;
        }

        return !$isStatic || !ModelCallGuard::mayRunInObjectContextOf($codebase, $modelClass, $source, $context);
    }

    /**
     * Resolve the model FQCN named on the left of a static call, or null when the receiver
     * is not a (resolvable) Eloquent model. `self` and `static` are special class names Psalm's
     * name resolver does not rewrite to an FQCN, so they are resolved against the enclosing class
     * explicitly, or the idiomatic `static::where(...)` inside a model method silently bypasses
     * this rule. Mirrors {@see UndefinedModelRelationHandler::resolveClassName()}.
     *
     * @return class-string<Model>|null
     */
    private static function staticReceiverModel(StaticCall $expr, StatementsSource $source, Codebase $codebase): ?string
    {
        if (!$expr->class instanceof Name) {
            return null;
        }

        $className = self::resolveClassName($expr->class, $source);

        if ($className === null || !self::isModelSubclass($className, $codebase)) {
            return null;
        }

        return $className;
    }

    /**
     * Resolve a `Name` class reference to an FQCN. `self` / `static` resolve to the enclosing class;
     * `parent` is not resolved. Mirrors {@see UndefinedModelRelationHandler::resolveClassName()}.
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
     * Resolve the model FQCN of an instance call's receiver, or null when the receiver type is
     * not unambiguously a single Eloquent model. The receiver must resolve to exactly one model
     * class: every atomic of the type has to be that same model. A union that mixes the model
     * with a Builder/Relation/Collection (the call may already be on a builder — the explicit
     * form), with `null`, or with a different model (an arbitrary class-specific suggestion) is
     * skipped to avoid a false positive.
     *
     * @return class-string<Model>|null
     */
    private static function instanceReceiverModel(MethodCall $expr, AfterExpressionAnalysisEvent $event, Codebase $codebase): ?string
    {
        $receiverType = $event->getStatementsSource()->getNodeTypeProvider()->getType($expr->var);

        if (!$receiverType instanceof Union) {
            return null;
        }

        $modelClass = null;

        foreach ($receiverType->getAtomicTypes() as $atomicType) {
            // Any non-model atomic (a Builder/Relation/Collection object, null, a scalar) makes
            // the receiver ambiguous — bail rather than guess.
            if (!$atomicType instanceof TNamedObject || !self::isModelSubclass($atomicType->value, $codebase)) {
                return null;
            }

            if ($modelClass === null) {
                $modelClass = $atomicType->value;
            } elseif ($modelClass !== $atomicType->value) {
                // A union of distinct models gives no single class to name in the suggestion.
                return null;
            }
        }

        return $modelClass;
    }

    /**
     * @psalm-assert-if-true class-string<Model> $className
     */
    private static function isModelSubclass(string $className, Codebase $codebase): bool
    {
        if ($className === Model::class) {
            return true;
        }

        if (!$codebase->classExists($className)) {
            return false;
        }

        // classExtends (from_api: true) throws InvalidArgumentException on missing/aliased storage
        // and UnpopulatedClasslikeException (a sibling LogicException) when storage exists but is
        // not populated yet. Either way the subclass link can't be proven → treat as not a model.
        // Mirrors BuilderScopeHandler::isMethodAccessibleFrom; reached on a broad receiver set here.
        try {
            return $codebase->classExtends($className, Model::class);
        } catch (\InvalidArgumentException|UnpopulatedClasslikeException) {
            return false;
        }
    }

    /**
     * Whether a call whose method is absent from the framework Model base is a magic-forwarded
     * builder / scope call. Reuses the plugin's own forwarding resolution so the verdict matches
     * what the plugin recognises as a forwarded call.
     *
     * @param class-string<Model> $modelClass
     * @param lowercase-string $methodNameLower
     */
    private static function isMagicForwardedCall(
        string $modelClass,
        string $methodNameLower,
        AfterExpressionAnalysisEvent $event,
        Codebase $codebase,
    ): bool {
        // Scopes (legacy scopeXxx + modern #[Scope]) are forwarded when invoked by the bare name.
        // A real, accessible scope method passed the builder explicitly (scope composition such
        // as $this->other($query, ...)) is a direct call, not magic, and is left alone.
        if (BuilderScopeHandler::hasScopeMethod($codebase, $modelClass, $methodNameLower)) {
            return !BuilderScopeHandler::isDirectScopeCall($codebase, $modelClass, $methodNameLower, $event->getContext());
        }

        // A method genuinely declared on the model (or inherited from a non-Model ancestor or
        // trait) is a real method, not magic forwarding — even when its name collides with a
        // builder method (a user `public function where()` / `orderBy()`). Scopes were already
        // handled above, so this does not swallow them. declaring_method_ids holds real declared
        // methods only; the plugin's forwarded methods are virtual and never recorded there.
        if (self::modelDeclaresMethod($modelClass, $methodNameLower, $codebase)) {
            return false;
        }

        // Query builder methods reached only through forwarding — Eloquent\Builder via the
        // @mixin, Query\Builder via Builder::__call, a custom builder, or a resolvable dynamic
        // where. A typo matches none of these and is left to UndefinedMagicMethod.
        return ModelMethodHandler::forwardsToQueryBuilder($codebase, $modelClass, $methodNameLower);
    }

    /**
     * Whether $modelClass really declares (or inherits) a method named $methodNameLower, as
     * opposed to resolving it through Laravel's magic forwarding. Reads `declaring_method_ids`,
     * which Psalm populates only from genuinely declared methods — the plugin's forwarded methods
     * are answered virtually by a method-existence provider and never recorded there.
     *
     * @param class-string<Model> $modelClass
     * @param lowercase-string $methodNameLower
     *
     * @psalm-mutation-free
     */
    private static function modelDeclaresMethod(string $modelClass, string $methodNameLower, Codebase $codebase): bool
    {
        try {
            $classStorage = $codebase->classlike_storage_provider->get(\strtolower($modelClass));
        } catch (\InvalidArgumentException) {
            return false;
        }

        return isset($classStorage->declaring_method_ids[$methodNameLower]);
    }

    /** @psalm-pure */
    private static function shortClassName(string $fqcn): string
    {
        $pos = \strrpos($fqcn, '\\');

        return $pos !== false ? \substr($fqcn, $pos + 1) : $fqcn;
    }
}
