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
use Psalm\Internal\Analyzer\Statements\Expression\NullsafeChainState;
use Psalm\Internal\Type\TypeExpander;
use Psalm\Issue\UndefinedMagicPropertyFetch;
use Psalm\Issue\UndefinedPropertyFetch;
use Psalm\LaravelPlugin\Internal\ClassLineage;
use Psalm\Plugin\EventHandler\AfterExpressionAnalysisInterface;
use Psalm\Plugin\EventHandler\BeforeAddIssueInterface;
use Psalm\Plugin\EventHandler\BeforeExpressionAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterExpressionAnalysisEvent;
use Psalm\Plugin\EventHandler\Event\BeforeAddIssueEvent;
use Psalm\Plugin\EventHandler\Event\BeforeExpressionAnalysisEvent;
use Psalm\StatementsSource;
use Psalm\Type;
use Psalm\Type\Atomic;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TNull;
use Psalm\Type\Atomic\TObjectWithProperties;
use Psalm\Type\Union;

/**
 * Resolves a property fetch on a Model intersected with an object shape: `Pivot&object{role: string}`
 * (the refined `withPivot()` pivot) and `Related&object{pivot: P}` (BelongsToMany results) (#1944).
 *
 * Why a handler: vimeo/psalm#12110. `AtomicPropertyFetchAnalyzer` reaches the intersection fallback only in
 * the `elseif` of `if ($class_storage->namedMixins)`, and skips final classes; Eloquent's Model stub carries
 * `@mixin Builder`, so the shape is never consulted and the fetch reports UndefinedMagicPropertyFetch.
 * Property providers carry no receiver, so they cannot do this.
 *
 * Why not rewrite the receiver to `object{...}&Model` for the fetch: Psalm's object-shape branch returns before
 * processTaints and before the virtual `__get` call, so the taint edges of a `@property` pseudo-property and
 * the ImpureMethodCall of `Model::__get` inside a pure function would vanish. The fetch is therefore analysed
 * on the unchanged receiver, and the handler only does the two things the shape is for:
 * - After(receiver) decides whether the fetch qualifies and records its location; BeforeAddIssue drops
 *   UndefinedMagicPropertyFetch / UndefinedPropertyFetch at exactly that location;
 * - After(fetch) retypes the fetch (node type and scoped `$var->prop` type) to the shape's type, keeping the
 *   `parent_nodes` Psalm computed.
 *
 * Qualifying: every atomic of the receiver is `null` or a Model that does not itself resolve the property
 * (`propertyExists`, which covers native, accessor and column providers but not `@property`) and whose
 * intersection holds a shape declaring it; any other mix is left to Psalm. This mirrors the precedence of
 * vimeo/psalm#12111: native or provider property, then shape, then `@property` / mixin. The null of a
 * nullable receiver is added as `InstancePropertyFetchAnalyzer` adds it.
 *
 * Removal: drop once the minimum supported Psalm contains the vimeo/psalm#12110 fix (#12111 or its 7.x port).
 *
 * @internal
 */
