<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent\Support;

use Illuminate\Database\Eloquent\Concerns\HasAttributes;
use Illuminate\Database\Eloquent\Concerns\HasRelationships;
use Illuminate\Database\Eloquent\Concerns\HasTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Concerns\AsPivot;
use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Str;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\String_;
use Psalm\Codebase;
use Psalm\Exception\UnpopulatedClasslikeException;
use Psalm\Internal\MethodIdentifier;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ModelMetadata;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ModelMetadataRegistry;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ModelMetadataRegistryBuilder;
use Psalm\LaravelPlugin\Handlers\Eloquent\ModelPropertyHandler;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\CastResolver;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\ColumnTypeMapper;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaStateProvider;
use Psalm\Type;
use Psalm\Type\Atomic;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TObjectWithProperties;
use Psalm\Type\Union;

/**
 * Types the pivot columns a `withPivot()` call selects, shared by the call-site handler and the
 * relation-method parser: `Pivot&object{role: string, ...}` (#1944).
 *
 * @internal
 */
final class PivotColumnResolver
{
    /** Many-to-many factories mapped to the position of their `$table` parameter. */
    public const FACTORY_TABLE_POSITION = ['belongstomany' => 1, 'morphtomany' => 2, 'morphedbymany' => 2];

    /**
     * Literal column names, read as Laravel does: the first argument when it is an array, else every
     * argument. Nothing when the first argument is neither a literal string nor a literal array: it may
     * be an array at runtime, and then it alone counts. Other dynamic names and unpacks add nothing;
     * dotted names are skipped because Laravel's `pivot_<column>` alias then names no attribute.
     *
     * @param array<array-key, Node> $args
     * @return list<string>
     * @psalm-mutation-free
     */
    public static function columnsFromArgs(array $args): array
    {
        $first = $args[0] ?? null;
        if (!$first instanceof Arg || $first->unpack || !$first->value instanceof String_ && !$first->value instanceof Expr\Array_) {
            return [];
        }

        $values = [];
        if ($first->value instanceof Expr\Array_) {
            foreach ($first->value->items as $item) {
                // An unpacked item or an explicit key may overwrite a column, in the literal or in array_merge().
                if (!$item instanceof Expr\ArrayItem || $item->unpack || $item->key instanceof Expr) {
                    return [];
                }

                $values[] = $item->value;
            }
        } else {
            foreach ($args as $arg) {
                if ($arg instanceof Arg && !$arg->unpack) {
                    $values[] = $arg->value;
                }
            }
        }

        $columns = [];
        foreach ($values as $value) {
            if ($value instanceof String_ && self::isColumnName($value->value)) {
                $columns[] = $value->value;
            }
        }

        return $columns;
    }

    /**
     * The pivot table a many-to-many factory call joins: null when unknown, false when the `$table`
     * argument may name a model class, which Laravel resolves to that model's table and may answer
     * with `using()` on it (`BelongsToMany::resolveTableName()`), so the pivot class is unknown.
     *
     * @param lowercase-string $factory
     */
    public static function table(
        Codebase $codebase,
        MethodCall $call,
        string $factory,
        ?string $relatedClass,
        ?string $declaringClass,
    ): string|false|null {
        $position = self::FACTORY_TABLE_POSITION[$factory] ?? null;
        if ($position === null) {
            return null;
        }

        foreach ($call->args as $arg) {
            if (!$arg instanceof Arg || $arg->unpack) {
                return false;
            }
        }

        $table = self::argument($call, $position, 'table');
        if ($table instanceof String_) {
            return \str_contains($table->value, '\\') ? false : $table->value;
        }

        if ($table instanceof Expr && (!$table instanceof Expr\ConstFetch || $table->name->toLowerString() !== 'null')) {
            return false;
        }

        if ($factory !== 'belongstomany') {
            // HasRelationships::morphToMany(): the morph name with its last `_` segment pluralized.
            $name = self::argument($call, 1, 'name');
            if (!$name instanceof String_) {
                return null;
            }

            $split = \strrpos($name->value, '_');
            $prefix = $split === false ? '' : \substr($name->value, 0, $split + 1);

            return $prefix . Str::plural(\substr($name->value, $split === false ? 0 : $split + 1));
        }

        // HasRelationships::joiningTable(): both models' joiningTableSegment(), sorted.
        if (
            $relatedClass === null || $declaringClass === null
            || !self::inheritsDefault($codebase, $declaringClass, 'joiningTable')
            || !self::inheritsDefault($codebase, $relatedClass, 'joiningTableSegment')
            || !self::inheritsDefault($codebase, $declaringClass, 'joiningTableSegment')
        ) {
            return null;
        }

        $segments = [Str::snake(\class_basename($relatedClass)), Str::snake(\class_basename($declaringClass))];
        \sort($segments);

        return \strtolower(\implode('_', $segments));
    }

