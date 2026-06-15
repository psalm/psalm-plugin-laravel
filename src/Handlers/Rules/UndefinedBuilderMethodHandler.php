<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Rules;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use Psalm\Codebase;
use Psalm\CodeLocation;
use Psalm\Internal\MethodIdentifier;
use Psalm\Issue\UndefinedMagicMethod;
use Psalm\IssueBuffer;
use Psalm\LaravelPlugin\Util\DynamicWhereResolver;
use Psalm\Plugin\EventHandler\AfterExpressionAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterExpressionAnalysisEvent;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

/**
 * Flags undefined method/scope calls on `$class::query()` when `$class` is a bare
 * `class-string<\Illuminate\Database\Eloquent\Model>`.
 *
 * Such a `query()` resolves to the base `Builder<Model>`, whose stub
 * `@mixin \Illuminate\Database\Query\Builder` (carrying `__call`) makes Psalm accept ANY
 * method name permissively — so a genuinely-undefined scope/method is silently swallowed
 * and surfaces only as a downstream `mixed`. At runtime the same call reaches
 * `Builder::__call`, which forwards to `Query\Builder::__call`, finds no scope/macro/
 * where-clause to route to (the base `Model` declares no scopes), and throws
 * `BadMethodCallException`. This was the motivating bug in #1070: a renamed scope reached
 * production via `$fqcn::query()->oldScope()`.
 *
 * The handler reports Psalm's built-in {@see \Psalm\Issue\UndefinedMagicMethod} — the same
 * issue Psalm already raises for the concrete custom-builder case
 * (`WorkOrder::query()->fake()`) — so both paths surface identically and honor existing
 * `UndefinedMagicMethod` issue configuration.
 *
 * **Why the receiver is restricted to a direct `::query()` call.** A bare `Builder $q`
 * parameter (idiomatic in filters, pipelines, and `whereHas(..., fn (Builder $q) => ...)`
 * closures) is *type-identical* to `Builder<Model>` — Psalm fills the missing template
 * param with the `Model` bound and there is no type-level discriminator. Such a variable
 * usually stands in for a concrete model at runtime, where the scope is perfectly valid, so
 * flagging it would be a false positive. Requiring the receiver to be `$class::query()`
 * narrows reporting to the genuine `class-string<Model>` case from #1070 and leaves bare
 * `Builder` receivers alone. The trade-off: a variable-assigned builder
 * (`$q = $fqcn::query(); $q->oldScope();`) or a method chain
 * (`$fqcn::query()->where(...)->oldScope()`) is not reported.
 *
 * Concrete-model builders (`Builder<Post>`) are excluded by the type gate: a
 * `class-string<Post>` holder may legitimately call a scope this handler cannot enumerate,
 * and models with custom builders already report undefined calls through the builder class
 * ({@see \Psalm\LaravelPlugin\Handlers\Eloquent\CustomBuilderMethodHandler}).
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1070
 * @see https://github.com/larastan/larastan EloquentBuilderForwardsCallsExtension — Larastan's always-on analogue
 */
// Uses AfterExpressionAnalysisInterface (fires per-expression). The instanceof MethodCall
// check and the cheap structural "receiver is a ::query() static call" gate reject the
// overwhelming majority of expressions before any type lookup or codebase query runs.
final class UndefinedBuilderMethodHandler implements AfterExpressionAnalysisInterface
{
    /** @inheritDoc */
    #[\Override]
    public static function afterExpressionAnalysis(AfterExpressionAnalysisEvent $event): ?bool
    {
        $expr = $event->getExpr();

        // Only instance calls with a literal method name. Dynamic `->{$name}()` is skipped:
        // the name isn't statically known, so existence can't be decided.
        if (!$expr instanceof MethodCall || !$expr->name instanceof Identifier) {
            return null;
        }

        // Provenance gate: only report on `$class::query()->method()`. A plain-variable
        // receiver (bare `Builder $q` param, whereHas closure) is type-identical to
        // Builder<Model> but typically backs a concrete model at runtime, so reporting there
        // would be a false positive. See the class docblock for the full rationale.
        $receiver = $expr->var;
        if (!$receiver instanceof StaticCall
            || !$receiver->name instanceof Identifier
            || \strtolower($receiver->name->name) !== 'query'
        ) {
            return null;
        }

        $source = $event->getStatementsSource();

        $receiverType = $source->getNodeTypeProvider()->getType($receiver);
        if (!$receiverType instanceof Union || !self::isBaseModelBuilder($receiverType)) {
            return null;
        }

        $methodName = $expr->name->name;
        /** @var lowercase-string $methodNameLc */
        $methodNameLc = \strtolower($methodName);

        if (self::isResolvableOnBaseBuilder($event->getCodebase(), $methodNameLc)) {
            return null;
        }

        // Emit Psalm's built-in UndefinedMagicMethod — the same issue Psalm raises for the
        // concrete custom-builder case (e.g. WorkOrder::query()->fake()), so the dynamic
        // base-Builder case reports consistently and honors existing UndefinedMagicMethod config.
        IssueBuffer::accepts(
            new UndefinedMagicMethod(
                'Magic method ' . Builder::class . "::{$methodNameLc} does not exist",
                new CodeLocation($source, $expr->name),
                Builder::class . '::' . $methodNameLc,
            ),
            $source->getSuppressedIssues(),
        );

        return null;
    }

