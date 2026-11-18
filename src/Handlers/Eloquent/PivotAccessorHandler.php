<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use PhpParser\Node\Expr\MethodCall;
use Psalm\Plugin\EventHandler\AfterMethodCallAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterMethodCallAnalysisEvent;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TObjectWithProperties;
use Psalm\Type\MutableTypeVisitor;
use Psalm\Type\TypeNode;
use Psalm\Type\Union;

/**
 * Renames the pivot key of the `TRelatedModel&object{pivot: TPivotModel}` a BelongsToMany / MorphToMany call returns to
 * the relation's `as()` accessor (TAccessor, slot 4): after `->as('subscription')`, `first()` returns
 * `Plan&object{subscription: P}`. Psalm reads an object-shape key literally, so the stubs cannot write
 * `object{TAccessor: TPivotModel}`; Laravel 13.41+ docblocks do and get a property named `TAccessor`, which this
 * handler renames too (to `pivot` for the default accessor).
 *
 * Runs after Psalm resolved the call, so it covers every method (stub, vendor docblock, Relation's TResult, chained
 * receivers) without a method list. Declines unless the receiver is exactly one BelongsToMany / MorphToMany whose
 * TAccessor is one literal property name and the method is Laravel's own; only a single-property shape whose value is
 * the receiver's own TPivotModel is renamed.
 *
 * Known gaps (Issue2348PivotAccessorKnownLimitation.phpt): callback params (`chunk()`, `each()`) keep `pivot`, since
 * Psalm types a closure before the call resolves; first-class callables skip this hook; a `firstOr()` / `findOr()`
 * callback returning the same `M&object{pivot: P}` is renamed with the relation's result.
 *
 * @todo-by https://github.com/vimeo/psalm/issues/14196 remove this handler and its Plugin.php registration once Psalm resolves template-valued shape keys; write object{TAccessor: TPivotModel} in the stubs instead
 *
 * @internal
 */
final class PivotAccessorHandler implements AfterMethodCallAnalysisInterface
{
    private const RELATIONS = [
        'illuminate\database\eloquent\relations\belongstomany' => true,
        'illuminate\database\eloquent\relations\morphtomany' => true,
    ];

    /** Declaring classes whose returns carry the pivot shape; a method declared anywhere else keeps its own type. */
    private const DECLARING_CLASSES = self::RELATIONS + [
        'illuminate\database\eloquent\relations\relation' => true,
    ];

    #[\Override]
    public static function afterMethodCallAnalysis(AfterMethodCallAnalysisEvent $event): void
    {
        $candidate = $event->getReturnTypeCandidate();
        $expr = $event->getExpr();
        if (!$candidate instanceof Union || !$expr instanceof MethodCall
            || !isset(self::RELATIONS[\strtolower(\explode('::', $event->getMethodId(), 2)[0])])
            || !isset(self::DECLARING_CLASSES[\strtolower(\explode('::', $event->getDeclaringMethodId(), 2)[0])])
        ) {
            return;
        }

        $receiver = $event->getStatementsSource()->getNodeTypeProvider()->getType($expr->var);
        $relation = $receiver?->isSingle() === true ? $receiver->getSingleAtomic() : null;
        $accessor = $relation instanceof TGenericObject ? self::accessor($relation) : null;
        if ($accessor === null || !$relation instanceof TGenericObject) {
            return;
        }

        $event->setReturnTypeCandidate(self::rename($candidate, $relation->type_params[2], $accessor));
    }

    /**
     * The single literal TAccessor of an exact BelongsToMany / MorphToMany type, or null when it is not provable.
     *
     * @psalm-pure
     */
    public static function accessor(TGenericObject $relation): ?string
    {
        if (!isset(self::RELATIONS[\strtolower($relation->value)]) || \count($relation->type_params) !== 4) {
            return null;
        }

        // Not isSingle(): Psalm counts a union of string literals (`'a'|'b'`) as single.
        $accessor = $relation->type_params[3]->isSingleStringLiteral() ? $relation->type_params[3]->getSingleStringLiteral()->value : null;

        // A key that is not a plain property name (`as('a-b')`, `as('0')`) declines rather than print a quoted shape key.
        return $accessor !== null && \preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $accessor) === 1 ? $accessor : null;
    }

    private static function rename(Union $type, Union $pivot, string $accessor): Union
    {
        $visitor = new class ($pivot->getId(), $accessor) extends MutableTypeVisitor {
            public function __construct(private readonly string $pivot, private readonly string $accessor) {}

            /** @psalm-capabilities read-props|write-this-props|write-refs */
            #[\Override]
            protected function enterNode(TypeNode &$type): ?int
            {
                if (!$type instanceof TObjectWithProperties || $type->methods !== [] || \count($type->properties) !== 1) {
                    return null;
                }

                $key = \array_key_first($type->properties);
                $value = $type->properties[$key];
                if ($key === $this->accessor || ($key !== 'pivot' && $key !== 'TAccessor') || $value->getId() !== $this->pivot) {
                    return null;
                }

                $type = $type->setProperties([$this->accessor => $value]);

                return self::DONT_TRAVERSE_CHILDREN;
            }
        };

        $visitor->traverse($type);

        return $type;
    }
}
