<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use Illuminate\Database\Eloquent\Model;
use PhpParser\Node\Expr\MethodCall;
use Psalm\Codebase;
use Psalm\Internal\MethodIdentifier;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ModelMetadataRegistry;
use Psalm\Plugin\EventHandler\AfterMethodCallAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterMethodCallAnalysisEvent;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TObjectWithProperties;
use Psalm\Type\Atomic\TTemplateParam;
use Psalm\Type\MutableTypeVisitor;
use Psalm\Type\TypeNode;
use Psalm\Type\TypeVisitor;
use Psalm\Type\Union;

/**
 * Renames the pivot key of the `TRelatedModel&object{pivot: TPivotModel}` a BelongsToMany / MorphToMany call returns to
 * the relation's `as()` accessor (TAccessor, slot 4): after `->as('subscription')`, `first()` returns
 * `Plan&object{subscription: P}`. Psalm reads an object-shape key literally, so the stubs cannot write
 * `object{TAccessor: TPivotModel}`; Laravel 13.41+ docblocks do and get a property named `TAccessor`, which this
 * handler renames too (to `pivot` for the default accessor).
 *
 * Runs after Psalm resolved the call, so it covers every method (stub, vendor docblock, chained receivers) without a
 * method list. Declines unless the receiver is exactly one BelongsToMany / MorphToMany whose TAccessor is one literal
 * property name that no attribute or `Model` method of the related model shadows (Laravel reads those first), and the
 * method is Laravel's own and declares the pivot shape in its return. Only a shape on the receiver's own TRelatedModel
 * with the receiver's TPivotModel is renamed, so arguments and callback values of other models pass through.
 *
 * Known gaps (Issue2348PivotAccessorKnownLimitation.phpt): a `firstOr()` / `findOr()` callback value of the same model
 * and pivot is renamed with the relation's result; callback params (`chunk()`, `each()`, a `chunkMap()` identity)
 * keep `pivot`, since Psalm types a closure before the call resolves; first-class callables skip this hook. Psalm does
 * not track the receiver object: an in-place `as()` on an alias, or after `lazy()` / `cursor()` was called, is not
 * seen, nor is an `as()` inside a registered relation macro in a relation body. `create()` / `save()` results carry
 * the stubs' pivot promise although Laravel loads no pivot there (pre-existing).
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
        // Every analyzed method call reaches this hook: compare the called class without allocating first.
        $methodId = $event->getMethodId();
        if (\strncasecmp($methodId, 'Illuminate\Database\Eloquent\Relations\BelongsToMany::', 54) !== 0
            && \strncasecmp($methodId, 'Illuminate\Database\Eloquent\Relations\MorphToMany::', 52) !== 0
        ) {
            return;
        }

        $candidate = $event->getReturnTypeCandidate();
        $expr = $event->getExpr();
        $declaringId = $event->getDeclaringMethodId();
        if (!$candidate instanceof Union || !$expr instanceof MethodCall
            || !isset(self::DECLARING_CLASSES[\strtolower(\explode('::', $declaringId, 2)[0])])
            || !self::declaresPivotShape($event->getCodebase(), $declaringId)
        ) {
            return;
        }

        $source = $event->getStatementsSource();
        $relation = self::relation($source->getNodeTypeProvider()->getType($expr->var));
        $accessor = $relation instanceof TGenericObject ? self::accessor($event->getCodebase(), $relation) : null;
        if ($accessor !== null && $relation instanceof TGenericObject) {
            $event->setReturnTypeCandidate(self::rename($candidate, $relation, $accessor));
        }
    }

    /**
     * The one non-null atomic of $type when it is a generic object (`$m?->rel()` and `?BelongsToMany` returns).
     *
     * @psalm-pure
     */
    public static function relation(?Union $type): ?TGenericObject
    {
        $atomics = $type?->getAtomicTypes() ?? [];
        unset($atomics['null']);
        $atomic = \count($atomics) === 1 ? \reset($atomics) : null;

        return $atomic instanceof TGenericObject ? $atomic : null;
    }

    /**
     * The single literal TAccessor of an exact BelongsToMany / MorphToMany type, or null when it is not provable or
     * Laravel would read something else under that name.
     */
    public static function accessor(Codebase $codebase, TGenericObject $relation): ?string
    {
        if (!isset(self::RELATIONS[\strtolower($relation->value)]) || \count($relation->type_params) !== 4) {
            return null;
        }

        // Not isSingle(): Psalm counts a union of string literals (`'a'|'b'`) as single.
        $accessor = $relation->type_params[3]->isSingleStringLiteral() ? $relation->type_params[3]->getSingleStringLiteral()->value : null;

        // A key that is not a plain property name (`as('a-b')`, `as('0')`) declines rather than print a quoted shape key.
        if ($accessor === null || \preg_match('/^[A-Za-z_][A-Za-z0-9_]*\z/', $accessor) !== 1) {
            return null;
        }

        return $accessor === 'pivot' || !self::shadowed($codebase, $relation->type_params[0], $accessor) ? $accessor : null;
    }

    /**
     * `getAttribute()` returns an attribute, or null for a `Model` method name, before it reads the pivot relation.
     * A relation method of the same name does not: the loaded pivot relation wins.
     */
    private static function shadowed(Codebase $codebase, Union $model, string $accessor): bool
    {
        if (\method_exists(Model::class, $accessor)) {
            return true;
        }

        foreach ($model->getAtomicTypes() as $atomic) {
            $class = $atomic instanceof TTemplateParam ? $atomic->as->getSingleAtomic() : $atomic;
            try {
                $storage = $class instanceof TNamedObject ? $codebase->classlike_storage_provider->get($class->value) : null;
            } catch (\InvalidArgumentException) {
                continue;
            }

            /** @psalm-var class-string|null $name a class with storage */
            $name = $storage?->name;
            $metadata = $name === null ? null : ModelMetadataRegistry::for($name);
            if (isset($storage->declaring_property_ids[$accessor]) || isset($storage->pseudo_property_get_types['$' . $accessor])
                || $metadata instanceof \Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ModelMetadata && ($metadata->schema()->has($accessor) || isset($metadata->casts()[$accessor]) || $metadata->accessor($accessor) instanceof \Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\AccessorInfo)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * The value of a one-property, method-less `object{pivot: ...}` / `object{TAccessor: ...}`.
     *
     * @internal
     * @psalm-pure
     */
    public static function pivotValue(TObjectWithProperties $shape): ?Union
    {
        if ($shape->methods !== [] || \count($shape->properties) !== 1) {
            return null;
        }

        $key = \array_key_first($shape->properties);

        return $key === 'pivot' || $key === 'TAccessor' ? $shape->properties[$key] : null;
    }

    /**
     * Whether the method's own return declares `object{pivot|TAccessor: TPivotModel}`: a template or argument it
     * passes through (`saveMany()`'s TContainer, `chunkMap()`'s TReturn, `getParent()`) carries other values.
     *
     * @psalm-capabilities read-props
     */
    private static function declaresPivotShape(Codebase $codebase, string $declaringId): bool
    {
        try {
            $declared = $codebase->methods->getStorage(MethodIdentifier::wrap($declaringId))->return_type;
        } catch (\InvalidArgumentException|\UnexpectedValueException) {
            return false;
        }

        $finder = new class extends TypeVisitor {
            /** @psalm-pure */
            #[\Override]
            protected function enterNode(TypeNode $type): ?int
            {
                $value = $type instanceof TObjectWithProperties ? PivotAccessorHandler::pivotValue($type) : null;
                $pivot = $value?->isSingle() === true ? $value->getSingleAtomic() : null;

                return $pivot instanceof TTemplateParam && $pivot->param_name === 'TPivotModel' ? self::STOP_TRAVERSAL : null;
            }
        };

        return $declared instanceof Union && !$finder->traverse($declared);
    }

    private static function rename(Union $type, TGenericObject $relation, string $accessor): Union
    {
        $visitor = new class ($relation->type_params[0], $relation->type_params[2]->getId(), $accessor) extends MutableTypeVisitor {
            /** @var array<string, true> */
            private array $models = [];

            /** @psalm-capabilities read-props */
            public function __construct(Union $model, private readonly string $pivot, private readonly string $accessor)
            {
                foreach ($model->getAtomicTypes() as $atomic) {
                    $this->models[$atomic->getId()] = true;
                }
            }

            /** @psalm-capabilities read-props|write-this-props|write-refs */
            #[\Override]
            protected function enterNode(TypeNode &$type): ?int
            {
                if (!$type instanceof TNamedObject && !$type instanceof TTemplateParam || $type->extra_types === []) {
                    return null;
                }

                // Compare the host without the pivot shape only: other intersection members belong to the model.
                $host = [];
                $extra = [];
                foreach ($type->extra_types as $key => $shape) {
                    $value = $shape instanceof TObjectWithProperties ? PivotAccessorHandler::pivotValue($shape) : null;
                    if ($shape instanceof TObjectWithProperties && $value instanceof Union && $value->getId() === $this->pivot) {
                        $shape = $shape->setProperties([$this->accessor => $value]);
                        $key = $shape->getKey();
                    } else {
                        $host[$key] = $shape;
                    }

                    $extra[$key] = $shape;
                }

                if (\count($host) === \count($extra) || !isset($this->models[$type->setIntersectionTypes($host)->getId()])) {
                    return null;
                }

                $type = $type->setIntersectionTypes($extra);

                return self::DONT_TRAVERSE_CHILDREN;
            }
        };

        $visitor->traverse($type);

        return $type;
    }
}