    /**
     * Each column's type, first hit wins: a custom pivot's own `@property` / cast; the date type for the parent's
     * created/updated column when the pivot's hydration provably casts it; the relation table's migration
     * schema; `mixed`.
     *
     * Laravel casts those two columns as dates once the pivot's timestamps switch on. A plain pivot
     * (`Pivot::fromAttributes()`) checks its OWN `created_at` before the parent is attached, a `using()` pivot
     * (`fromRawAttributes()`) the parent's created-at column. Selecting it is the whole gate, and later
     * selections never close it, but a hidden chain may carry a `using()` that moves it: only a
     * $visible chain proves it. Where it is not proven the parent's timestamp columns are `mixed`: neither the
     * raw schema type nor Carbon is right.
     *
     * @param list<string> $columns
     * @param ?string $parentClass the model the relation is called on
     * @param ?string $using the `using()` pivot class; null for the plain pivot
     * @param bool $visible every call of the chain, `using()` included, is known
     * @return array<string, Union>
     */
    public static function columnTypes(
        Codebase $codebase,
        ?string $table,
        array $columns,
        ?string $parentClass,
        ?string $using = null,
        bool $visible = false,
    ): array {
        $schemaTable = $table === null ? null : SchemaStateProvider::getSchema()?->tables[$table] ?? null;
        $parent = $parentClass === null ? null : self::parentTimestamps($codebase, $parentClass);
        // A pivot class that converts dates its own way makes the raw schema type of any column unreliable.
        $stock = $using === null || self::hasStockDates($codebase, $using);
        $switch = $using === null ? Model::CREATED_AT : $parent[0] ?? null;
        $dates = $visible && $stock && $switch !== null && \in_array($switch, $columns, true);
        $types = [];
        foreach ($columns as $column) {
            $schemaColumn = $schemaTable?->columns[$column] ?? null;
            $declared = $using === null ? null : self::declaredType($codebase, $using, $column, $schemaColumn?->nullable ?? true);
            $isDate = $parent !== null && \in_array($column, $parent, true);
            if ($declared instanceof Union) {
                $types[$column] = $declared;
            } elseif ($parent === null || !$stock || $isDate && !$dates) {
                $types[$column] = Type::getMixed();
            } elseif ($isDate) {
                $types[$column] = CastResolver::resolve($codebase, 'datetime', $schemaColumn?->nullable ?? true);
            } elseif ($schemaColumn === null) {
                $types[$column] = Type::getMixed();
            } else {
                $type = ColumnTypeMapper::mapBaseType(ModelMetadataRegistryBuilder::buildColumnInfo($schemaColumn));
                $types[$column] = $schemaColumn->nullable ? Type::combineUnionTypes($type, Type::getNull()) : $type;
            }
        }

        return $types;
    }

    /**
     * $pivot with $properties added to its object shape (a column already in it keeps its type: Laravel
     * only appends pivot columns, it never retypes one), or null unless $pivot is exactly `Pivot` /
     * `MorphPivot`, optionally already carrying one shape. With $using the shape is built on that custom
     * pivot class instead, from scratch.
     *
     * @param array<string, Union> $properties
     * @psalm-pure
     */
    public static function refine(Atomic $pivot, array $properties, ?string $using = null): ?TNamedObject
    {
        if ($using !== null) {
            $pivot = new TNamedObject($using);
        } elseif ($pivot::class !== TNamedObject::class || !self::isFrameworkPivot($pivot->value)) {
            return null;
        }

        $existing = [];
        foreach ($pivot->extra_types as $extra) {
            if (!$extra instanceof TObjectWithProperties || $extra->methods !== [] || $existing !== []) {
                return null;
            }

            $existing = $extra->properties;
        }

        $shape = new TObjectWithProperties($existing + $properties);

        return $pivot->setIntersectionTypes([$shape->getKey() => $shape]);
    }

    /**
     * The columns a `withTimestamps($createdAt, $updatedAt)` call selects: a literal name is itself, an absent
     * or `null` one is the parent's column (`createdAt()` falls back to `created_at` for a disabled constant).
     * `false` disables the column in Laravel 13 and names none in 12.20, a dynamic name is unknown: neither adds one.
     *
     * @return list<string>
     */
    public static function timestampColumns(Codebase $codebase, MethodCall $call, ?string $parentClass): array
    {
        foreach ($call->args as $arg) {
            if (!$arg instanceof Arg || $arg->unpack) {
                return [];
            }
        }

        $parent = $parentClass === null ? null : self::parentTimestamps($codebase, $parentClass);
        $columns = [];
        foreach ([['createdAt', Model::CREATED_AT], ['updatedAt', Model::UPDATED_AT]] as $index => [$name, $default]) {
            $value = self::argument($call, $index, $name);
            $isNull = !$value instanceof Expr || $value instanceof Expr\ConstFetch && $value->name->toLowerString() === 'null';
            $column = $value instanceof String_ ? $value->value : ($isNull && $parent !== null ? $parent[$index] ?? $default : null);
            if ($column !== null && self::isColumnName($column)) {
                $columns[] = $column;
            }
        }

        return $columns;
    }

