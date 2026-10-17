<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Concerns\InteractsWithPivotTable;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use PhpParser\Node\Expr\MethodCall;
use Psalm\Codebase;
use Psalm\Exception\UnpopulatedClasslikeException;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\LaravelPlugin\Handlers\Eloquent\Support\PivotColumnArgs;
use Psalm\LaravelPlugin\Handlers\Eloquent\Support\PivotColumnTypes;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TTemplateParam;
use Psalm\Type\Union;

/**
 * Refines the pivot slot (3rd template param) of a many-to-many relation after `withPivot()` /
 * `withTimestamps()`: `BelongsToMany<R, P, Pivot, 'pivot'>` becomes `BelongsToMany<R, P, Pivot&object{role: ...}, 'pivot'>`,
 * so a declared `Pivot&object{role: string}` return type matches the chained call (#1944).
 *
 * Laravel's `withPivot()` returns `$this`, so the stub return type carries no information about the added
 * columns; the receiver's own generic is re-emitted with a refined slot instead (`setTypeParams()` keeps
 * `is_static` and intersection types of the receiver atom). `withPivot()` is declared on the
 * `InteractsWithPivotTable` trait, which is the only registration that fires for `MorphToMany` and userland
 * subclasses; `withTimestamps()` is declared on `BelongsToMany` itself.
 *
 * Column semantics mirror Laravel's runtime: `withPivot()` takes either one array (later args ignored) or
 * variadic names; `withTimestamps($createdAt, $updatedAt)` treats `false` as "skip this column", `null` as
 * "the parent model's default column name" and a string as the custom name.
 *
 * Declines (null, the stub's `$this` stays) on everything not statically provable: non-literal, keyed, unpacked
 * or named (for `withPivot`) arguments, a receiver that is not exactly BelongsToMany / MorphToMany or a subclass
 * forwarding their four templates in order, or a pivot slot that is not a single named object. A
 * `withTimestamps()` default column name needs a declaring-model slot that is a single provable Model.
 * Argument parsing lives in {@see PivotColumnArgs}.
 *
 * Reading the added columns through the shape (`$user->pivot->role` on `Pivot&object{role: ...}`) still
 * fails: that is an upstream Psalm limitation (vimeo/psalm#12110), not fixable from a plugin hook.
 *
 * @internal
 */
final class BelongsToManyPivotColumnsHandler implements MethodReturnTypeProviderInterface
{
    /**
     * @return list<class-string>
     * @psalm-pure
     */
    #[\Override]
    public static function getClassLikeNames(): array
    {
        return [InteractsWithPivotTable::class, BelongsToMany::class];
    }

    #[\Override]
    public static function getMethodReturnType(MethodReturnTypeProviderEvent $event): ?Union
    {
        $method = $event->getMethodNameLowercase();

        if ($method !== 'withpivot' && $method !== 'withtimestamps') {
            return null;
        }

        $source = $event->getSource();
        $stmt = $event->getStmt();

        if (!$source instanceof StatementsAnalyzer || !$stmt instanceof MethodCall) {
            return null;
        }

        $receiver = $source->getNodeTypeProvider()->getType($stmt->var);

        if (!$receiver instanceof Union || !$receiver->isSingle()) {
            return null;
        }

        $atomic = $receiver->getSingleAtomic();

        if (!$atomic instanceof TGenericObject || \count($atomic->type_params) !== 4) {
            return null;
        }

        $codebase = $source->getCodebase();

        if (!self::isPivotAwareReceiver($codebase, $atomic)) {
            return null;
        }

        // Only a single named pivot class can be refined; its name selects the column read types.
        $pivotSlot = $atomic->type_params[2];
        $pivotAtomic = $pivotSlot->isSingle() ? $pivotSlot->getSingleAtomic() : null;

        if (!$pivotAtomic instanceof TNamedObject) {
            return null;
        }

        /** @psalm-var class-string $pivotClass */
        $pivotClass = $pivotAtomic->value;

        // The declaring-model slot only matters for the default column names of withTimestamps();
        // an unprovable slot is passed on as null so PivotColumnArgs declines instead of guessing.
        $args = $event->getCallArgs();
        $declaringModel = self::declaringModel($codebase, $atomic->type_params[1]);

        $columns = $method === 'withpivot'
            ? PivotColumnArgs::withPivot($args)
            : PivotColumnArgs::withTimestamps($args, $declaringModel);

        if ($columns === null || $columns === []) {
            return null;
        }

        $scopeClass = $source->getFQCLN();
        $table = $scopeClass !== null
            ? RelationMethodParser::pivotTableOfChain($codebase, $stmt->var, $scopeClass)
            : null;

        $pivot = PivotColumnTypes::refine(
            $pivotSlot,
            PivotColumnTypes::resolve($codebase, $columns, $table, $declaringModel, $pivotClass),
        );

        if (!$pivot instanceof Union) {
            return null;
        }

        $params = $atomic->type_params;
        $params[2] = $pivot;

        return new Union([$atomic->setTypeParams($params)]);
    }

    /**
     * The receiver must be exactly BelongsToMany / MorphToMany, or a subclass that forwards the four
     * templates of BelongsToMany unchanged and in order. Any other subclass can bind or reorder them
     * (`@template-extends BelongsToMany<Tag, TOwner, Pivot, 'pivot'>`), so slot 3 would not be the pivot.
     *
     * @psalm-capabilities read-props
     */
    private static function isPivotAwareReceiver(Codebase $codebase, TGenericObject $receiver): bool
    {
        if (
            \strcasecmp($receiver->value, BelongsToMany::class) === 0
            || \strcasecmp($receiver->value, MorphToMany::class) === 0
        ) {
            return true;
        }

        try {
            $storage = $codebase->classlike_storage_provider->get($receiver->value);
        } catch (\InvalidArgumentException|UnpopulatedClasslikeException) {
            return false;
        }

        $extended = $storage->template_extended_params[BelongsToMany::class] ?? null;

        if ($extended === null) {
            return false;
        }

        $ownTemplates = \array_keys($storage->template_types ?? []);
        $position = 0;

        foreach ($extended as $mapped) {
            if ($position > 3) {
                break;
            }

            $mappedAtomic = $mapped->isSingle() ? $mapped->getSingleAtomic() : null;

            if (
                !$mappedAtomic instanceof TTemplateParam
                || \strcasecmp($mappedAtomic->defining_class, $receiver->value) !== 0
                || ($ownTemplates[$position] ?? null) !== $mappedAtomic->param_name
            ) {
                return false;
            }

            ++$position;
        }

        return $position === 4;
    }

    /**
     * @return ?class-string<Model>
     * @psalm-capabilities read-props
     */
    private static function declaringModel(Codebase $codebase, Union $parentSlot): ?string
    {
        if (!$parentSlot->isSingle()) {
            return null;
        }

        $atomic = $parentSlot->getSingleAtomic();

        if (!$atomic instanceof TNamedObject) {
            return null;
        }

        // classExtends() is non-reflexive and does not autoload; the parent slot is not a provable
        // Model subclass when it throws or answers false.
        try {
            if (!$codebase->classExtends($atomic->value, Model::class)) {
                return null;
            }
        } catch (\InvalidArgumentException|UnpopulatedClasslikeException) {
            return null;
        }

        /** @var class-string<Model> $fqcn */
        $fqcn = $atomic->value;

        return $fqcn;
    }
}
