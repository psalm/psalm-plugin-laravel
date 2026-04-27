<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Database;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use Psalm\Codebase;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Plugin\EventHandler\AfterExpressionAnalysisInterface;
use Psalm\Plugin\EventHandler\BeforeExpressionAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AddRemoveTaintsEvent;
use Psalm\Plugin\EventHandler\Event\AfterExpressionAnalysisEvent;
use Psalm\Plugin\EventHandler\Event\BeforeExpressionAnalysisEvent;
use Psalm\Plugin\EventHandler\RemoveTaintsInterface;
use Psalm\StatementsSource;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\TaintKind;
use Psalm\Type\Union;

/**
 * Keeps the where-family `$column` sink for tainted column names while
 * stripping only SQL taint from array-form VALUES:
 *
 *     $builder->where(['status_id' => $tainted]);     // safe, PDO-bound value
 *     $builder->where([$taintedColumn => 'value']);   // unsafe, still reported
 *
 * The sink stays in the stubs so string-form column identifiers continue to
 * report. This handler only marks the VALUE expressions inside the first-arg
 * associative array as safe for SQL when the call targets Laravel's
 * Query/Eloquent where-family methods.
 */
final class WhereArrayValueTaintHandler implements
    BeforeExpressionAnalysisInterface,
    AfterExpressionAnalysisInterface,
    RemoveTaintsInterface
{
    /** @var array<string, true> */
    private const WHERE_FAMILY_METHODS = [
        'where' => true,
        'orWhere' => true,
        'whereNot' => true,
        'orWhereNot' => true,
        'having' => true,
        'orHaving' => true,
    ];

    /** @var array<int, int> */
    private static array $pendingExprIds = [];

    /** @var array<int, list<int>> */
    private static array $pendingExprIdsByCall = [];

    /** @inheritDoc */
    #[\Override]
    public static function beforeExpressionAnalysis(BeforeExpressionAnalysisEvent $event): ?bool
    {
        $expr = $event->getExpr();
        $valueExprIds = self::collectSafeValueExprIds($expr, $event->getStatementsSource(), $event->getCodebase());

        if ($valueExprIds === []) {
            return null;
        }

        self::$pendingExprIdsByCall[\spl_object_id($expr)] = $valueExprIds;

        foreach ($valueExprIds as $exprId) {
            self::$pendingExprIds[$exprId] = (self::$pendingExprIds[$exprId] ?? 0) + 1;
        }

        return null;
    }

    /** @inheritDoc */
    #[\Override]
    public static function afterExpressionAnalysis(AfterExpressionAnalysisEvent $event): ?bool
    {
        $callId = \spl_object_id($event->getExpr());
        $valueExprIds = self::$pendingExprIdsByCall[$callId] ?? null;

        if ($valueExprIds === null) {
            return null;
        }

        unset(self::$pendingExprIdsByCall[$callId]);

        foreach ($valueExprIds as $exprId) {
            if (!isset(self::$pendingExprIds[$exprId])) {
                continue;
            }

            if (self::$pendingExprIds[$exprId] === 1) {
                unset(self::$pendingExprIds[$exprId]);
                continue;
            }

            --self::$pendingExprIds[$exprId];
        }

        return null;
    }

    #[\Override]
    public static function removeTaints(AddRemoveTaintsEvent $event): int
    {
        return isset(self::$pendingExprIds[\spl_object_id($event->getExpr())]) ? TaintKind::INPUT_SQL : 0;
    }

    /**
     * @return list<int>
     */
    private static function collectSafeValueExprIds(
        Expr $expr,
        StatementsSource $source,
        Codebase $codebase,
    ): array {
        if (!$expr instanceof MethodCall && !$expr instanceof StaticCall) {
            return [];
        }

        if (!$expr->name instanceof Identifier || !isset(self::WHERE_FAMILY_METHODS[$expr->name->name])) {
            return [];
        }

        $args = $expr->getArgs();

        if (!isset($args[0]) || !$args[0]->value instanceof Array_) {
            return [];
        }

        if (!self::targetsLaravelWhereFamily($expr, $source, $codebase)) {
            return [];
        }

        $valueExprIds = [];

        foreach ($args[0]->value->items as $item) {
            if ($item === null || $item->key === null) {
                continue;
            }

            $valueExprIds[] = \spl_object_id($item->value);
        }

        return $valueExprIds;
    }

    private static function targetsLaravelWhereFamily(
        MethodCall|StaticCall $expr,
        StatementsSource $source,
        Codebase $codebase,
    ): bool {
        if ($expr instanceof MethodCall) {
            return self::methodCallerIsLaravelBuilder($expr, $source, $codebase);
        }

        return self::staticCallerIsModel($expr, $codebase)
            && !self::hasCustomModelMethod($expr, $codebase);
    }

    private static function methodCallerIsLaravelBuilder(
        MethodCall $expr,
        StatementsSource $source,
        Codebase $codebase,
    ): bool {
        if (!$source instanceof StatementsAnalyzer) {
            return false;
        }

        $callerType = $source->node_data->getType($expr->var);

        if (!$callerType instanceof Union) {
            return false;
        }

        foreach ($callerType->getAtomicTypes() as $atomic) {
            if (!$atomic instanceof TNamedObject) {
                continue;
            }

            $className = $atomic->value;

            if (in_array($className, [QueryBuilder::class, EloquentBuilder::class, Relation::class], true)
            ) {
                return true;
            }

            try {
                if ($codebase->classExtends($className, QueryBuilder::class)
                    || $codebase->classExtends($className, EloquentBuilder::class)
                    || $codebase->classExtends($className, Relation::class)
                ) {
                    return true;
                }
            } catch (\Psalm\Exception\UnpopulatedClasslikeException|\InvalidArgumentException) {
                continue;
            }
        }

        return false;
    }

    private static function staticCallerIsModel(StaticCall $expr, Codebase $codebase): bool
    {
        if (!$expr->class instanceof Name) {
            return false;
        }

        $className = $expr->class->getAttribute('resolvedName');

        if (!\is_string($className)) {
            return false;
        }

        if ($className === Model::class) {
            return true;
        }

        if (!$codebase->classExists($className)) {
            return false;
        }

        try {
            return $codebase->classExtends($className, Model::class);
        } catch (\Psalm\Exception\UnpopulatedClasslikeException|\InvalidArgumentException) {
            return false;
        }
    }

    private static function hasCustomModelMethod(StaticCall $expr, Codebase $codebase): bool
    {
        if (!$expr->class instanceof Name || !$expr->name instanceof Identifier) {
            return false;
        }

        $className = $expr->class->getAttribute('resolvedName');

        if (!\is_string($className)) {
            return false;
        }

        try {
            $classStorage = $codebase->classlike_storage_provider->get(\strtolower($className));
        } catch (\InvalidArgumentException) {
            return false;
        }

        $methodName = \strtolower($expr->name->name);
        $declaringId = $classStorage->declaring_method_ids[$methodName] ?? null;

        if ($declaringId === null) {
            return false;
        }

        return $declaringId->fq_class_name !== Model::class;
    }
}
