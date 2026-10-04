<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use Psalm\Codebase;
use Psalm\Exception\UnpopulatedClasslikeException;
use Psalm\LaravelPlugin\Handlers\Eloquent\Support\AggregateCallParser;
use Psalm\LaravelPlugin\Handlers\Eloquent\Support\AggregateEntry;
use Psalm\Plugin\EventHandler\AfterExpressionAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterExpressionAnalysisEvent;
use Psalm\Type;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TNull;
use Psalm\Type\Union;

/**
 * Types `{relation}_count` / `{relation}_exists` (and `as alias` names) precisely when the code
 * PROVES the aggregate was loaded; {@see ModelAggregatePropertyHandler} keeps them nullable otherwise.
 *
 * Three proof shapes, all literal-only (a dynamic argument is no proof) and validated against the
 * FINAL model class through the property handler's relation check:
 *
 * 1. `$m->loadCount('x')` on a variable: `vars_in_scope['$m->x_count']` is set. Psalm reads that
 *    entry before any property provider, so alias names (which fail the provider's existence
 *    check) work too. `$m->refresh()` drops every `$m->…` entry; reassigning `$m` is dropped by Psalm.
 * 2. `$m = M::withCount('x')->where(…)->firstOrFail();`: same facts for the assigned variable.
 * 3. `M::withCount('x')->firstOrFail()->x_count`, `$m->loadCount('x')->x_count`: the PropertyFetch
 *    node type is overridden (conventional names only; alias names fail existence earlier).
 *
 * Chains are walked from the terminal call inward. Past the first retrieval method (first, find, …)
 * only Builder/Relation-typed calls keep the query, and `select()` & co. drop the aggregate columns,
 * so the walk stops there. Collection hops, variable-held builders and foreach are not tracked: those
 * reads stay nullable. A closure that captures `$m` sees the fact like any other property narrowing.
 *
 * Carrying the fact in an intersection type (`M&object{x_count: int}`) was probed and rejected:
 * decisions.md, "Aggregate accessor proof".
 *
 * @internal
 */
final class ModelAggregateLoadHandler implements AfterExpressionAnalysisInterface
{
    /** Methods that return the model matching the query; firstOrNew & co. build instances without the attribute. */
    private const RETRIEVAL_METHODS = [
        'first' => true,
        'firstorfail' => true,
        'sole' => true,
        'find' => true,
        'findorfail' => true,
        'firstwhere' => true,
    ];

    /** Replace the select list, discarding the aggregate sub-selects added earlier in the chain. */
    private const COLUMN_REPLACING_METHODS = [
        'select' => true,
        'selectraw' => true,
        'selectsub' => true,
        'setquery' => true,
    ];

    /** @inheritDoc */
    #[\Override]
    public static function afterExpressionAnalysis(AfterExpressionAnalysisEvent $event): ?bool
    {
        $expr = $event->getExpr();

        if ($expr instanceof MethodCall) {
            $name = $expr->name instanceof Identifier ? \strtolower($expr->name->name) : null;
            $described = $name === null ? null : AggregateCallParser::describe($name);

            if ($name === 'refresh') {
                self::forgetLoadedAggregates($expr, $event);
            } elseif ($described !== null && $described[1]) {
                self::trackLoad($expr, $described[0], $event);
            }
        } elseif ($expr instanceof Assign) {
            self::trackAssignment($expr, $event);
        } elseif ($expr instanceof PropertyFetch) {
            self::overrideChainFetch($expr, $event);
        }

        return null;
    }

    /**
     * `$m->refresh()` reloads the attributes, so every aggregate loaded on `$m` is gone. A proven
     * count/exists fact is overwritten with its unproven type rather than unset: a branch merge
     * ignores keys missing from one side, so only an overwrite widens `if (...) { $m->refresh(); }`.
     */
    private static function forgetLoadedAggregates(MethodCall $call, AfterExpressionAnalysisEvent $event): void
    {
        $varId = self::varId(self::identityRoot($call->var));
        if ($varId === null) {
            return;
        }

        $context = $event->getContext();
        $model = self::singleModel($context->vars_in_scope[$varId] ?? null);
        $prefix = $varId . '->';

        foreach ($context->vars_in_scope as $key => $type) {
            if (!\str_starts_with($key, $prefix)) {
                continue;
            }

            $property = \substr($key, \strlen($prefix));
            $widened = $model === null || \str_contains($property, '->') || ModelPropertyHandler::resolveRawColumnType($model, $property) instanceof Union
                ? null
                : ModelAggregatePropertyHandler::unprovenType($type);

            if ($widened instanceof Union) {
                $context->vars_in_scope[$key] = $widened;
            } else {
                unset($context->vars_in_scope[$key]);
            }
        }
    }