    /**
     * True only when the receiver is exactly base `Builder<\Illuminate\Database\Eloquent\Model>`.
     *
     * Custom builder subclasses (`PostBuilder<...>`) and concrete-model params
     * (`Builder<Post>`) are excluded — the former already report undefined calls via the
     * builder class, the latter may carry scopes this handler does not enumerate.
     *
     * @psalm-mutation-free
     */
    private static function isBaseModelBuilder(Union $receiverType): bool
    {
        $atomics = $receiverType->getAtomicTypes();
        if (\count($atomics) !== 1) {
            return false;
        }

        $atomic = $atomics[\array_key_first($atomics)];

        if (!$atomic instanceof TGenericObject
            || $atomic->value !== Builder::class
            || \count($atomic->type_params) !== 1
        ) {
            return false;
        }

        $paramAtomics = $atomic->type_params[0]->getAtomicTypes();
        if (\count($paramAtomics) !== 1) {
            return false;
        }

        $paramAtomic = $paramAtomics[\array_key_first($paramAtomics)];

        return $paramAtomic instanceof TNamedObject && $paramAtomic->value === Model::class;
    }

    /**
     * Mirror Laravel's `Builder` / `Query\Builder` `__call` dispatch: a method on base
     * `Builder<Model>` is resolvable (won't reach the throwing branch of `__call`) iff it is a
     * real `Eloquent\Builder` or `Query\Builder` method, a registered macro, or a dynamic
     * `where{Column}` form.
     *
     * `Model::class` is intentionally NOT consulted: `Eloquent\Builder::__call` forwards only
     * to `$this->query` (Query\Builder), never to the Model, so Model-only methods such as
     * `save()` / `getAttribute()` genuinely fatal on a builder and must be reported.
     *
     * @param lowercase-string $methodNameLc
     */
    private static function isResolvableOnBaseBuilder(Codebase $codebase, string $methodNameLc): bool
    {
        // Real methods reachable on Builder<Model>: Eloquent\Builder's own and Query\Builder's
        // (the latter via the stub's @mixin / runtime forwardCallTo). is_used: false keeps this
        // an existence probe, not a usage mark, so unused-code analysis is unaffected.
        foreach ([Builder::class, QueryBuilder::class] as $class) {
            if ($codebase->methodExists(new MethodIdentifier($class, $methodNameLc), is_used: false)) {
                return true;
            }
        }

        // Dynamic where{Column}: Laravel routes any `where*` name through dynamicWhere(), which
        // builds a clause instead of throwing — so it is never an undefined-method fatal.
        if (DynamicWhereResolver::isDynamicWhereMethod($methodNameLc)) {
            return true;
        }

        // Runtime-registered macros (and stub @method declarations) are injected into
        // pseudo_methods by {@see \Psalm\LaravelPlugin\Handlers\Magic\MacroHandler}; a macro
        // call resolves through __call at runtime, so it is not undefined.
        return self::hasPseudoMethod($codebase, Builder::class, $methodNameLc)
            || self::hasPseudoMethod($codebase, QueryBuilder::class, $methodNameLc);
    }

    /**
     * @param class-string $class
     * @param lowercase-string $methodNameLc
     * @psalm-mutation-free
     */
    private static function hasPseudoMethod(Codebase $codebase, string $class, string $methodNameLc): bool
    {
        try {
            $storage = $codebase->classlike_storage_provider->get(\strtolower($class));
        } catch (\InvalidArgumentException) {
            // Builder/QueryBuilder are core classes guaranteed populated once a receiver typed
            // as Builder<Model> exists, so this is defensive and effectively unreachable.
            return false;
        }

        return isset($storage->pseudo_methods[$methodNameLc])
            || isset($storage->declaring_pseudo_method_ids[$methodNameLc]);
    }
}
