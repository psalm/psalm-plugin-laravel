<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use Illuminate\Database\Eloquent\Concerns\HasRelationships;
use Illuminate\Database\Eloquent\Concerns\HasTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Str;
use Psalm\Codebase;
use Psalm\Exception\UnpopulatedClasslikeException;
use Psalm\Internal\MethodIdentifier;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ColumnInfo;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ModelMetadata;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ModelMetadataRegistry;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\CastResolver;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\ColumnTypeMapper;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaStateProvider;
use Psalm\Type;
use Psalm\Type\Atomic\TConditional;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TObjectWithProperties;
use Psalm\Type\Atomic\TTemplateIndexedAccess;
use Psalm\Type\Atomic\TTemplateKeyOf;
use Psalm\Type\Atomic\TTemplateParam;
use Psalm\Type\Atomic\TTemplateParamClass;
use Psalm\Type\Atomic\TTemplatePropertiesOf;
use Psalm\Type\Atomic\TTemplateValueOf;
use Psalm\Type\TypeNode;
use Psalm\Type\TypeVisitor;
use Psalm\Type\Union;

/**
 * Types the pivot columns a `BelongsToMany` / `MorphToMany` relation selects, for the object shape
 * `Pivot&object{role: string, ...}` that `withPivot()` / `withTimestamps()` refine TPivotModel to (#1944).
 * Both producers use it: {@see PivotColumnsReturnTypeHandler} inside a relation method body and
 * {@see ModelRelationReturnTypeHandler} at the call site of a registered model's relation method.
 *
 * Column type, first hit wins:
 * 1. a custom pivot class describes its own columns (`@property`, then casts, then its schema);
 * 2. a timestamp column the pivot date-casts: `getDates()` names the PARENT model's created-at / updated-at columns, not
 *    the `withTimestamps($createdAt, $updatedAt)` arguments, and applies once the pivot's timestamps are on. The
 *    default pivot (`Pivot::fromAttributes()`) turns them on when its own `created_at` is selected (it checks before
 *    the parent is attached); a `using()` class (`fromRawAttributes()`) when the parent's created-at column is. So
 *    a renamed parent column is plain on the default pivot, and a Carbon on a custom one;
 * 3. the column in the relation table's schema (the migration table, or the table of a model passed as the
 *    table argument);
 * 4. the caller's fallback, then `mixed`.
 * A lookup that cannot be proven (an unscanned class, an overridden `joiningTable()` / `joiningTableSegment()`,
 * a dynamic table) falls through to the next step; one that would wrongly claim a type (a custom pivot class
 * whose casts are not fully known, parent timestamp columns that cannot be read while timestamps may be on)
 * makes the column `mixed`.
 * Laravel 12.20 and 13 agree on all of this except `withTimestamps(false)`: 13 disables the column, 12.20
 * passes `false` as its name; both add no column here.
 *
 * @psalm-import-type PivotTable from RelationMethodParser
 * @psalm-import-type PivotTimestamps from RelationMethodParser
 * @internal
 */
