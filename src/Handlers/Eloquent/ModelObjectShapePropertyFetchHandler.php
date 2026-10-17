<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use Illuminate\Database\Eloquent\Model;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Identifier;
use Psalm\Codebase;
use Psalm\Context;
use Psalm\Exception\UnpopulatedClasslikeException;
use Psalm\Internal\Analyzer\Statements\Expression\ExpressionIdentifier;
use Psalm\LaravelPlugin\Internal\ClassLineage;
use Psalm\NodeTypeProvider;
use Psalm\Plugin\EventHandler\AfterExpressionAnalysisInterface;
use Psalm\Plugin\EventHandler\AfterStatementAnalysisInterface;
use Psalm\Plugin\EventHandler\BeforeExpressionAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterExpressionAnalysisEvent;
use Psalm\Plugin\EventHandler\Event\AfterStatementAnalysisEvent;
use Psalm\Plugin\EventHandler\Event\BeforeExpressionAnalysisEvent;
use Psalm\StatementsSource;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TObjectWithProperties;
use Psalm\Type\Union;

/**
 * Resolves a property fetch on a Model intersected with an object shape: `Pivot&object{role: string}`
 * (the refined `withPivot()` pivot) and `Related&object{pivot: P}` (BelongsToMany results) (#1944).
 *
 * Why a handler: vimeo/psalm#12110. `AtomicPropertyFetchAnalyzer` reaches the intersection fallback only in
 * the `elseif` of `if ($class_storage->namedMixins)`, and skips final classes; Eloquent's Model stub carries
 * `@mixin Builder`, so the shape is never consulted and the fetch reports UndefinedMagicPropertyFetch. A
 * shape-FIRST intersection (`object{p: T}&Model`) resolves, but breaks method calls on the value, so the
 * receiver is rewritten only while the fetch is analysed. Property providers carry no receiver, so they
 * cannot do this.
 *
 * Mechanics: Before(fetch) remembers the property name per receiver node. After(receiver) rewrites the
 * receiver type exactly where `InstancePropertyFetchAnalyzer` will read it (the scoped variable, else the
 * node type) and After(fetch) puts the original back. Only a Model atomic that does NOT declare the property
 * itself, and whose intersection holds a shape declaring it, is rewritten; every other atomic (`null`, other
 * classes) is kept, so Psalm's own checks (PossiblyNullPropertyFetch, ...) still run. The statement hook is a
 * safety net for a fetch that never reaches its After event.
 *
 * Removal: drop once the minimum supported Psalm contains the vimeo/psalm#12110 fix (#12111 or its 7.x port).
 *
 * @internal
 */