final class ModelObjectShapePropertyFetchHandler implements
    BeforeExpressionAnalysisInterface,
    AfterExpressionAnalysisInterface,
    BeforeAddIssueInterface
{
    /**
     * Per receiver node of a fetch under analysis: the fetch's location and property, then, once qualified,
     * the shape type. Holds no AST node as a value: a WeakMap value is a strong reference and the fetch holds
     * its receiver key.
     *
     * @psalm-var \WeakMap<Expr, array{property: string, start: int, end: int, shape: ?Union, nullable: bool, ignoreNullable: bool}>|null
     */
    private static ?\WeakMap $fetches = null;

    /**
     * Locations (`file:start:end`) of qualified fetches between After(receiver) and After(fetch).
     *
     * @var array<string, true>
     */
    private static array $locations = [];

    /**
     * @return \WeakMap<Expr, array{property: string, start: int, end: int, shape: ?Union, nullable: bool, ignoreNullable: bool}>
     *
     * @psalm-pure
     */
    private static function newFetchMap(): \WeakMap
    {
        /** @psalm-var \WeakMap<Expr, array{property: string, start: int, end: int, shape: ?Union, nullable: bool, ignoreNullable: bool}> $map */
        $map = new \WeakMap();

        return $map;
    }

    public static function reset(): void
    {
        self::$fetches = null;
        self::$locations = [];
    }

    #[\Override]
    public static function beforeExpressionAnalysis(BeforeExpressionAnalysisEvent $event): ?bool
    {
        $expr = $event->getExpr();

        if ($expr instanceof PropertyFetch && $expr->name instanceof Identifier) {
            $fetches = self::$fetches ??= self::newFetchMap();
            $fetches[$expr->var] = [
                'property' => $expr->name->name,
                'start' => (int) $expr->getAttribute('startFilePos'),
                'end' => (int) $expr->getAttribute('endFilePos'),
                'shape' => null,
                'nullable' => false,
                'ignoreNullable' => false,
            ];
        }

        return null;
    }

    #[\Override]
    public static function afterExpressionAnalysis(AfterExpressionAnalysisEvent $event): ?bool
    {
        $fetches = self::$fetches;
        if (!$fetches instanceof \WeakMap) {
            return null;
        }

        $expr = $event->getExpr();

        // The fetch first: as the receiver of an outer fetch its type must be final before that one is decided.
        if ($expr instanceof PropertyFetch) {
            self::retypeFetch($event, $expr, $fetches);
        }

        $pending = $fetches[$expr] ?? null;
        if ($pending === null || $pending['shape'] instanceof Union) {
            return null;
        }

        $source = $event->getStatementsSource();
        $context = $event->getContext();

        $receiver = self::receiverType($expr, $source, $context);
        $shape = $receiver instanceof Union
            ? self::shapeType($event->getCodebase(), $source, $context, $receiver, $pending['property'])
            : null;

        if (!$receiver instanceof Union
            || !$shape instanceof Union
            || !self::isReadForTheFirstTime($expr, $pending['property'], $source, $context)
        ) {
            $fetches->offsetUnset($expr);

            return null;
        }

        $fetches[$expr] = [
            'property' => $pending['property'],
            'start' => $pending['start'],
            'end' => $pending['end'],
            'shape' => $shape,
            'nullable' => $receiver->isNullable(),
            'ignoreNullable' => $receiver->ignore_nullable_issues,
        ];
        self::$locations[self::locationKey($source->getFilePath(), $pending['start'], $pending['end'])] = true;

        return null;
    }

    #[\Override]
    public static function beforeAddIssue(BeforeAddIssueEvent $event): ?bool
    {
        if (self::$locations === []) {
            return null;
        }

        $issue = $event->getIssue();
        if (!$issue instanceof UndefinedMagicPropertyFetch && !$issue instanceof UndefinedPropertyFetch) {
            return null;
        }

        $location = $issue->code_location;
        $key = self::locationKey($location->file_path, $location->raw_file_start, $location->raw_file_end);

        return isset(self::$locations[$key]) ? false : null;
    }

    /**
     * @param \WeakMap<Expr, array{property: string, start: int, end: int, shape: ?Union, nullable: bool, ignoreNullable: bool}> $fetches
     */
    private static function retypeFetch(
        AfterExpressionAnalysisEvent $event,
        PropertyFetch $fetch,
        \WeakMap $fetches,
    ): void {
        $entry = $fetches[$fetch->var] ?? null;
        $shape = $entry['shape'] ?? null;

        // A receiver node can be shared by a nullsafe fetch and its virtual twin: only the entry recorded for this fetch counts.
        if ($entry === null
            || !$shape instanceof Union
            || $entry['start'] !== (int) $fetch->getAttribute('startFilePos')
            || $entry['end'] !== (int) $fetch->getAttribute('endFilePos')
        ) {
            return;
        }

        $source = $event->getStatementsSource();
        $fetches->offsetUnset($fetch->var);
        unset(self::$locations[self::locationKey($source->getFilePath(), $entry['start'], $entry['end'])]);

        $context = $event->getContext();
        $types = $source->getNodeTypeProvider();
        $psalmType = $types->getType($fetch);

        // Mirrors InstancePropertyFetchAnalyzer: the null of a nullable receiver is part of the result, except
        // when isset()/`??` skips it for a receiver that is not a `?->` chain link.
        $chain = NullsafeChainState::of($fetch->var);
        $shortCircuit = $entry['nullable'] && $chain !== NullsafeChainState::None;
        $builder = $shape->getBuilder();

        if ($shortCircuit) {
            $chain->afterLink($shape->isNullable())->markOn($fetch);
        }

        if ($entry['nullable'] && ($shortCircuit || !$context->inside_isset || !$entry['ignoreNullable'])) {
            $builder->addType(new TNull());
            $builder->ignore_nullable_issues = $entry['ignoreNullable'];
        }

        if ($psalmType instanceof Union) {
            $builder->parent_nodes = $psalmType->parent_nodes;
        }

        $result = $builder->freeze();
        $types->setType($fetch, $result);

        $varId = ExpressionIdentifier::getExtendedVarId($fetch, $source->getFQCLN(), $source);
        if ($varId !== null) {
            $context->vars_in_scope[$varId] = $result;
        }
    }

    /**
     * The receiver type where `InstancePropertyFetchAnalyzer` reads it: the scoped variable, else the node type.
     */
    private static function receiverType(
        Expr $receiver,
        StatementsSource $source,
        Context $context,
    ): ?Union {
        $key = ExpressionIdentifier::getExtendedVarId($receiver, $source->getFQCLN(), $source);
        if ($key !== null && isset($context->vars_in_scope[$key])) {
            return $context->vars_in_scope[$key];
        }

        return $source->getNodeTypeProvider()->getType($receiver);
    }

    /**
     * A fetch whose `$var->prop` is already scoped (assigned or read before) takes Psalm's scoped path, which
     * checks nothing and keeps that type.
     */
    private static function isReadForTheFirstTime(
        Expr $receiver,
        string $property,
        StatementsSource $source,
        Context $context,
    ): bool {
        $receiverId = ExpressionIdentifier::getExtendedVarId($receiver, $source->getFQCLN(), $source);

        return $receiverId === null || !isset($context->vars_in_scope[$receiverId . '->' . $property]);
    }

    /**
     * The type of the shape property, or null unless every atomic is null or a qualifying Model.
     */
    private static function shapeType(
        Codebase $codebase,
        StatementsSource $source,
        Context $context,
        Union $receiver,
        string $property,
    ): ?Union {
        $result = null;

        foreach ($receiver->getAtomicTypes() as $atomic) {
            if ($atomic instanceof TNull) {
                continue;
            }

            $shape = self::shapePropertyType($codebase, $source, $context, $atomic, $property);
            if (!$shape instanceof Union) {
                return null;
            }

            $result = Type::combineUnionTypes($shape, $result);
        }

        return $result;
    }

    private static function shapePropertyType(
        Codebase $codebase,
        StatementsSource $source,
        Context $context,
        Atomic $atomic,
        string $property,
    ): ?Union {
        if (!$atomic instanceof TNamedObject || $atomic->getIntersectionTypes() === []) {
            return null;
        }

        $declared = null;
        foreach ($atomic->getIntersectionTypes() as $extra) {
            if ($extra instanceof TObjectWithProperties && isset($extra->properties[$property])) {
                $declared = $extra->properties[$property];
                break;
            }
        }

        if (!$declared instanceof Union || !ClassLineage::isA($codebase, $atomic->value, Model::class)) {
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

        // The expansion Psalm applies to a property of an object shape.
        return TypeExpander::expandUnion($codebase, $declared, null, null, null, true, true, false, true, false, true);
    }

    /**
     * @psalm-pure
     */
    private static function locationKey(string $filePath, int $start, int $end): string
    {
        return $filePath . ':' . $start . ':' . $end;
    }
}
