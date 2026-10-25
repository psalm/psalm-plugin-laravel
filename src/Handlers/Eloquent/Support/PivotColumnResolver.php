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
use Illuminate\Support\DateFactory;
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
use Psalm\Internal\Type\TypeExpander;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ModelMetadata;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ModelMetadataRegistry;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ModelMetadataRegistryBuilder;
use Psalm\LaravelPlugin\Handlers\Eloquent\ModelPropertyHandler;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\CastResolver;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\ColumnTypeMapper;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaStateProvider;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Type;
use Psalm\Type\Atomic;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TObjectWithProperties;
use Psalm\Type\TypeNode;
use Psalm\Type\TypeVisitor;
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
    private const FACTORY_TABLE_POSITION = ['belongstomany' => 1, 'morphtomany' => 2, 'morphedbymany' => 2];

    /** Declarers of the Laravel defaults a model may inherit. */
    private const STOCK_DECLARERS = [HasRelationships::class, HasTimestamps::class, HasAttributes::class, AsPivot::class, Model::class];

    /** Relation methods that return `$this` and cannot change the pivot class or table (Laravel 12.27 and 13). */
    private const PIVOT_PRESERVING = [
        'withpivot', 'withtimestamps', 'as', 'withpivotvalue', 'orderbypivot',
        'wherepivot', 'wherepivotbetween', 'wherepivotnotbetween', 'wherepivotin', 'wherepivotnotin', 'wherepivotnull', 'wherepivotnotnull',
        'orwherepivot', 'orwherepivotbetween', 'orwherepivotnotbetween', 'orwherepivotin', 'orwherepivotnotin', 'orwherepivotnull', 'orwherepivotnotnull',
    ];

    /**
     * Reads the receiver chain from $outer down to its many-to-many factory call: the columns its calls select
     * (innermost first), the class the outermost `using()` sets and the pivot table. `table` is false when the
     * factory is not called on `$this`: it takes that model's timestamp names and default table, not the receiver's.
     * `visible` is true only when every link is a method that returns the same relation with the same pivot class,
     * or a `using()` that names its class: the outermost one runs last and wins, so a dynamic one hides the chain.
     *
     * @param \Closure(MethodCall): ?string $usingClass the class a `using()` call names
     * @return array{columns: list<string>, using: ?string, table: string|false|null, visible: bool}
     */
    public static function readChain(Codebase $codebase, Expr $outer, ?string $parentClass, ?string $relatedClass, \Closure $usingClass): array
    {
        $columns = [];
        $using = null;
        $usingSeen = false;
        $visible = true;
        for ($call = $outer; $call instanceof MethodCall && $call->name instanceof Identifier; $call = $call->var) {
            $name = \strtolower($call->name->name);
            if (isset(self::FACTORY_TABLE_POSITION[$name])) {
                $onThis = $call->var instanceof Expr\Variable && $call->var->name === 'this';
                $table = $onThis ? self::table($codebase, $call, $name, $relatedClass, $parentClass) : false;

                // An unproven link may have swapped in another factory's relation: a table NAME is not the pivot's,
                // but a refusal (false) stands.
                return [
                    'columns' => $columns,
                    'using' => $using,
                    'table' => \is_string($table) && !$visible ? null : $table,
                    'visible' => $visible && $onThis,
                ];
            }

            if ($name === 'using') {
                if (!$usingSeen) {
                    $usingSeen = true;
                    $using = $usingClass($call);
                    $visible = $visible && $using !== null;
                }
            } else {
                $visible = $visible && self::keepsPivot($name);
            }

            $columns = [...self::callColumns($codebase, $call, $name, $parentClass), ...$columns];
        }

        return ['columns' => $columns, 'using' => $using, 'table' => null, 'visible' => false];
    }

    /**
     * Whether a call returns the relation it was made on, with the same pivot class. `using()`, `when()`,
     * `tap()`, macros and forwarded query calls can replace the relation, so they are not on the list.
     *
     * @param lowercase-string $name the call's method name
     * @psalm-pure
     */
    public static function keepsPivot(string $name): bool
    {
        return \in_array($name, self::PIVOT_PRESERVING, true);
    }

    /**
     * The columns one chain call selects: a `withPivot()` call's literals, a `withTimestamps()` call's names.
     *
     * @param lowercase-string $name the call's method name
     * @return list<string>
     */
    public static function callColumns(Codebase $codebase, MethodCall $call, string $name, ?string $parentClass): array
    {
        return match ($name) {
            'withpivot' => self::columnsFromArgs($call->args),
            'withtimestamps' => self::timestampColumns($codebase, $call, $parentClass),
            default => [],
        };
    }

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
    private static function columnsFromArgs(array $args): array
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
    private static function table(
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
     * Each column's type, first hit wins: the custom `using()` pivot's own answer, the date type of the parent's
     * created/updated column when the pivot's hydration provably casts it, the relation table's schema type,
     * `mixed` (rationale: decisions.md, "`withPivot()` columns refine TPivotModel").
     *
     * @param list<string> $columns
     * @param ?string $parentClass the model the relation is called on
     * @param bool $visible every link between the factory and the call is a pivot-preserving method
     * @param ?string $using the pivot class a visible chain's `using()` sets, or the custom class of a hidden slot;
     *     null for the plain pivot
     * @return array<string, Union>
     */
    public static function columnTypes(Codebase $codebase, ?string $table, array $columns, ?string $parentClass, bool $visible, ?string $using = null): array
    {
        // On the `using()` path the relation table is read only on a visible chain: a hidden one may have swapped it.
        $schemaTable = $table === null || $using !== null && !$visible ? null : SchemaStateProvider::getSchema()?->tables[$table] ?? null;
        $parent = $parentClass === null ? null : self::parentTimestamps($codebase, $parentClass);
        // A plain pivot's `fromAttributes()` switches its date casts on when its OWN `created_at` is selected. Any
        // `using()` pivot hydrates raw attributes and checks the parent's column instead, so only a visible chain
        // proves which kind it is, and a class that moves its date handling or raw reads proves nothing.
        $raw = $using !== null && $visible && self::hasStockHydration($codebase, $using);
        $dates = $using === null
            ? $visible && \in_array(Model::CREATED_AT, $columns, true)
            : $raw && $parent !== null && $parent[0] !== null && \in_array($parent[0], $columns, true);
        $types = [];
        foreach ($columns as $column) {
            $schemaColumn = $schemaTable?->columns[$column] ?? null;
            $own = $using === null || self::isFramework($using) ? null : self::ownType($codebase, $using, $column, $schemaColumn?->nullable ?? true, $raw);
            if ($own instanceof Union) {
                $types[$column] = $own;
            } elseif ($parent !== null && ($column === $parent[0] || $column === $parent[1])) {
                $types[$column] = ($dates ? self::dateType($codebase, $schemaColumn?->nullable ?? true) : null) ?? Type::getMixed();
            } elseif (
                $parent === null || $schemaColumn === null
                // A plain pivot's forceFill() runs before it has a parent, so it date-normalizes the DEFAULT
                // names into strings that the parent's own names never read back as dates.
                || ($using === null ? \in_array($column, [Model::CREATED_AT, Model::UPDATED_AT], true) : !$raw)
            ) {
                $types[$column] = Type::getMixed();
            } else {
                $type = ColumnTypeMapper::mapRawType(ModelMetadataRegistryBuilder::buildColumnInfo($schemaColumn));
                $types[$column] = $schemaColumn->nullable ? Type::combineUnionTypes($type, Type::getNull()) : $type;
            }
        }

        return $types;
    }

    /**
     * The pivot class whose own types the columns read: a visible chain's `using()` class, else a hidden slot
     * that is a custom pivot. Null for the framework's plain pivot, where a hidden chain proves nothing, and for a
     * templated class, whose parameters a rebuilt shape would lose.
     *
     * @psalm-capabilities read-props
     */
    public static function usingFor(Codebase $codebase, ?string $class, bool $visible): ?string
    {
        return $class !== null && ($visible || !self::isFramework($class)) && !self::isGeneric($codebase, $class) ? $class : null;
    }

    /**
     * $pivot with $properties added to its object shape (a column already in it keeps its type: Laravel
     * only appends pivot columns, it never retypes one), or null unless $pivot is exactly `$using` (else `Pivot` /
     * `MorphPivot`), optionally already carrying one shape.
     *
     * @param array<string, Union> $properties
     * @psalm-pure
     */
    public static function refine(Atomic $pivot, array $properties, ?string $using = null): ?TNamedObject
    {
        if ($pivot::class !== TNamedObject::class || ($using === null ? !self::isFramework($pivot->value) : \strcasecmp($pivot->value, $using) !== 0)) {
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
     * `false` disables the column in Laravel 13 and names none in 12.27, a dynamic name is unknown: neither adds one.
     *
     * @return list<string>
     */
    private static function timestampColumns(Codebase $codebase, MethodCall $call, ?string $parentClass): array
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
            if ($value instanceof String_) {
                $column = $value->value;
            } elseif ($parent !== null && (!$value instanceof Expr || $value instanceof Expr\ConstFetch && $value->name->toLowerString() === 'null')) {
                $column = $parent[$index] ?? $default;
            } else {
                continue;
            }

            if (self::isColumnName($column)) {
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
     * The configured date class, as `Date::use()` left it in the booted app: null when a callable or a factory
     * builds the dates (the class is unknowable), the framework default when unconfigured.
     */
    private static function dateType(Codebase $codebase, bool $nullable): ?Union
    {
        [$class, $callable, $factory] = \array_map(
            static fn(string $name): mixed => (new \ReflectionProperty(DateFactory::class, $name))->getValue(),
            ['dateClass', 'callable', 'factory'],
        );

        if ($callable !== null || $factory !== null) {
            return null;
        }

        if ($class === null) {
            return CastResolver::resolve($codebase, 'datetime', $nullable);
        }

        if (!\is_string($class) || !$codebase->classlikes->classExists($class)) {
            return null;
        }

        $type = new Union([new TNamedObject($class)]);

        return $nullable ? Type::combineUnionTypes($type, Type::getNull()) : $type;
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

        return $declaring instanceof MethodIdentifier && \in_array($declaring->fq_class_name, self::STOCK_DECLARERS, true);
    }

    /**
     * The type custom pivot $class gives $column itself, null when it says nothing. Psalm's direct-read order:
     * a native property, an `@property`, an accessor, then the cast or column of the class's default table; those
     * beat an object shape on read, so the shape must repeat them, bound to $class as a direct read binds them.
     * A class the registry does not hold completely may transform the column past the first two: `mixed`. A cast on
     * a column the default table lacks still applies to the relation table's column, which is null-passing for
     * primitive casts, unless the class changes how raw values are read.
     *
     * @param bool $nullable whether the column may be null in the relation's table
     * @param bool $proven the chain is visible and the class keeps the stock hydration, so the cast fallback applies
     */
    private static function ownType(Codebase $codebase, string $class, string $column, bool $nullable, bool $proven): ?Union
    {
        try {
            $storage = $codebase->classlike_storage_provider->get($class);
            $declaring = $storage->declaring_property_ids[$column] ?? null;
            if ($declaring !== null) {
                return self::bind($codebase, $storage, ($codebase->classlike_storage_provider->get($declaring)->properties[$column] ?? null)?->type);
            }
        } catch (\InvalidArgumentException) {
            return Type::getMixed();
        }

        $pseudo = $storage->pseudo_property_get_types['$' . $column] ?? null;
        if ($pseudo instanceof Union) {
            return self::bind($codebase, $storage, $pseudo);
        }

        /** @var class-string<Model> $class */
        $metadata = ModelMetadataRegistry::for($class);
        if (!$metadata instanceof ModelMetadata || !$metadata->isComplete(ModelMetadata::SECTION_METHODS | ModelMetadata::SECTION_CASTS)) {
            return Type::getMixed();
        }

        $accessor = $metadata->accessor($column)?->returnType;
        if ($accessor instanceof Union) {
            return self::bind($codebase, $storage, $accessor);
        }

        // Cast-derived types belong to the cast's class, not to the pivot.
        $own = ModelPropertyHandler::resolveColumnType($codebase, $class, $column);
        if ($own instanceof Union) {
            return self::unbound($own);
        }

        $cast = ($metadata->casts()[$column] ?? null)?->psalmType;
        if (!$cast instanceof Union) {
            return null;
        }

        return !$proven ? Type::getMixed() : self::unbound($nullable ? Type::combineUnionTypes($cast, Type::getNull()) : $cast);
    }

    /**
     * $type as a direct read of the property binds it: `self`, `static`, `$this` and `parent` are the pivot class
     * itself. Left relative they would resolve against whatever class reads the shape. `mixed` when not provable.
     */
    private static function bind(Codebase $codebase, ClassLikeStorage $storage, ?Union $type): Union
    {
        if (!$type instanceof Union) {
            return Type::getMixed();
        }

        return self::unbound(TypeExpander::expandUnion($codebase, $type, $storage->name, $storage->name, $storage->parent_class, final: true));
    }

    /**
     * $type, or `mixed` when `self` / `static` / `$this` / `parent` is left in it, generics, shapes and callables
     * included: a type the pivot did not declare (a cast's) is relative to another class.
     *
     * @psalm-pure
     */
    private static function unbound(Union $type): Union
    {
        $visitor = new class extends TypeVisitor {
            /** @psalm-pure */
            #[\Override]
            protected function enterNode(TypeNode $type): ?int
            {
                $relative = $type instanceof TNamedObject
                    && ($type->is_static || \in_array(\strtolower($type->value), ['self', 'static', 'parent', '$this'], true));

                return $relative ? self::STOP_TRAVERSAL : null;
            }
        };

        // traverse() returns false exactly when enterNode() stopped the traversal.
        return $visitor->traverse($type) ? $type : Type::getMixed();
    }

    /**
     * Whether the pivot class hydrates and reads attributes as stock Laravel does, so the parent's created column
     * and `getDates()` alone decide which columns are dates and the relation table's raw values are what is read.
     * The class (own body, ancestors, traits) must override no method of `HasAttributes`, `HasTimestamps` or
     * `AsPivot` except `casts()`, nor `__get()` / `__call()` of `Model`. Accessors, mutators, relations and
     * lifecycle hooks are no Laravel methods, so they stay stock.
     *
     * @psalm-capabilities read-props
     */
    private static function hasStockHydration(Codebase $codebase, string $class): bool
    {
        if (self::isFramework($class)) {
            return true;
        }

        try {
            $declared = $codebase->classlike_storage_provider->get($class)->declaring_method_ids;
        } catch (\InvalidArgumentException) {
            return false;
        }

        $surface = ['__get' => true, '__call' => true];
        foreach ([HasAttributes::class, HasTimestamps::class, AsPivot::class] as $concern) {
            foreach ((new \ReflectionClass($concern))->getMethods() as $method) {
                $surface[\strtolower($method->name)] = true;
            }
        }

        unset($surface['casts']);

        foreach ($declared as $name => $id) {
            if (isset($surface[$name]) && !\in_array($id->fq_class_name, self::STOCK_DECLARERS, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * A templated pivot class loses its parameters when rebuilt from its name.
     *
     * @psalm-capabilities read-props
     */
    private static function isGeneric(Codebase $codebase, string $class): bool
    {
        try {
            return $codebase->classlike_storage_provider->get($class)->template_types !== null;
        } catch (\InvalidArgumentException) {
            return true;
        }
    }

    /** @psalm-pure */
    private static function isFramework(string $class): bool
    {
        return \strcasecmp($class, Pivot::class) === 0 || \strcasecmp($class, MorphPivot::class) === 0;
    }
}