    /**
     * `$m->loadCount('x')->refresh()` evaluates to `$m` itself: load*() and refresh() return `$this`.
     *
     * @psalm-mutation-free
     */
    private static function identityRoot(Expr $expr): Expr
    {
        while ($expr instanceof MethodCall && $expr->name instanceof Identifier) {
            $name = \strtolower($expr->name->name);
            if ($name !== 'refresh' && !(AggregateCallParser::describe($name)[1] ?? false)) {
                break;
            }

            $expr = $expr->var;
        }

        return $expr;
    }

    /** @param 'count'|'exists'|'sum'|'min'|'max'|'avg' $function */
    private static function trackLoad(MethodCall $call, string $function, AfterExpressionAnalysisEvent $event): void
    {
        // `$m->loadCount('a')->loadExists('b')`: the inner call already recorded `a`; both mutate `$m`.
        $receiver = self::identityRoot($call->var);

        $varId = self::varId($receiver);
        if ($varId === null) {
            return;
        }

        self::recordFacts($event, $varId, AggregateCallParser::entries($function, $call->isFirstClassCallable() ? [] : $call->getArgs()));
    }

    private static function trackAssignment(Assign $assign, AfterExpressionAnalysisEvent $event): void
    {
        $value = $assign->expr;
        if (!$value instanceof MethodCall && !$value instanceof NullsafeMethodCall && !$value instanceof StaticCall) {
            return;
        }

        $varId = self::varId($assign->var);
        if ($varId === null) {
            return;
        }

        self::recordFacts($event, $varId, self::chainEntries($value, $event));
    }

    private static function overrideChainFetch(PropertyFetch $fetch, AfterExpressionAnalysisEvent $event): void
    {
        $name = $fetch->name;
        if (!$name instanceof Identifier) {
            return;
        }

        $property = $name->name;
        $receiver = $fetch->var;
        if (
            (!$receiver instanceof MethodCall && !$receiver instanceof NullsafeMethodCall && !$receiver instanceof StaticCall)
            || (!\str_ends_with($property, '_count') && !\str_ends_with($property, '_exists'))
        ) {
            return;
        }

        $source = $event->getStatementsSource();
        $codebase = $event->getCodebase();
        $receiverType = $source->getNodeTypeProvider()->getType($receiver);
        $model = self::singleModel($receiverType);
        if ($model === null) {
            return;
        }

        $entry = self::chainEntries($receiver, $event)[$property] ?? null;
        $type = $entry instanceof AggregateEntry ? self::provenType($codebase, $model, $entry) : null;
        if ($type instanceof Union) {
            // A nullable receiver (`?->`, first()) short-circuits the read to null.
            $source->getNodeTypeProvider()->setType(
                $fetch,
                $receiverType?->isNullable() === true ? Type::combineUnionTypes($type, Type::getNull()) : $type,
            );
        }
    }

    /**
     * @param iterable<AggregateEntry> $entries in execution order: a later entry with the same alias wins
     */
    private static function recordFacts(AfterExpressionAnalysisEvent $event, string $varId, iterable $entries): void
    {
        $context = $event->getContext();
        $varType = $context->vars_in_scope[$varId] ?? null;
        $model = self::singleModel($varType);

        // A cached `$m->alias` is read before the receiver is checked, so a nullable `$m` would lose
        // its PossiblyNullPropertyFetch.
        if ($model === null || $varType?->isNullable() === true) {
            return;
        }

        foreach ($entries as $entry) {
            $type = self::provenType($event->getCodebase(), $model, $entry);
            if ($type instanceof Union) {
                $context->vars_in_scope[$varId . '->' . $entry->alias] = $type;
            }
        }
    }

