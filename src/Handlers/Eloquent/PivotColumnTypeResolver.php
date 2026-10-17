<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use Illuminate\Database\Eloquent\Concerns\HasRelationships;
use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Illuminate\Database\Eloquent\Relations\Pivot;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\String_;
use Psalm\Codebase;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Internal\MethodIdentifier;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ColumnInfo;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\ColumnTypeMapper;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaColumn;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaStateProvider;
use Psalm\Type;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TObjectWithProperties;
use Psalm\Type\Union;

/** @internal */
final class PivotColumnTypeResolver
{
    /**
     * Read literal identifier columns and a proven explicit table from a relation call chain.
     * Unsupported argument forms add no names, and unproven factory/table provenance yields a null table.
     *
     * @return array{table: ?string, columns: ?list<string>}|null
     */
    public static function describe(Expr $call, Codebase $codebase, ?StatementsAnalyzer $source = null, ?string $scopeClass = null): ?array
    {
        if (!$call instanceof MethodCall) {
            return null;
        }

        $columns = [];
        $sawWithPivot = false;
        $expr = $call;

        while ($expr instanceof MethodCall) {
            if (!$expr->name instanceof Identifier) {
                return null;
            }

            $method = \strtolower($expr->name->name);
            if ($method === 'withpivot') {
                $sawWithPivot = true;
                foreach (self::readWithPivotArgs($expr->getArgs()) ?? [] as $column) {
                    $columns[$column] = true;
                }
            } elseif (\in_array($method, ['belongstomany', 'morphtomany', 'morphedbymany'], true)) {
                return [
                    'table' => self::provenFactoryTable($expr, $method, $codebase, $source, $scopeClass)
                        ? self::readTableArg($expr, $method)
                        : null,
                    'columns' => \array_keys($columns),
                ];
            } elseif (!\in_array($method, ['using', 'as', 'withtimestamps'], true)) {
                return $sawWithPivot ? ['table' => null, 'columns' => \array_keys($columns)] : null;
            }

            $expr = $expr->var;
        }

        return $sawWithPivot ? ['table' => null, 'columns' => \array_keys($columns)] : null;
    }

    /**
     * @param array<array-key, Arg> $args
     * @return list<string>|null
     * @psalm-capabilities read-props
     */
    private static function readWithPivotArgs(array $args): ?array
    {
        if ($args === []) {
            return null;
        }

        if (\count($args) === 1 && !$args[0]->unpack && ($args[0]->name === null || $args[0]->name->toString() === 'columns') && $args[0]->value instanceof Expr\Array_) {
            $values = [];
            foreach ($args[0]->value->items as $item) {
                if ($item === null || $item->unpack || !$item->value instanceof String_) {
                    return null;
                }

                if (self::isColumnIdentifier($item->value->value)) {
                    $values[$item->value->value] = true;
                }
            }

            return \array_keys($values);
        }

        $values = [];
        foreach ($args as $index => $arg) {
            if ($arg->unpack || ($arg->name !== null && ($index !== 0 || $arg->name->toString() !== 'columns')) || !$arg->value instanceof String_) {
                return null;
            }

            if (self::isColumnIdentifier($arg->value->value)) {
                $values[$arg->value->value] = true;
            }
        }

        return \array_keys($values);
    }

    /** @psalm-pure */
    private static function isColumnIdentifier(string $column): bool
    {
        return \preg_match('/\\A[a-zA-Z_][a-zA-Z0-9_]*\\z/', $column) === 1;
    }

    private static function provenFactoryTable(
        MethodCall $factory,
        string $method,
        Codebase $codebase,
        ?StatementsAnalyzer $source,
        ?string $scopeClass,
    ): bool {
        $modelClass = null;
        if ($factory->var instanceof \PhpParser\Node\Expr\Variable && $factory->var->name === 'this') {
            $modelClass = $scopeClass;
        } elseif ($source instanceof \Psalm\Internal\Analyzer\StatementsAnalyzer) {
            $receiver = $source->getNodeTypeProvider()->getType($factory->var);
            if ($receiver instanceof Union && $receiver->isSingle()) {
                $atomic = $receiver->getSingleAtomic();
                if ($atomic instanceof TNamedObject) {
                    $modelClass = $atomic->value;
                }
            }
        }

        if ($modelClass === null) {
            return false;
        }

        try {
            $declaring = $codebase->methods->getDeclaringMethodId(new MethodIdentifier($modelClass, \strtolower($method)));

            return $declaring instanceof MethodIdentifier
                && \strcasecmp($declaring->fq_class_name, HasRelationships::class) === 0;
        } catch (\InvalidArgumentException|\UnexpectedValueException|\Psalm\Exception\UnpopulatedClasslikeException) {
            return false;
        }
    }

    private static function readTableArg(MethodCall $factory, string $method): ?string
    {
        $position = $method === 'belongstomany' ? 1 : 2;
        $name = 'table';
        $value = null;

        foreach ($factory->getArgs() as $index => $arg) {
            if ($arg->name !== null) {
                if ($arg->name->toString() === $name) {
                    $value = $arg->value;
                    break;
                }

                continue;
            }

            if ($index === $position) {
                $value = $arg->value;
                break;
            }
        }

        return $value instanceof String_ && $value->value !== '' ? $value->value : null;
    }

    /**
     * Add the selected columns to the pivot atomic. A missing schema/table/column is mixed,
     * preserving the selected property without inventing its database type.
     *
     * @param list<string>|null $columns
     */
    public static function refinePivot(Union $pivot, ?string $table, ?array $columns): Union
    {
        if ($columns === null || $columns === []) {
            return $pivot;
        }

        $properties = [];
        foreach ($columns as $columnName) {
            $properties[$columnName] = self::columnType($table, $columnName);
        }

        $refined = [];
        foreach ($pivot->getAtomicTypes() as $atomic) {
            if (!$atomic instanceof TNamedObject || !\in_array(\strtolower($atomic->value), [\strtolower(Pivot::class), \strtolower(MorphPivot::class)], true)) {
                return $pivot;
            }

            $intersections = $atomic->getIntersectionTypes();
            $mergedProperties = [];
            foreach ($intersections as $key => $intersection) {
                if ($intersection instanceof TObjectWithProperties) {
                    foreach ($intersection->properties as $name => $type) {
                        if (!isset($mergedProperties[$name]) || $mergedProperties[$name]->isMixed()) {
                            $mergedProperties[$name] = $type;
                        }
                    }

                    unset($intersections[$key]);
                }
            }

            foreach ($properties as $name => $type) {
                if (!isset($mergedProperties[$name]) || $mergedProperties[$name]->isMixed()) {
                    $mergedProperties[$name] = $type;
                }
            }

            $mergedShape = new TObjectWithProperties($mergedProperties);
            $intersections[$mergedShape->getKey()] = $mergedShape;
            $refined[] = $atomic->setIntersectionTypes($intersections);
        }

        return $pivot->setTypes($refined);
    }

    private static function columnType(?string $table, string $name): Union
    {
        $schema = SchemaStateProvider::getSchema();
        $column = $table !== null ? ($schema?->tables[$table]->columns[$name] ?? null) : null;
        if (!$column instanceof SchemaColumn || $column->name === '' || $column->type === '') {
            return Type::getMixed();
        }

        $mapped = ColumnTypeMapper::mapBaseType(new ColumnInfo(
            $column->name,
            $column->type,
            $column->nullable,
            $column->default instanceof \Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaColumnDefault,
            $column->unsigned,
            \array_values($column->options),
        ));

        return $column->nullable ? Type::combineUnionTypes($mapped, Type::getNull()) : $mapped;
    }
}