final readonly class PivotShapeResolver
{
    /**
     * @param string $pivotClass the TPivotModel class: `Pivot` / `MorphPivot` or a custom pivot
     * @param ?PivotTable $table
     * @psalm-capabilities read-props
     */
    public function __construct(
        private Codebase $codebase,
        private string $pivotClass,
        private ?array $table,
        private ?string $parentClass,
        private ?string $relatedClass,
    ) {}

    /**
     * The properties a call adds to the pivot shape. `$known` are the columns the shape already holds: they
     * decide whether the pivot's timestamps are on, and a known date column is typed again when this call
     * brings the column that turns them on.
     *
     * @param list<string> $columns `withPivot()` columns
     * @param list<PivotTimestamps> $timestamps `withTimestamps()` arguments
     * @param array<array-key, Union> $known
     * @param array<array-key, Union> $fallback column types of a declared shape, used when nothing else knows one
     * @return array<string, Union>
     */
    public function properties(array $columns, array $timestamps, array $known = [], array $fallback = []): array
    {
        $parent = $this->parentTimestampColumns();
        foreach ($timestamps as $call) {
            foreach ([0 => $call['created'], 1 => $call['updated']] as $index => $argument) {
                // `null` is the parent model's column, falling back to Laravel's `created_at` / `updated_at`.
                $name = $argument ?? ($parent === null ? null : ($parent[$index] ?? ($index === 0 ? Model::CREATED_AT : Model::UPDATED_AT)));
                if (\is_string($name)) {
                    $columns[] = $name;
                }
            }
        }

        // Timestamps are on once the column named by `$switch` is selected; the date columns are the parent's.
        $switch = $this->timestampSwitch($parent);
        $retype = [];
        if ($switch !== null && \in_array($switch, $columns, true)) {
            $retype = \array_values(\array_filter(\array_keys($known), static fn(int|string $column): bool => \in_array($column, $parent ?? [], true)));
        }

        $selected = [...\array_keys($known), ...$columns];
        $properties = [];
        foreach (\array_unique([...$columns, ...\array_map(\strval(...), $retype)]) as $column) {
            $properties[$column] = $this->columnType($column, $selected, $parent, $switch, $fallback[$column] ?? null);
        }

        return $properties;
    }

    /**
     * The pivot class with `$properties` merged into its object shape, later values winning; null when the
     * slot is not a plain class with at most one shape.
     *
     * @param array<string, Union> $properties
     * @psalm-mutation-free
     */
    public static function refine(Union $slot, array $properties): ?TNamedObject
    {
        $pivot = self::plainPivot($slot);
        if (!$pivot instanceof TNamedObject) {
            return null;
        }

        $merged = new TObjectWithProperties([...self::shapeOf($pivot), ...$properties]);

        return $pivot->setIntersectionTypes([$merged->getKey() => $merged]);
    }

    /**
     * The shape's properties of a slot {@see refine()} accepts, else null.
     *
     * @return ?array<array-key, Union>
     * @psalm-mutation-free
     */
    public static function shapeProperties(Union $slot): ?array
    {
        $pivot = self::plainPivot($slot);

        return $pivot instanceof TNamedObject ? self::shapeOf($pivot) : null;
    }

    /**
     * @return array<array-key, Union>
     * @psalm-mutation-free
     */
    private static function shapeOf(TNamedObject $pivot): array
    {
        $extra = \current($pivot->getIntersectionTypes());

        return $extra instanceof TObjectWithProperties ? $extra->properties : [];
    }

    /**
     * A single named class with no intersection but (at most) one object shape.
     *
     * @psalm-mutation-free
     */
    private static function plainPivot(Union $slot): ?TNamedObject
    {
        if (!$slot->isSingle()) {
            return null;
        }

        $atomic = $slot->getSingleAtomic();
        if ($atomic::class !== TNamedObject::class) {
            return null;
        }

        $extras = $atomic->getIntersectionTypes();

        return $extras === [] || (\count($extras) === 1 && \current($extras) instanceof TObjectWithProperties) ? $atomic : null;
    }

    /**
     * `Pivot::fromAttributes()` (the default pivot) switches timestamps on from the pivot's OWN `created_at`, as it
     * checks before the parent is attached; `fromRawAttributes()` (`using()`) checks after, against the parent's
     * created-at column. Null when that cannot be proven.
     *
     * @param ?array{?string, ?string} $parent
     * @psalm-capabilities read-props
     */
    private function timestampSwitch(?array $parent): ?string
    {
        return $this->isCustomPivot() ? ($parent[0] ?? null) : Model::CREATED_AT;
    }

    /** @psalm-capabilities read-props */
    private function isCustomPivot(): bool
    {
        return !\in_array(\strtolower($this->pivotClass), [\strtolower(Pivot::class), \strtolower(MorphPivot::class)], true);
    }

    /**
     * @param list<array-key> $selected
     * @param ?array{?string, ?string} $parent
     */
    private function columnType(string $column, array $selected, ?array $parent, ?string $switch, ?Union $fallback): Union
    {
        if ($this->isCustomPivot()) {
            $type = $this->customPivotColumn($column);
            if ($type instanceof Union) {
                return $type;
            }
        }

        // With unprovable parent columns the date list is unknown, so is the type of any column once timestamps may be on.
        if ($parent === null && ($switch === null || \in_array($switch, $selected, true))) {
            return Type::getMixed();
        }

        if ($parent !== null && $switch !== null && \in_array($switch, $selected, true) && ($column === $parent[0] || $column === $parent[1])) {
            return CastResolver::resolve($this->codebase, 'datetime', true);
        }

        $schema = $this->schemaColumn($column);
        if ($schema instanceof ColumnInfo) {
            return ColumnTypeMapper::mapType($schema);
        }

        return $fallback instanceof Union && !self::isTemplateDerived($fallback) ? $fallback : Type::getMixed();
    }

    /**
     * The type a custom pivot class gives a column (`@property`, a cast, its own schema), `mixed` when its casts
     * cannot be proven (a pivot built from constructor state is not fully inspected), null when it says nothing.
     */
    private function customPivotColumn(string $column): ?Union
    {
        try {
            /** @var class-string<Model> $pivotClass a Pivot is a Model */
            $pivotClass = $this->pivotClass;

            $type = ModelPropertyHandler::resolveColumnType($this->codebase, $pivotClass, $column);
            if ($type instanceof Union) {
                return $type;
            }

            $metadata = ModelMetadataRegistry::for($pivotClass);
            if (!$metadata instanceof ModelMetadata || !$metadata->isComplete(ModelMetadata::SECTION_CASTS)) {
                return Type::getMixed();
            }

            $cast = $metadata->casts()[$column] ?? null;

            return $cast?->psalmType;
        } catch (\InvalidArgumentException|UnpopulatedClasslikeException) {
            return Type::getMixed();
        }
    }

    /**
     * Whether a template (or anything derived from one: `list<T>`, `class-string<T>`, `key-of<T>`, a shape value,
     * a conditional) sits anywhere in the type; `Union::hasTemplate()` only looks at the top level.
     */
    private static function isTemplateDerived(Union $type): bool
    {
        $visitor = new class extends TypeVisitor {
            /** @psalm-pure */
            #[\Override]
            protected function enterNode(TypeNode $type): ?int
            {
                $derived = $type instanceof TTemplateParam
                    || $type instanceof TTemplateParamClass
                    || $type instanceof TTemplateKeyOf
                    || $type instanceof TTemplateValueOf
                    || $type instanceof TTemplateIndexedAccess
                    || $type instanceof TTemplatePropertiesOf
                    || $type instanceof TConditional;

                return $derived ? self::STOP_TRAVERSAL : null;
            }
        };

        // traverse() returns false exactly when enterNode() stopped the traversal.
        return !$visitor->traverse($type);
    }

    private function schemaColumn(string $column): ?ColumnInfo
    {
        if ($this->table === null) {
            return null;
        }

        if ($this->table['kind'] === 'model') {
            /** @var class-string<Model> $model */
            $model = $this->table['value'];

            return ModelMetadataRegistry::for($model)?->schema()->column($column);
        }

        $table = $this->tableName();
        $schemaTable = $table === null ? null : SchemaStateProvider::getSchema()?->tables[$table] ?? null;
        $schemaColumn = $schemaTable?->columns[$column] ?? null;

        return $schemaColumn === null ? null : ColumnInfo::fromSchemaColumn($schemaColumn);
    }

    /**
     * The table name Laravel derives, or null when it cannot be proven. The default belongsToMany() table
     * is the two snake_cased class basenames, sorted and joined with `_` (no pluralization); morphToMany()
     * pluralizes the last `_` word of the morph name.
     */
    private function tableName(): ?string
    {
        $table = $this->table;
        if ($table === null) {
            return null;
        }

        if ($table['kind'] === 'literal') {
            return $table['value'];
        }

        if ($table['kind'] === 'morph') {
            $words = \preg_split('/(_)/u', $table['value'], -1, \PREG_SPLIT_DELIM_CAPTURE);
            if ($words === false) {
                return null;
            }

            $lastWord = \array_pop($words);

            return \implode('', $words) . Str::plural($lastWord);
        }

        if ($table['kind'] !== 'default' || $this->parentClass === null || $this->relatedClass === null
            || !$this->usesStockMethod($this->parentClass, 'joiningtablesegment', HasRelationships::class)
            || !$this->usesStockMethod($this->parentClass, 'joiningtable', HasRelationships::class)
            || !$this->usesStockMethod($this->relatedClass, 'joiningtablesegment', HasRelationships::class)
        ) {
            return null;
        }

        $segments = [Str::snake(\class_basename($this->relatedClass)), Str::snake(\class_basename($this->parentClass))];
        \sort($segments);

        return \strtolower(\implode('_', $segments));
    }

    /**
     * The parent model's created-at and updated-at column names (`null`: the model has none), or null when
     * they cannot be proven: no parent class, an overridden `getCreatedAtColumn()` / `getUpdatedAtColumn()`,
     * a constant that is no string literal or null.
     *
     * @return ?array{?string, ?string}
     */
    private function parentTimestampColumns(): ?array
    {
        $parent = $this->parentClass;
        if ($parent === null) {
            return null;
        }

        $columns = [];
        foreach (['CREATED_AT' => 'getcreatedatcolumn', 'UPDATED_AT' => 'getupdatedatcolumn'] as $constant => $method) {
            if (!$this->usesStockMethod($parent, $method, HasTimestamps::class)) {
                return null;
            }

            try {
                $type = $this->codebase->classlikes->getClassConstantType($parent, $constant, \ReflectionProperty::IS_PUBLIC);
            } catch (\InvalidArgumentException|UnpopulatedClasslikeException) {
                return null;
            }

            if ($type instanceof Union && $type->isNull()) {
                $columns[] = null;
            } elseif ($type instanceof Union && $type->isSingleStringLiteral()) {
                $columns[] = $type->getSingleStringLiteral()->value;
            } else {
                return null;
            }
        }

        return [$columns[0], $columns[1]];
    }

    /**
     * Whether `$class` has the framework's own `$method` (declared by `$trait`) rather than an override.
     *
     * @param lowercase-string $method
     * @param class-string $trait
     * @psalm-capabilities read-props
     */
    private function usesStockMethod(string $class, string $method, string $trait): bool
    {
        try {
            $declaring = $this->codebase->methods->getDeclaringMethodId(new MethodIdentifier($class, $method));
        } catch (\InvalidArgumentException|UnpopulatedClasslikeException) {
            return false;
        }

        return $declaring instanceof MethodIdentifier && \strcasecmp($declaring->fq_class_name, $trait) === 0;
    }
}
