<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use Illuminate\Database\Eloquent\Model;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use Psalm\Plugin\EventHandler\AfterMethodCallAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterMethodCallAnalysisEvent;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

/**
 * After a call to a Model method that mutates the underlying attributes array,
 * remove any narrowed types Psalm tracked for `@property`-declared attributes on
 * the receiver.
 *
 * Why: literal assignments like `$model->flag = true;` cause Psalm to narrow
 * `$model->flag` to `true`. The mutating methods below mass-assign or replace
 * the attributes array at runtime, but Psalm only sees the slot
 * `$this->attributes` change — narrowings on the per-name `@property` slots
 * stay stale, producing false RedundantCondition / TypeDoesNotContainType
 * errors on subsequent reads.
 *
 * Scope is intentionally narrowed to methods that mass-assign or fully replace
 * the attributes array (the cases #818 reports). `save()`, `push()`, `touch()`,
 * `increment()` etc. only touch a small known set of slots (timestamps, primary
 * key on insert) and would over-invalidate user narrowings — they can be added
 * later if a real false-positive surfaces.
 *
 * `fresh()` is intentionally excluded: it returns a new instance and does not
 * mutate `$this`, so the typical idiom `$model = $model->fresh()` already
 * invalidates narrowings via reassignment.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/818
 * @internal
 */
final class ModelAttributeInvalidationHandler implements AfterMethodCallAnalysisInterface
{
    /**
     * Suffixes we test the declaring method id against. The leading `::`
     * prevents accidental matches against class names ending in `fill` etc.;
     * `str_ends_with` is a C-level byte comparison, so we avoid the
     * `strrpos`+`substr` allocation that would otherwise run for every method
     * call in the analyzed codebase (afterMethodCallAnalysis fires per call).
     *
     * Method portion is always lowercase in MethodIdentifier::__toString();
     * see {@see \Psalm\Internal\MethodIdentifier::fromMethodIdReference()}.
     */
    private const MUTATING_METHOD_SUFFIXES = [
        '::refresh',
        '::fill',
        '::forcefill',
        '::setrawattributes',
        '::update',
    ];

    #[\Override]
    public static function afterMethodCallAnalysis(AfterMethodCallAnalysisEvent $event): void
    {
        $expr = $event->getExpr();

        // Only instance calls can mutate a tracked variable; static calls have
        // no receiver to invalidate against.
        if (!$expr instanceof MethodCall) {
            return;
        }

        // Hot-path filter: reject by method name first, allocation-free.
        if (!self::isMutatingMethod($event->getDeclaringMethodId())) {
            return;
        }

        $source = $event->getStatementsSource();
        $receiverType = $source->getNodeTypeProvider()->getType($expr->var);
        if (!$receiverType instanceof Union) {
            return;
        }

        $codebase = $event->getCodebase();
        $modelStorages = self::collectModelStorages($receiverType, $codebase);
        if ($modelStorages === []) {
            return;
        }

        $receiverVarId = self::buildVarId($expr->var);
        if ($receiverVarId === null) {
            return;
        }

        $context = $event->getContext();

        foreach ($modelStorages as $storage) {
            // Pseudo-property keys are stored as "$<name>". Parent and trait
            // declarations are merged into the child's storage by Psalm's Populator,
            // so iterating the receiver's storage alone covers the inheritance chain.
            // Both get_types and set_types are unioned to also cover properties
            // declared only as @property-write or @property-read.
            $pseudoKeys = $storage->pseudo_property_get_types + $storage->pseudo_property_set_types;

            foreach ($pseudoKeys as $pseudoKey => $_) {
                if (!\str_starts_with($pseudoKey, '$')) {
                    continue;
                }

                $propertyName = \substr($pseudoKey, 1);
                if ($propertyName === '') {
                    continue;
                }

                // Context::remove() also clears descendents (e.g. `$model->prop['x']`)
                // and clauses referencing the var, matching how Psalm itself
                // invalidates narrowings on assignment.
                $context->remove($receiverVarId . '->' . $propertyName);
            }
        }
    }

    /** @psalm-pure */
    private static function isMutatingMethod(string $declaringMethodId): bool
    {
        foreach (self::MUTATING_METHOD_SUFFIXES as $suffix) {
            if (\str_ends_with($declaringMethodId, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Collect ClassLikeStorage entries from the receiver's type for atomic
     * objects that extend Model (or are Model itself).
     *
     * @return list<\Psalm\Storage\ClassLikeStorage>
     * @psalm-mutation-free
     */
    private static function collectModelStorages(Union $receiverType, \Psalm\Codebase $codebase): array
    {
        $modelFqcnLower = \strtolower(Model::class);
        $storages = [];

        foreach ($receiverType->getAtomicTypes() as $atomic) {
            if (!$atomic instanceof TNamedObject) {
                continue;
            }

            try {
                $storage = $codebase->classlike_storage_provider->get(\strtolower($atomic->value));
            } catch (\InvalidArgumentException) {
                continue;
            }

            // parent_classes contains the full ancestor chain (lowercased).
            // Include Model itself (e.g. a parameter typed as Model) too.
            if (
                isset($storage->parent_classes[$modelFqcnLower])
                || \strtolower($storage->name) === $modelFqcnLower
            ) {
                $storages[] = $storage;
            }
        }

        return $storages;
    }

    /**
     * Build the receiver's variable id (e.g. "$preference", "$this->member->preference")
     * from the call's AST. Returns null for receivers that don't map to a stable
     * vars_in_scope key (e.g. `getModel()->refresh()`, array element access).
     *
     * Mirrors the subset of Psalm's ExpressionIdentifier::getVarId() that's
     * relevant here, without depending on the @internal class.
     *
     * @psalm-mutation-free
     */
    private static function buildVarId(Expr $expr): ?string
    {
        if ($expr instanceof Variable && \is_string($expr->name)) {
            return '$' . $expr->name;
        }

        if ($expr instanceof PropertyFetch && $expr->name instanceof Identifier) {
            $parent = self::buildVarId($expr->var);
            if ($parent === null) {
                return null;
            }

            return $parent . '->' . $expr->name->name;
        }

        return null;
    }
}
