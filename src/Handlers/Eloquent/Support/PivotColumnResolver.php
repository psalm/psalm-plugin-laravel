<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent\Support;

use Illuminate\Database\Eloquent\Concerns\HasRelationships;
use Illuminate\Database\Eloquent\Model;
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
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ModelMetadataRegistryBuilder;
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
            if ($value instanceof String_ && $value->value !== '' && !\str_contains($value->value, '.')) {
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
     * Each column's type from the migration schema of $table, `mixed` when unknown.
     *
     * @param list<string> $columns
     * @return array<string, Union>
     */
    public static function columnTypes(?string $table, array $columns): array
    {
        $schemaTable = $table === null ? null : SchemaStateProvider::getSchema()?->tables[$table] ?? null;
        $types = [];
        foreach ($columns as $column) {
            $schemaColumn = $schemaTable?->columns[$column] ?? null;
            // Pivot::fromAttributes() enables timestamps when created_at is selected; they then read as Carbon.
            if ($schemaColumn === null || $column === 'created_at' || $column === 'updated_at') {
                $types[$column] = Type::getMixed();
                continue;
            }

            $type = ColumnTypeMapper::mapBaseType(ModelMetadataRegistryBuilder::buildColumnInfo($schemaColumn));
            $types[$column] = $schemaColumn->nullable ? Type::combineUnionTypes($type, Type::getNull()) : $type;
        }

        return $types;
    }

    /**
     * $pivot with $properties added to its object shape (a repeated column takes the new type), or
     * null unless $pivot is exactly `Pivot` / `MorphPivot`, optionally already carrying one shape.
     *
     * @param array<string, Union> $properties
     * @psalm-mutation-free
     */
    public static function refine(Atomic $pivot, array $properties): ?TNamedObject
    {
        if (
            $pivot::class !== TNamedObject::class
            || !\in_array(\strtolower($pivot->value), [\strtolower(Pivot::class), \strtolower(MorphPivot::class)], true)
        ) {
            return null;
        }

        $existing = [];
        foreach ($pivot->extra_types as $extra) {
            if (!$extra instanceof TObjectWithProperties || $extra->methods !== [] || $existing !== []) {
                return null;
            }

            $existing = $extra->properties;
        }

        $shape = new TObjectWithProperties(\array_replace($existing, $properties));

        return $pivot->setIntersectionTypes([$shape->getKey() => $shape]);
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
            && \in_array(\strtolower($declaring->fq_class_name), [\strtolower(HasRelationships::class), \strtolower(Model::class)], true);
    }
}
