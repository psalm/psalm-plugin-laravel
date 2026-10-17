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
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ModelMetadataRegistry;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\CastResolver;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\ColumnTypeMapper;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaStateProvider;
use Psalm\Type;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TObjectWithProperties;
use Psalm\Type\Union;

/**
 * Types the pivot columns a `BelongsToMany` / `MorphToMany` relation selects, for the object shape
 * `Pivot&object{role: string, ...}` that `withPivot()` / `withTimestamps()` refine TPivotModel to (#1944).
 * Both producers use it: {@see PivotColumnsReturnTypeHandler} inside a relation method body and
 * {@see ModelRelationReturnTypeHandler} at the call site of a registered model's relation method.
 *
 * Column type, first hit wins:
 * 1. a custom pivot class describes its own columns (`@property`, then casts, then its schema);
 * 2. a timestamp column the base pivot date-casts: `Pivot::fromRawAttributes()` turns timestamps on when the
 *    PARENT model's created-at column is among the selected ones, and `getDates()` then names the parent's
 *    created-at / updated-at columns, NOT the `withTimestamps($createdAt, $updatedAt)` arguments. So a renamed
 *    column is plain, and the updated-at column casts only together with the created-at one;
 * 3. the column in the relation table's schema (the migration table, or the table of a model passed as the
 *    table argument);
 * 4. the caller's fallback, then `mixed`.
 * Every lookup that cannot be proven (an unscanned class, an overridden `joiningTableSegment()`,
 * `getCreatedAtColumn()` or `getUpdatedAtColumn()`, a dynamic table) falls through to the next step.
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
     * decide which columns the base pivot date-casts, and the updated-at one is typed again when this call
     * brings the created-at column that turns the casts on.
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

        $selected = [...\array_keys($known), ...$columns];
        $retype = $parent !== null && $parent[1] !== null && \in_array($parent[0], $columns, true) && isset($known[$parent[1]]) ? [$parent[1]] : [];

        $properties = [];
        foreach (\array_unique([...$columns, ...$retype]) as $column) {
            $properties[$column] = $this->columnType($column, $selected, $parent, $fallback[$column] ?? null);
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
     * @param list<array-key> $selected
     * @param ?array{?string, ?string} $parent
     */
    private function columnType(string $column, array $selected, ?array $parent, ?Union $fallback): Union
    {
        $custom = !\in_array(\strtolower($this->pivotClass), [\strtolower(Pivot::class), \strtolower(MorphPivot::class)], true);

        if ($custom) {
            $type = $this->customPivotColumn($column);
            if ($type instanceof Union) {
                return $type;
            }
        } elseif ($parent !== null && $parent[0] !== null && \in_array($parent[0], $selected, true) && ($column === $parent[0] || $column === $parent[1])) {
            return CastResolver::resolve($this->codebase, 'datetime', true);
        }

        $schema = $this->schemaColumn($column);
        if ($schema instanceof ColumnInfo) {
            return ColumnTypeMapper::mapType($schema);
        }

        return $fallback instanceof Union && !$fallback->hasTemplate() ? $fallback : Type::getMixed();
    }

    private function customPivotColumn(string $column): ?Union
    {
        try {
            /** @var class-string<Model> $pivotClass a Pivot is a Model */
            $pivotClass = $this->pivotClass;

            return ModelPropertyHandler::resolveColumnType($this->codebase, $pivotClass, $column);
        } catch (\InvalidArgumentException|UnpopulatedClasslikeException) {
            return null;
        }
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
