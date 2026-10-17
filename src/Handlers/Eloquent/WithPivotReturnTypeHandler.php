<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Union;

/**
 * @internal
 *
 * Psalm's native mixin-first property lookup ignores object-shape intersections for `$related->pivot`;
 * see Issue1944ObjectShapePivotReadKnownLimitation.phpt and https://github.com/vimeo/psalm/pull/12111.
 */
final class WithPivotReturnTypeHandler implements MethodReturnTypeProviderInterface
{
    /** @psalm-pure */
    #[\Override]
    public static function getClassLikeNames(): array
    {
        return [BelongsToMany::class, MorphToMany::class];
    }

    #[\Override]
    public static function getMethodReturnType(MethodReturnTypeProviderEvent $event): ?Union
    {
        if ($event->getMethodNameLowercase() !== 'withpivot') {
            return null;
        }

        $source = $event->getSource();
        $stmt = $event->getStmt();
        if (!$source instanceof StatementsAnalyzer || !$stmt instanceof \PhpParser\Node\Expr\MethodCall) {
            return null;
        }

        $receiver = $source->getNodeTypeProvider()->getType($stmt->var);
        if (!$receiver instanceof Union || !$receiver->isSingle()) {
            return null;
        }

        $atomic = $receiver->getSingleAtomic();
        if (
            !$atomic instanceof TGenericObject
            || !\in_array(\strtolower($atomic->value), [\strtolower(BelongsToMany::class), \strtolower(MorphToMany::class)], true)
            || $atomic->extra_types !== []
            || \count($atomic->type_params) < 4
        ) {
            return null;
        }

        $description = PivotColumnTypeResolver::describe(
            $stmt,
            $source->getCodebase(),
            $source,
            $event->getContext()->self,
        );
        if ($description === null || $description['columns'] === null) {
            return null;
        }

        $refinedPivot = PivotColumnTypeResolver::refinePivot(
            $atomic->type_params[2],
            $description['table'],
            $description['columns'],
        );

        if ($refinedPivot->equals($atomic->type_params[2])) {
            return null;
        }

        $typeParams = $atomic->type_params;
        $typeParams[2] = $refinedPivot;
        $refined = new TGenericObject(
            $atomic->value,
            $typeParams,
            $atomic->remapped_params,
            $atomic->is_static,
            $atomic->extra_types,
            $atomic->from_docblock,
        );

        return $receiver->setTypes([$refined]);
    }
}
