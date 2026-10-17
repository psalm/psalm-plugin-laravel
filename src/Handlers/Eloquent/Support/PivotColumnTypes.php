<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Psalm\Codebase;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ColumnInfo;
use Psalm\LaravelPlugin\Handlers\Eloquent\ModelPropertyHandler;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\ColumnTypeMapper;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaColumn;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaStateProvider;
use Psalm\Type;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TObjectWithProperties;
use Psalm\Type\Union;

/**
 * Read types for `withPivot()` / `withTimestamps()` columns, and the merge of those columns into a
 * relation's pivot template slot as an `object{...}` shape.
 *
 * @internal
 */
final class PivotColumnTypes
{
    /**
     * Column => read type, in call order.
     *
     * Date casts mirror runtime ({@see \Illuminate\Database\Eloquent\Relations\Concerns\AsPivot}):
     * `$timestamps` is `hasTimestampAttributes()`, and `getDates()` then casts the PARENT's non-null
     * created-at / updated-at columns to Carbon. What differs is when the gate is evaluated:
     *  - plain `Pivot` / `MorphPivot` are built via `fromAttributes()`, before `pivotParent` is set, so the
     *    gate is the pivot's own created-at column, the literal `created_at`;
     *  - a `using()` class is built via `fromRawAttributes()`, after `pivotParent` is set, so the gate is
     *    the parent's created-at column (a parent without one never opens it).
     * A custom `withTimestamps('joined_at')` column is therefore never a date cast. When the parent's
     * names are unknown (null or not instantiable) nothing is date-cast: the names are never guessed.
     *
     * Precedence per column (Laravel's `transformModelValue()`: an explicit cast outranks the dates list):
     *  - `using()` class: its `@property` type, its explicit cast, the date cast, its registered schema,
     *    the relation table's schema, then `mixed`;
     *  - plain `Pivot` / `MorphPivot`: the date cast, the relation table's schema, then `mixed`.
     * A date-cast column of unknown schema is a nullable Carbon; any other unknown column reads `mixed`.
     *
     * @param list<non-empty-string> $columns selected pivot columns in call order (duplicates ignored)
     * @param ?non-empty-string $table resolved pivot table, null = unknown
     * @param ?class-string<Model> $declaringModel the relation's parent model (null = unknown: no date casts)
     * @param class-string $pivotClass the slot's named class (Pivot, MorphPivot, or the using() class)
     * @return array<string, Union> column => read type
     */
    public static function resolve(
        Codebase $codebase,
        array $columns,
        ?string $table,
        ?string $declaringModel,
        string $pivotClass,
    ): array {
        if ($columns === []) {
            return [];
        }

        $isPlainPivot = \strcasecmp($pivotClass, Pivot::class) === 0 || \strcasecmp($pivotClass, MorphPivot::class) === 0;

        // Null names never equal a selected column, so they are left out (keeps `$castToDate` a list of
        // non-empty strings for the in_array() assertion below).
        $castToDate = [];
        $names = self::parentTimestampNames($declaringModel);
        if ($names !== null) {
            [$createdAt, $updatedAt] = $names;

            $gateOpen = $isPlainPivot
                ? \in_array('created_at', $columns, true)
                : $createdAt !== null && \in_array($createdAt, $columns, true);

            if ($gateOpen) {
                if ($createdAt !== null) {
                    $castToDate[] = $createdAt;
                }

                if ($updatedAt !== null) {
                    $castToDate[] = $updatedAt;
                }
            }
        }

        $schemaTable = $table !== null
            ? SchemaStateProvider::getSchema()?->tables[$table] ?? null
            : null;

        $isCustomModel = !$isPlainPivot && \is_a($pivotClass, Model::class, true);

        $types = [];
        foreach ($columns as $column) {
            if (isset($types[$column])) {
                continue;
            }

            $explicit = $isCustomModel
                ? ModelPropertyHandler::resolveExplicitColumnType($codebase, $pivotClass, $column)
                : null;

            if ($explicit instanceof Union) {
                $types[$column] = $explicit;

                continue;
            }

            $schemaColumn = $schemaTable?->columns[$column] ?? null;

            if (\in_array($column, $castToDate, true)) {
                $carbon = new Union([new TNamedObject(\Illuminate\Support\Carbon::class)]);
                $types[$column] = $schemaColumn?->nullable === false
                    ? $carbon
                    : Type::combineUnionTypes($carbon, Type::getNull());

                continue;
            }

            // Only the pivot class's own registered schema is left here: its explicit declarations were
            // consulted above, so this must not outrank the date cast.
            $declared = $isCustomModel
                ? ModelPropertyHandler::resolveColumnType($codebase, $pivotClass, $column)
                : null;

            if ($declared instanceof Union) {
                $types[$column] = $declared;
            } elseif ($schemaColumn instanceof SchemaColumn) {
                $types[$column] = ColumnTypeMapper::mapColumnType(ColumnInfo::fromSchemaColumn($schemaColumn));
            } else {
                $types[$column] = Type::getMixed();
            }
        }

        return $types;
    }

    /**
     * Merge a column shape into a pivot template slot. Null when the slot is not a single TNamedObject.
     * Existing shape keys win (a re-selected column or a user-declared shape is never narrowed or
     * overwritten); new keys are appended. Other intersection members of the slot are kept.
     *
     * @param array<string, Union> $columnTypes
     * @psalm-capabilities read-props
     */
    public static function refine(Union $pivotSlot, array $columnTypes): ?Union
    {
        if ($columnTypes === []) {
            return $pivotSlot;
        }

        if (!$pivotSlot->isSingle()) {
            return null;
        }

        $pivot = $pivotSlot->getSingleAtomic();
        if (!$pivot instanceof TNamedObject) {
            return null;
        }

        $properties = [];
        $otherTypes = $pivot->extra_types;
        foreach ($otherTypes as $key => $extraType) {
            if ($extraType instanceof TObjectWithProperties) {
                $properties = $extraType->properties;
                unset($otherTypes[$key]);
            }
        }

        foreach ($columnTypes as $column => $type) {
            $properties[$column] ??= $type;
        }

        // setIntersectionTypes() clones the atom, so is_static and the rest of the pivot atom survive.
        return new Union([
            $pivot->setIntersectionTypes($otherTypes)->addIntersectionType(new TObjectWithProperties($properties)),
        ]);
    }

    /**
     * Parent model's [created-at, updated-at] column names via a constructor-less instance
     * (`getCreatedAtColumn()`/`getUpdatedAtColumn()`), both plain overridable methods, hence the instance
     * rather than reading the `CREATED_AT` / `UPDATED_AT` constants. Keeps positions: a model with
     * `CREATED_AT = null` has no created-at column, which the callers treat per slot. Null when the names
     * are unknown (no parent model, or it cannot be instantiated, e.g. abstract): callers must never
     * assume the `created_at` / `updated_at` defaults then.
     *
     * @param ?class-string<Model> $declaringModel
     * @return ?array{?non-empty-string, ?non-empty-string}
     */
    public static function parentTimestampNames(?string $declaringModel): ?array
    {
        if ($declaringModel === null) {
            return null;
        }

        try {
            $model = (new \ReflectionClass($declaringModel))->newInstanceWithoutConstructor();

            $created = $model->getCreatedAtColumn();
            $updated = $model->getUpdatedAtColumn();

            return [
                $created !== null && $created !== '' ? $created : null,
                $updated !== null && $updated !== '' ? $updated : null,
            ];
        } catch (\Throwable) {
            return null;
        }
    }
}