    /**
     * Aggregates the chain provably loaded on the model it returns. Walks from the outermost call to
     * the root; collection hops and non-Builder calls end the walk, keeping what was collected.
     * Keyed by alias: calls execute innermost-first, so the outermost entry for an alias wins.
     *
     * @return array<string, AggregateEntry>
     */
    private static function chainEntries(Expr $expr, AfterExpressionAnalysisEvent $event): array
    {
        $entries = [];
        $buildingQuery = false;
        $types = null;

        while ($expr instanceof MethodCall || $expr instanceof NullsafeMethodCall || $expr instanceof StaticCall) {
            if (!$expr->name instanceof Identifier) {
                break;
            }

            $name = \strtolower($expr->name->name);
            $described = AggregateCallParser::describe($name);

            if ($described !== null) {
                // loadXxx() composes on a model; withXxx() only counts once the query is being built.
                if ($described[1] === $buildingQuery) {
                    break;
                }

                $call = [];
                foreach (AggregateCallParser::entries($described[0], $expr->isFirstClassCallable() ? [] : $expr->getArgs()) as $entry) {
                    $call[$entry->alias] = $entry;
                }

                $entries += $call;
            } elseif (!$buildingQuery) {
                if (!isset(self::RETRIEVAL_METHODS[$name])) {
                    break;
                }

                $buildingQuery = true;
            } else {
                $types ??= $event->getStatementsSource()->getNodeTypeProvider();
                if (isset(self::COLUMN_REPLACING_METHODS[$name]) || !self::isQueryType($types->getType($expr))) {
                    break;
                }
            }

            $expr = $expr instanceof StaticCall ? null : $expr->var;
        }

        return $entries;
    }

    /** Relation check against the final model + user `@property` precedence; null declines. */
    private static function provenType(Codebase $codebase, string $model, AggregateEntry $entry): ?Union
    {
        try {
            if (
                !ModelAggregatePropertyHandler::isRelationMethod($codebase, $model, $entry->relation)
                || ModelAggregatePropertyHandler::hasUserPseudoProperty($codebase, $model, $entry->alias)
            ) {
                return null;
            }

            return ModelAggregatePropertyHandler::aggregateType(
                $codebase,
                $entry->function,
                $model,
                $entry->relation,
                $entry->column,
                true,
            );
        } catch (\InvalidArgumentException|UnpopulatedClasslikeException) {
            return null;
        }
    }

    /** @psalm-mutation-free */
    private static function isQueryType(?Union $type): bool
    {
        if (!$type instanceof Union) {
            return false;
        }

        foreach ($type->getAtomicTypes() as $atomic) {
            if (
                !$atomic instanceof TNamedObject
                || (!\is_a($atomic->value, Builder::class, true) && !\is_a($atomic->value, Relation::class, true))
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * The one Model class the (possibly nullable) type names. Anything else — unions of models, the
     * bare base Model, mixed — declines, since a relation proven on one class says nothing about another.
     *
     * @return class-string<Model>|null
     * @psalm-mutation-free
     */
    private static function singleModel(?Union $type): ?string
    {
        $model = null;

        foreach ($type?->getAtomicTypes() ?? [] as $atomic) {
            if ($atomic instanceof TNull) {
                continue;
            }

            if (
                !$atomic instanceof TNamedObject
                || ($model !== null && $model !== $atomic->value)
                || !\is_a($atomic->value, Model::class, true)
            ) {
                return null;
            }

            $model = $atomic->value;
        }

        return $model;
    }

    /**
     * Psalm's extended var id for `$var` / `$var->prop->…`; null for anything else.
     *
     * @psalm-mutation-free
     */
    private static function varId(Expr $expr): ?string
    {
        if ($expr instanceof Variable) {
            return \is_string($expr->name) ? '$' . $expr->name : null;
        }

        if ($expr instanceof PropertyFetch && $expr->name instanceof Identifier) {
            $base = self::varId($expr->var);

            return $base === null ? null : $base . '->' . $expr->name->name;
        }

        return null;
    }
}