final class ModelObjectShapePropertyFetchHandler implements
    BeforeExpressionAnalysisInterface,
    AfterExpressionAnalysisInterface,
    AfterStatementAnalysisInterface
{
    /**
     * Property name per receiver node of a fetch about to be analysed. Holds a string, not the fetch: a
     * WeakMap value is a strong reference, and the fetch holds its receiver key.
     *
     * @psalm-var \WeakMap<Expr, string>|null
     */
    private static ?\WeakMap $pendingReceivers = null;

    /**
     * Rewrites awaiting restore, keyed by the receiver node's object id.
     *
     * @var array<int, array{context: Context, key: ?string, types: NodeTypeProvider, node: Expr, original: Union, rewritten: Union}>
     */
    private static array $active = [];

    /**
     * @return \WeakMap<Expr, string>
     *
     * @psalm-pure
     */
    private static function newReceiverMap(): \WeakMap
    {
        /** @psalm-var \WeakMap<Expr, string> $map */
        $map = new \WeakMap();

        return $map;
    }

    public static function reset(): void
    {
        self::$pendingReceivers = null;
        self::$active = [];
    }

    #[\Override]
    public static function beforeExpressionAnalysis(BeforeExpressionAnalysisEvent $event): ?bool
    {
        $expr = $event->getExpr();

        if ($expr instanceof PropertyFetch && $expr->name instanceof Identifier) {
            $pending = self::$pendingReceivers ??= self::newReceiverMap();
            $pending[$expr->var] = $expr->name->name;
        }

        return null;
    }

    #[\Override]
    public static function afterExpressionAnalysis(AfterExpressionAnalysisEvent $event): ?bool
    {
        $expr = $event->getExpr();

        // The fetch is done (also as the receiver of an outer fetch, handled below): put its receiver back.
        if (self::$active !== [] && $expr instanceof PropertyFetch) {
            self::restore(\spl_object_id($expr->var));
        }

        $pending = self::$pendingReceivers;
        if (!$pending instanceof \WeakMap || !$pending->offsetExists($expr)) {
            return null;
        }

        $property = $pending[$expr] ?? null;
        $pending->offsetUnset($expr);
        if ($property === null) {
            return null;
        }

        $source = $event->getStatementsSource();
        $context = $event->getContext();

        // Mirrors where InstancePropertyFetchAnalyzer reads the receiver type.
        $key = ExpressionIdentifier::getExtendedVarId($expr, $source->getFQCLN(), $source);
        $types = $source->getNodeTypeProvider();
        if ($key !== null && isset($context->vars_in_scope[$key])) {
            $original = $context->vars_in_scope[$key];
        } else {
            $key = null;
            $original = $types->getType($expr);
        }

        if (!$original instanceof Union) {
            return null;
        }

        $rewritten = self::rewriteReceiver($event->getCodebase(), $source, $context, $original, $property);
        if (!$rewritten instanceof Union) {
            return null;
        }

        if ($key !== null) {
            $context->vars_in_scope[$key] = $rewritten;
        } else {
            $types->setType($expr, $rewritten);
        }

        self::$active[\spl_object_id($expr)] = [
            'context' => $context,
            'key' => $key,
            'types' => $types,
            'node' => $expr,
            'original' => $original,
            'rewritten' => $rewritten,
        ];

        return null;
    }

    #[\Override]
    public static function afterStatementAnalysis(AfterStatementAnalysisEvent $event): ?bool
    {
        foreach (\array_keys(self::$active) as $id) {
            self::restore($id);
        }

        return null;
    }

    /**
     * Puts the original receiver type back unless something else has already replaced the rewritten one.
     */
    private static function restore(int $id): void
    {
        $rewrite = self::$active[$id] ?? null;
        if ($rewrite === null) {
            return;
        }

        unset(self::$active[$id]);

        if ($rewrite['key'] !== null) {
            if (($rewrite['context']->vars_in_scope[$rewrite['key']] ?? null) === $rewrite['rewritten']) {
                $rewrite['context']->vars_in_scope[$rewrite['key']] = $rewrite['original'];
            }
        } elseif ($rewrite['types']->getType($rewrite['node']) === $rewrite['rewritten']) {
            $rewrite['types']->setType($rewrite['node'], $rewrite['original']);
        }
    }

    /**
     * The receiver with each qualifying atomic replaced by `object{...shape}&Model`, or null when none
     * qualifies. Union flags (including taint `parent_nodes`) are kept by the builder.
     */
    private static function rewriteReceiver(
        Codebase $codebase,
        StatementsSource $source,
        Context $context,
        Union $receiver,
        string $property,
    ): ?Union {
        $builder = null;

        foreach ($receiver->getAtomicTypes() as $key => $atomic) {
            $replacement = self::rewriteAtomic($codebase, $source, $context, $atomic, $property);
            if ($replacement === null) {
                continue;
            }

            $builder ??= $receiver->getBuilder();
            $builder->removeType($key);
            $builder->addType($replacement);
        }

        return $builder?->freeze();
    }

    private static function rewriteAtomic(
        Codebase $codebase,
        StatementsSource $source,
        Context $context,
        \Psalm\Type\Atomic $atomic,
        string $property,
    ): ?TObjectWithProperties {
        if (!$atomic instanceof TNamedObject || $atomic->getIntersectionTypes() === []) {
            return null;
        }

        $properties = [];
        $others = [];
        foreach ($atomic->getIntersectionTypes() as $extraKey => $extra) {
            if ($extra instanceof TObjectWithProperties && isset($extra->properties[$property])) {
                $properties = \array_merge($properties, $extra->properties);
            } else {
                $others[$extraKey] = $extra;
            }
        }

        if ($properties === [] || !ClassLineage::isA($codebase, $atomic->value, Model::class)) {
            return null;
        }

        // A property the class declares (or a plugin proves, e.g. a schema column) resolves to its own type.
        try {
            if ($codebase->propertyExists($atomic->value . '::$' . $property, true, $source, $context)) {
                return null;
            }
        } catch (\InvalidArgumentException|UnpopulatedClasslikeException) {
            return null;
        }

        $bare = $atomic->setIntersectionTypes([]);

        return new TObjectWithProperties($properties, [], [$bare->getKey() => $bare] + $others);
    }
}