    /**
     * The `CREATED_AT` / `UPDATED_AT` column names of $modelClass (null: disabled); null when unknown:
     * a model that overrides `getCreatedAtColumn()` / `getUpdatedAtColumn()`, or a constant that is not
     * a literal string or null.
     *
     * @return array{?string, ?string}|null
     */
    private static function parentTimestamps(Codebase $codebase, string $modelClass): ?array
    {
        if (
            !self::inheritsDefault($codebase, $modelClass, 'getCreatedAtColumn')
            || !self::inheritsDefault($codebase, $modelClass, 'getUpdatedAtColumn')
        ) {
            return null;
        }

        $columns = [];
        foreach (['CREATED_AT', 'UPDATED_AT'] as $constant) {
            try {
                $type = $codebase->classlikes->getClassConstantType($modelClass, $constant, \ReflectionProperty::IS_PRIVATE);
            } catch (\InvalidArgumentException|\UnexpectedValueException|UnpopulatedClasslikeException) {
                return null;
            }

            if (!$type instanceof Union || !$type->isNull() && !$type->isSingleStringLiteral()) {
                return null;
            }

            $columns[] = $type->isNull() ? null : $type->getSingleStringLiteral()->value;
        }

        return [$columns[0], $columns[1]];
    }

    /**
     * The type a custom pivot class gives a column itself, null when it says nothing. What its own property
     * types answer (`@property`, a cast or a column of its default table) is reused verbatim: Psalm reads such
     * a property before an object shape, so a different type in the shape would never be read. A cast on a
     * column its default table lacks is bound to no nullability; the relation table's decides, and an unknown
     * column may be null. `mixed` when its casts are not fully known (a class this run never registered).
     *
     * @param bool $nullable whether the column may be null in the relation's table
     */
    private static function declaredType(Codebase $codebase, string $pivotClass, string $column, bool $nullable): ?Union
    {
        // `using(Pivot::class)` is the framework's own pivot: it says nothing about its columns.
        if (self::isFrameworkPivot($pivotClass)) {
            return null;
        }

        /** @var class-string<Model> $pivotClass */
        $own = ModelPropertyHandler::resolveColumnType($codebase, $pivotClass, $column);
        if ($own instanceof Union) {
            return $own;
        }

        $metadata = ModelMetadataRegistry::for($pivotClass);
        if (!$metadata instanceof ModelMetadata || !$metadata->isComplete(ModelMetadata::SECTION_CASTS)) {
            return Type::getMixed();
        }

        $cast = ($metadata->casts()[$column] ?? null)?->psalmType;

        return $cast instanceof Union && $nullable ? Type::combineUnionTypes($cast, Type::getNull()) : $cast;
    }

    /** Whether the pivot class converts dates as stock Laravel does, so `getDates()` decides which columns are dates. */
    private static function hasStockDates(Codebase $codebase, string $pivotClass): bool
    {
        foreach (['getDates', 'usesTimestamps', 'hasTimestampAttributes', 'getCreatedAtColumn', 'getUpdatedAtColumn'] as $method) {
            if (!self::inheritsDefault($codebase, $pivotClass, $method)) {
                return false;
            }
        }

        return true;
    }

    /** @psalm-pure */
    private static function isFrameworkPivot(string $class): bool
    {
        return \in_array(\strtolower($class), [\strtolower(Pivot::class), \strtolower(MorphPivot::class)], true);
    }

    /** @psalm-pure */
    private static function isColumnName(string $name): bool
    {
        return $name !== '' && !\str_contains($name, '.');
    }

    /** @psalm-mutation-free */
    private static function argument(MethodCall $call, int $position, string $name): ?Expr
    {
        foreach ($call->args as $arg) {
            if ($arg instanceof Arg && $arg->name instanceof Identifier && $arg->name->name === $name) {
                return $arg->value;
            }
        }

        $arg = $call->args[$position] ?? null;

        return $arg instanceof Arg && !$arg->name instanceof Identifier ? $arg->value : null;
    }

    /** @psalm-capabilities read-props */
    private static function inheritsDefault(Codebase $codebase, string $modelClass, string $method): bool
    {
        try {
            $declaring = $codebase->methods->getDeclaringMethodId(MethodIdentifier::wrap($modelClass . '::' . $method));
        } catch (\InvalidArgumentException|UnpopulatedClasslikeException) {
            return false;
        }

        return $declaring instanceof MethodIdentifier
            && \in_array(\strtolower($declaring->fq_class_name), [
                \strtolower(HasRelationships::class),
                \strtolower(HasTimestamps::class),
                \strtolower(HasAttributes::class),
                \strtolower(AsPivot::class),
                \strtolower(Model::class),
            ], true);
    }
}
