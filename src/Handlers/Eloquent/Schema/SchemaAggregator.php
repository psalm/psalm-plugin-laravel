<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent\Schema;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PhpParser;
use PhpParser\NodeFinder;

final class SchemaAggregator
{
    /**
     * @psalm-var list<lowercase-string>
     * @see \Illuminate\Database\Schema\Blueprint
     */
    private const METHODS_USE_HARDCODED_COLUMN_NAME = [
        'timestamps',
        'timestampstz',
        'nullabletimestamps',
        'nullabletimestampstz',
        'droptimestamps',
        'droptimestampstz',
        'dropremembertoken',
        'remembertoken',
    ];

    /**
     * @psalm-var array<lowercase-string, non-empty-string>
     * @see \Illuminate\Database\Schema\Blueprint
     */
    private const METHODS_HAVE_DEFAULT_COLUMN_NAME = [
        'id' => 'id',
        'dropsoftdeletes' => 'deleted_at',
        'dropsoftdeletesdatetime' => 'deleted_at',
        'dropsoftdeletestz' => 'deleted_at',
        'softdeletes' => 'deleted_at',
        'softdeletesdatetime' => 'deleted_at',
        'softdeletestz' => 'deleted_at',
        'uuid' => 'uuid',
        'ulid' => 'uuid',
        'ipaddress' => 'ip_address',
        'macaddress' => 'mac_address',
    ];

    /**
     * Blueprint methods that produce unsigned integers (non-negative-int).
     *
     * @psalm-var list<lowercase-string>
     */
    private const UNSIGNED_INT_METHODS = [
        'bigincrements',
        'foreignid',
        'id',
        'increments',
        'integerincrements',
        'mediumincrements',
        'smallincrements',
        'tinyincrements',
        'unsignedbiginteger',
        'unsignedinteger',
        'unsignedmediuminteger',
        'unsignedsmallinteger',
        'unsignedtinyinteger',
    ];

    /** @var array<string, SchemaTable> */
    public array $tables = [];

    /** @psalm-external-mutation-free */
    public function setTable(string $name, SchemaTable $table): void
    {
        $this->tables[$name] = $table;
    }

    /**
     * @param array<int, PhpParser\Node\Stmt> $stmts
     */
    public function addStatements(array $stmts): void
    {
        $nodeFinder = new NodeFinder();

        $classes = $nodeFinder->findInstanceOf($stmts, PhpParser\Node\Stmt\Class_::class);

        foreach ($classes as $stmt) {
            $this->addClassStatements($stmt->stmts);
        }
    }

    /**
     * Process all class methods except down() to catch Schema calls in helper methods.
     *
     * Migrations often delegate Schema calls to private helper methods called from up().
     * By processing all methods except down(), we capture these delegated calls.
     * This matches Larastan's behavior. See https://github.com/psalm/psalm-plugin-laravel/issues/507
     *
     * @param array<int, PhpParser\Node\Stmt> $stmts
     */
    private function addClassStatements(array $stmts): void
    {
        foreach ($stmts as $stmt) {
            if (
                $stmt instanceof PhpParser\Node\Stmt\ClassMethod
                && \strtolower($stmt->name->toString()) !== 'down'
                && \is_array($stmt->stmts)
            ) {
                $this->addMethodStatements($stmt->stmts);
            }
        }
    }

    /**
     * @param array<array-key, \PhpParser\Node\Stmt> $stmts
     */
    private function addMethodStatements(array $stmts): void
    {
        // Flatten nested block structures (if/else, try/catch, foreach, etc.)
        // so Schema calls inside conditionals are not missed.
        foreach (self::flattenStatements($stmts) as $stmt) {
            if (!$stmt instanceof PhpParser\Node\Stmt\Expression) {
                continue;
            }

            $schema_call = self::extractSchemaCall($stmt->expr);
            if ($schema_call === null || !$schema_call->name instanceof PhpParser\Node\Identifier) {
                continue;
            }

            switch ($schema_call->name->name) {
                case 'create':
                    $this->alterTable($schema_call, creating: true);
                    break;

                case 'table':
                    $this->alterTable($schema_call, creating: false);
                    break;

                case 'drop':
                case 'dropIfExists':
                    $this->dropTable($schema_call);
                    break;

                case 'dropColumns':
                    $this->dropColumnsFromTable($schema_call);
                    break;

                case 'rename':
                    $this->renameTable($schema_call);
            }
        }
    }

    /**
     * Extract a Schema facade call from an expression, if present.
     *
     * Handles two forms:
     * - Direct:  Schema::create('users', ...)
     * - Chained: Schema::connection('mysql')->create('users', ...)
     *
     * Only single-level chaining via connection() is detected.
     * Deeper chains like Schema::connection()->connection()->create() are not supported
     * because they are invalid at runtime.
     */
    private static function extractSchemaCall(
        PhpParser\Node\Expr $expr,
    ): PhpParser\Node\Expr\StaticCall|PhpParser\Node\Expr\MethodCall|null {
        // Direct Schema facade call: Schema::create(...), Schema::table(...), etc.
        if (
            $expr instanceof PhpParser\Node\Expr\StaticCall
            && $expr->class instanceof PhpParser\Node\Name
            && $expr->name instanceof PhpParser\Node\Identifier
            && self::isSchemaClass($expr->class->getAttribute('resolvedName'))
        ) {
            return $expr;
        }

        // Connection-chained call: Schema::connection('mysql')->create(...), etc.
        // The outer expression is a MethodCall whose var is a StaticCall on Schema.
        if (
            $expr instanceof PhpParser\Node\Expr\MethodCall
            && $expr->name instanceof PhpParser\Node\Identifier
            && $expr->var instanceof PhpParser\Node\Expr\StaticCall
            && $expr->var->class instanceof PhpParser\Node\Name
            && $expr->var->name instanceof PhpParser\Node\Identifier
            && $expr->var->name->name === 'connection'
            && self::isSchemaClass($expr->var->class->getAttribute('resolvedName'))
        ) {
            return $expr;
        }

        return null;
    }

    private function alterTable(PhpParser\Node\Expr\StaticCall|PhpParser\Node\Expr\MethodCall $call, bool $creating): void
    {
        if (
            !isset($call->args[0])
            || !$call->args[0] instanceof PhpParser\Node\Arg
        ) {
            return;
        }

        $table_name = self::resolveTableName($call->args[0]->value);
        if ($table_name === null) {
            return;
        }

        if (
            !isset($call->args[1])
            || !$call->args[1] instanceof PhpParser\Node\Arg
            || !$call->args[1]->value instanceof PhpParser\Node\Expr\Closure
            || \count($call->args[1]->value->params) < 1
            || ($call->args[1]->value->params[0]->type instanceof PhpParser\Node\Name
                && $call->args[1]->value->params[0]->type->getAttribute('resolvedName')
                !== Blueprint::class)
        ) {
            return;
        }

        // Schema::create() always starts fresh — a second create replaces the table.
        // Schema::table() may reference a table created in a migration not seen by
        // the aggregator (e.g., squashed into an SQL dump or published from a package),
        // so auto-create if missing. Only done after validating the closure argument
        // to avoid registering empty tables from unparsable calls.
        if ($creating || !isset($this->tables[$table_name])) {
            $this->tables[$table_name] = new SchemaTable();
        }

        $update_closure = $call->args[1]->value;

        if (
            $call->args[1]->value->params[0]->var instanceof PhpParser\Node\Expr\Variable
            && \is_string($call->args[1]->value->params[0]->var->name)
        ) {
            $call_arg_name = $call->args[1]->value->params[0]->var->name;

            $this->processColumnUpdates($table_name, $call_arg_name, $update_closure->stmts);
        }
    }

    private function dropTable(PhpParser\Node\Expr\StaticCall|PhpParser\Node\Expr\MethodCall $call): void
    {
        if (
            !isset($call->args[0])
            || !$call->args[0] instanceof PhpParser\Node\Arg
        ) {
            return;
        }

        $table_name = self::resolveTableName($call->args[0]->value);
        if ($table_name === null) {
            return;
        }

        unset($this->tables[$table_name]);
    }

    /**
     * Handle Schema::dropColumns($table, $columns) — drops columns without a closure.
     */
    private function dropColumnsFromTable(PhpParser\Node\Expr\StaticCall|PhpParser\Node\Expr\MethodCall $call): void
    {
        if (
            !isset($call->args[0], $call->args[1])
            || !$call->args[0] instanceof PhpParser\Node\Arg
            || !$call->args[1] instanceof PhpParser\Node\Arg
        ) {
            return;
        }

        $table_name = self::resolveTableName($call->args[0]->value);
        if ($table_name === null) {
            return;
        }

        if (!isset($this->tables[$table_name])) {
            return;
        }

        $table = $this->tables[$table_name];
        $columns_arg = $call->args[1]->value;

        if ($columns_arg instanceof PhpParser\Node\Scalar\String_) {
            $table->dropColumn($columns_arg->value);
        } elseif ($columns_arg instanceof PhpParser\Node\Expr\Array_) {
            foreach ($columns_arg->items as $item) {
                if ($item !== null && $item->value instanceof PhpParser\Node\Scalar\String_) {
                    $table->dropColumn($item->value->value);
                }
            }
        }
    }

    private function renameTable(PhpParser\Node\Expr\StaticCall|PhpParser\Node\Expr\MethodCall $call): void
    {
        if (
            !isset($call->args[0], $call->args[1])
            || !$call->args[0] instanceof PhpParser\Node\Arg
            || !$call->args[1] instanceof PhpParser\Node\Arg
        ) {
            return;
        }

        $old_table_name = self::resolveTableName($call->args[0]->value);
        $new_table_name = self::resolveTableName($call->args[1]->value);
        if ($old_table_name === null || $new_table_name === null) {
            return;
        }

        if (!isset($this->tables[$old_table_name])) {
            return;
        }

        $table = $this->tables[$old_table_name];

        unset($this->tables[$old_table_name]);

        // Skip if the target already exists — the rename is inside a conditional
        // (e.g., `if (Schema::hasTable('old'))`) that wouldn't have executed when
        // the table was already created under the new name.
        if (!isset($this->tables[$new_table_name])) {
            $this->tables[$new_table_name] = $table;
        }
    }

    /**
     * @param array<array-key, PhpParser\Node\Stmt> $stmts
     */
    private function processColumnUpdates(string $table_name, string $call_arg_name, array $stmts): void
    {
        if (!isset($this->tables[$table_name])) {
            return;
        }

        $table = $this->tables[$table_name];

        // Flatten nested block structures so Blueprint calls inside
        // conditionals (e.g., if (!Schema::hasColumn(...))) are discovered.
        foreach (self::flattenStatements($stmts) as $stmt) {
            $is_named_method_call = $stmt instanceof PhpParser\Node\Stmt\Expression
                && $stmt->expr instanceof PhpParser\Node\Expr\MethodCall
                && $stmt->expr->name instanceof PhpParser\Node\Identifier;

            if (!$is_named_method_call) {
                continue;
            }

            $root_var = $stmt->expr;

            $first_method_call = $root_var;

            $nullable = false;
            /** @var SchemaColumnDefault|null $default */
            $default = null;
            $unsigned = false;

            while ($root_var instanceof PhpParser\Node\Expr\MethodCall) {
                if ($root_var->name instanceof PhpParser\Node\Identifier) {
                    if ($root_var->name->name === 'nullable') {
                        $nullable = true;

                        /**
                         * Possible cases:
                         * ->nullable()
                         * ->nullable(false)
                         * ->nullable(true)
                         *
                         * Process this ->nullable(false)
                         */
                        if ($root_var->args !== []) {
                            $first_argument_of_nullable = $root_var->args[0];
                            if (
                                $first_argument_of_nullable instanceof PhpParser\Node\Arg
                                && $first_argument_of_nullable->value instanceof PhpParser\Node\Expr\ConstFetch
                                && $first_argument_of_nullable->value->name->getParts() === ['false']
                            ) {
                                $nullable = false;
                            }
                        }
                    }

                    if (
                        $root_var->name->name === 'default'
                        && isset($root_var->args[0])
                        && $root_var->args[0] instanceof PhpParser\Node\Arg
                    ) {
                        $default = $this->resolveDefaultValue($root_var->args[0]->value);
                    }

                    if ($root_var->name->name === 'unsigned') {
                        $unsigned = true;
                    }
                }

                $first_method_call = $root_var;
                $root_var = $root_var->var;
            } // while

            $is_first_method_named_method = $root_var instanceof PhpParser\Node\Expr\Variable
                && $root_var->name === $call_arg_name
                && $first_method_call->name instanceof PhpParser\Node\Identifier;

            if (!$is_first_method_named_method) {
                continue;
            }

            $first_arg = $first_method_call->args[0]->value ?? null;
            $second_arg = $first_method_call->args[1]->value ?? null;

            $first_method_name_lc = \strtolower($first_method_call->name->name);

            if ($first_method_call->args === []) {
                if (\in_array($first_method_name_lc, self::METHODS_USE_HARDCODED_COLUMN_NAME, true)) {
                    switch ($first_method_name_lc) {
                        case 'droptimestamps':
                        case 'droptimestampstz':
                            $table->dropColumn('created_at');
                            $table->dropColumn('updated_at');
                            break;

                        case 'remembertoken':
                            $table->setColumn(new SchemaColumn('remember_token', 'string', $nullable));
                            break;

                        case 'dropremembertoken':
                            $table->dropColumn('remember_token');
                            break;

                        case 'timestamps':
                        case 'timestampstz':
                        case 'nullabletimestamps':
                        case 'nullabletimestampstz':
                            $table->setColumn(new SchemaColumn('created_at', 'string', true));
                            $table->setColumn(new SchemaColumn('updated_at', 'string', true));
                            break;
                    }

                    continue; // foreach
                }

                if (\array_key_exists($first_method_name_lc, self::METHODS_HAVE_DEFAULT_COLUMN_NAME)) {
                    $column_name = self::METHODS_HAVE_DEFAULT_COLUMN_NAME[$first_method_name_lc];
                } else {
                    continue; // unknown type [method call without args] :/
                }
            } elseif ($first_arg instanceof PhpParser\Node\Scalar\String_) {
                $column_name = $first_arg->value;
            } elseif ($first_arg instanceof PhpParser\Node\Expr\Array_) {
                // Handle dropColumn/removeColumn with array argument: $table->dropColumn(['col1', 'col2'])
                if (\in_array($first_method_name_lc, ['dropcolumn', 'removecolumn'], true)) {
                    foreach ($first_arg->items as $item) {
                        if ($item !== null && $item->value instanceof PhpParser\Node\Scalar\String_) {
                            $table->dropColumn($item->value->value);
                        }
                    }

                    continue;
                }

                continue;
            } else {
                // foreignIdFor() with class reference: $table->foreignIdFor(User::class)
                if ($first_method_name_lc === 'foreignidfor') {
                    $column = $this->resolveForeignIdForColumn($first_arg, $second_arg, $nullable, $default);
                    if ($column instanceof \Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaColumn) {
                        $table->setColumn($column);
                    }

                    continue;
                }

                continue; // unknown type [method call with unknown argument type] :/
            }

            $second_arg_array = null;

            if ($second_arg instanceof PhpParser\Node\Expr\Array_) {
                $second_arg_array = [];

                foreach ($second_arg->items as $array_item) {
                    if ($array_item && $array_item->value instanceof PhpParser\Node\Scalar\String_) {
                        $second_arg_array[] = $array_item->value->value;
                    }
                }
            }

            // addColumn('type', 'name') → remap to the resolved type method and re-dispatch
            if (
                $first_method_name_lc === 'addcolumn'
                && $first_arg instanceof PhpParser\Node\Scalar\String_
                && $second_arg instanceof PhpParser\Node\Scalar\String_
            ) {
                $first_method_name_lc = \strtolower($first_arg->value);
                $column_name = $second_arg->value;
                $second_arg = null;
                $second_arg_array = null;
            }

            $is_unsigned = $unsigned || \in_array($first_method_name_lc, self::UNSIGNED_INT_METHODS, true);

            switch ($first_method_name_lc) {
                case 'biginteger':
                case 'bigincrements':
                case 'unsignedtinyinteger':
                case 'unsignedsmallinteger':
                case 'unsignedmediuminteger':
                case 'unsignedinteger':
                case 'unsignedbiginteger':
                case 'tinyinteger':
                case 'tinyincrements':
                case 'smallinteger':
                case 'smallincrements':
                case 'mediuminteger':
                case 'mediumincrements':
                case 'id':
                case 'integerincrements':
                case 'integer':
                case 'increments':
                case 'foreignid':
                    $table->setColumn(new SchemaColumn($column_name, 'int', $nullable, default: $default, unsigned: $is_unsigned));
                    break;

                case 'foreignidfor':
                    // foreignIdFor() accepts a class-string as first arg (e.g. foreignIdFor('App\Models\User')).
                    // If the string contains a namespace separator, it's a class name, not a column name —
                    // skip it to avoid registering a bogus column. We can only resolve class-const refs above.
                    if (\str_contains($column_name, '\\')) {
                        break;
                    }

                    $table->setColumn(new SchemaColumn($column_name, 'int', $nullable, default: $default, unsigned: true));
                    break;

                case 'binary':
                case 'foreignulid':

                case 'char':
                case 'date':
                case 'datetime':
                case 'datetimetz':
                case 'uuid':
                case 'ulid':
                case 'timestamp':
                case 'time':
                case 'tinytext':
                case 'text':
                case 'string':
                case 'multilinestring':
                case 'mediumtext':
                case 'macaddress':
                case 'longtext':
                case 'linestring':
                case 'jsonb':
                case 'json':
                case 'ipaddress':
                case 'foreignuuid':
                case 'tsvector':
                    $table->setColumn(new SchemaColumn($column_name, 'string', $nullable, default: $default));
                    break;

                case 'boolean':
                    $table->setColumn(new SchemaColumn($column_name, 'bool', $nullable, default: $default));
                    break;

                case 'vector':
                    $table->setColumn(new SchemaColumn($column_name, 'array', $nullable, default: $default));
                    break;

                case 'rawcolumn':
                case 'polygon':
                case 'point':
                case 'multipolygonz':
                case 'multipolygon':
                case 'multipoint':
                case 'geometrycollection':
                case 'geography':
                case 'geometry':
                case 'computed':
                    $table->setColumn(new SchemaColumn($column_name, 'mixed', $nullable, default: $default));
                    break;

                case 'double':
                case 'unsigneddecimal':
                case 'float':
                case 'unsignedfloat':
                case 'unsigneddouble':
                case 'decimal':
                    $table->setColumn(new SchemaColumn($column_name, 'float', $nullable, default: $default));
                    break;

                case 'removecolumn':
                case 'dropconstrainedforeignid':
                case 'dropsoftdeletestz':
                case 'dropsoftdeletesdatetime':
                case 'dropsoftdeletes':
                case 'dropifexists':
                case 'dropcolumn':
                case 'drop':
                    $table->dropColumn($column_name);
                    break;

                case 'after':
                    if (
                        $second_arg instanceof PhpParser\Node\Expr\Closure
                        && \count($second_arg->params) >= 1
                        && $second_arg->params[0]->var instanceof PhpParser\Node\Expr\Variable
                        && \is_string($second_arg->params[0]->var->name)
                    ) {
                        $this->processColumnUpdates($table_name, $second_arg->params[0]->var->name, $second_arg->stmts);
                    }

                    break;

                case 'dropforeign':
                case 'dropindex':
                case 'dropprimary':
                case 'dropunique':
                case 'unique':
                case 'spatialindex':
                case 'renameindex':
                case 'primary':
                case 'index':
                case 'foreign':
                case 'dropspatialindex':
                    break;

                case 'dropmorphs':
                    $table->dropColumn($column_name . '_type');
                    $table->dropColumn($column_name . '_id');
                    break;

                case 'enum':
                    $table->setColumn(new SchemaColumn($column_name, 'enum', $nullable, $second_arg_array ?? [], default: $default));
                    break;

                case 'numericmorphs':
                case 'morphs': // @todo support UUID and ULID types
                    $table->setColumn(new SchemaColumn($column_name . '_type', 'string', $nullable));
                    $table->setColumn(new SchemaColumn($column_name . '_id', 'int', $nullable, unsigned: true));
                    break;

                case 'nullablenumericmorphs':
                case 'nullablemorphs': // @todo support UUID and ULID types
                    $table->setColumn(new SchemaColumn($column_name . '_type', 'string', true));
                    $table->setColumn(new SchemaColumn($column_name . '_id', 'int', true, unsigned: true));
                    break;

                case 'uuidmorphs':
                case 'ulidmorphs':
                    $table->setColumn(new SchemaColumn($column_name . '_type', 'string', $nullable));
                    $table->setColumn(new SchemaColumn($column_name . '_id', 'string', $nullable));
                    break;

                case 'nullableuuidmorphs':
                case 'nullableulidmorphs':
                    $table->setColumn(new SchemaColumn($column_name . '_type', 'string', true));
                    $table->setColumn(new SchemaColumn($column_name . '_id', 'string', true));
                    break;

                case 'rename':
                    // $table->rename('new_name') - renames the table itself
                    $new_table_name = $column_name;
                    unset($this->tables[$table_name]);

                    // Skip if target already exists (same logic as renameTable)
                    if (!isset($this->tables[$new_table_name])) {
                        $this->tables[$new_table_name] = $table;
                    } else {
                        $table = $this->tables[$new_table_name];
                    }

                    $table_name = $new_table_name;

                    break;

                case 'renamecolumn':
                    if ($second_arg instanceof PhpParser\Node\Scalar\String_) {
                        $table->renameColumn($column_name, $second_arg->value);
                    }

                    break;

                case 'set':
                    $table->setColumn(new SchemaColumn($column_name, 'set', $nullable, $second_arg_array ?? [], default: $default));
                    break;

                case 'year':
                case 'timetz':
                case 'timestamptz':
                case 'softdeletesdatetime':
                case 'softdeletestz':
                case 'softdeletes':
                    $table->setColumn(new SchemaColumn($column_name, 'string', true, default: $default));
                    break;

                    // addColumn is handled above the switch via variable remapping
            }
        }
    }

    /**
     * Recursively collect all statements from nested block structures
     * (if/elseif/else, try/catch/finally, foreach, for, while, do-while, switch).
     *
     * Does NOT recurse into closures or function bodies — those have their own
     * scope and are handled separately (e.g., the ->after() closure case).
     *
     * @param array<array-key, PhpParser\Node\Stmt> $stmts
     * @return list<PhpParser\Node\Stmt>
     * @psalm-mutation-free
     */
    private static function flattenStatements(array $stmts): array
    {
        $result = [];

        foreach ($stmts as $stmt) {
            $result[] = $stmt;

            // Recurse into block structures that may contain Schema/Blueprint calls
            $nested_blocks = match (true) {
                $stmt instanceof PhpParser\Node\Stmt\If_ => [
                    $stmt->stmts,
                    ...\array_map(static fn(PhpParser\Node\Stmt\ElseIf_ $e): array => $e->stmts, $stmt->elseifs),
                    ...($stmt->else instanceof \PhpParser\Node\Stmt\Else_ ? [$stmt->else->stmts] : []),
                ],
                $stmt instanceof PhpParser\Node\Stmt\TryCatch => [
                    $stmt->stmts,
                    ...\array_map(static fn(PhpParser\Node\Stmt\Catch_ $c): array => $c->stmts, $stmt->catches),
                    ...($stmt->finally instanceof \PhpParser\Node\Stmt\Finally_ ? [$stmt->finally->stmts] : []),
                ],
                $stmt instanceof PhpParser\Node\Stmt\Foreach_,
                $stmt instanceof PhpParser\Node\Stmt\For_,
                $stmt instanceof PhpParser\Node\Stmt\While_,
                $stmt instanceof PhpParser\Node\Stmt\Do_ => [$stmt->stmts],
                $stmt instanceof PhpParser\Node\Stmt\Switch_ => \array_map(
                    static fn(PhpParser\Node\Stmt\Case_ $c): array => $c->stmts,
                    $stmt->cases,
                ),
                default => [],
            };

            foreach ($nested_blocks as $block) {
                \array_push($result, ...self::flattenStatements($block));
            }
        }

        return $result;
    }

    /**
     * Check if a class name refers to the Schema facade or a subclass of it.
     * Handles the FQCN, the root-namespace 'Schema' alias, and custom subclasses.
     *
     * Note: @psalm-pure is required by Psalm (MissingPureAnnotation).
     * Psalm considers is_a() pure despite potential autoloading side effects.
     *
     * @psalm-pure
     */
    private static function isSchemaClass(mixed $class_name): bool
    {
        if (!\is_string($class_name)) {
            return false;
        }

        // Fast-path for the two common cases: avoids is_a() autoloading overhead
        // for 99% of migrations that use the standard facade or root-namespace alias.
        if ($class_name === Schema::class || $class_name === 'Schema') {
            return true;
        }

        // Fall back to subclass check for custom Schema facades.
        // is_a() with allow_string=true may trigger autoloading, which is acceptable
        // here — same pattern as resolveForeignIdForColumn() using reflection.
        try {
            return \is_a($class_name, Schema::class, true);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Resolve a table name from a call argument expression.
     * Supports string literals ('users') and class constant fetches (User::TABLE).
     */
    private static function resolveTableName(PhpParser\Node\Expr $expr): ?string
    {
        if ($expr instanceof PhpParser\Node\Scalar\String_) {
            return $expr->value;
        }

        if ($expr instanceof PhpParser\Node\Expr\ClassConstFetch) {
            return self::resolveClassConstantString($expr);
        }

        return null;
    }

    /**
     * Resolve a class constant fetch (e.g. User::TABLE) to its string value.
     * Returns null if the class or constant cannot be resolved or isn't a string.
     *
     * Uses constant() which requires the class to be autoloadable — acceptable here
     * because the Laravel app is booted via Testbench before schema aggregation runs.
     * Same autoloading pattern as resolveForeignIdForColumn() which uses reflection.
     */
    private static function resolveClassConstantString(PhpParser\Node\Expr\ClassConstFetch $node): ?string
    {
        if (!$node->class instanceof PhpParser\Node\Name || !$node->name instanceof PhpParser\Node\Identifier) {
            return null;
        }

        $class_name = $node->class->getAttribute('resolvedName');
        if (!\is_string($class_name) || $class_name === '') {
            return null;
        }

        // constant() may trigger autoloading. Catch Throwable (not just Error)
        // because broken autoloaders can throw RuntimeException or other exceptions.
        try {
            /** @var mixed $value */
            $value = \constant($class_name . '::' . $node->name->name);
        } catch (\Throwable) {
            return null;
        }

        return \is_string($value) ? $value : null;
    }

    private function resolveDefaultValue(PhpParser\Node\Expr $expr): SchemaColumnDefault
    {
        if ($expr instanceof PhpParser\Node\Scalar\String_) {
            return SchemaColumnDefault::resolved($expr->value);
        }

        if ($expr instanceof PhpParser\Node\Scalar\LNumber) {
            return SchemaColumnDefault::resolved($expr->value);
        }

        if ($expr instanceof PhpParser\Node\Scalar\DNumber) {
            return SchemaColumnDefault::resolved($expr->value);
        }

        if ($expr instanceof PhpParser\Node\Expr\ConstFetch) {
            $parts = $expr->name->getParts();

            if ($parts === ['true'] || $parts === ['TRUE']) {
                return SchemaColumnDefault::resolved(true);
            }

            if ($parts === ['false'] || $parts === ['FALSE']) {
                return SchemaColumnDefault::resolved(false);
            }

            if ($parts === ['null'] || $parts === ['NULL']) {
                return SchemaColumnDefault::resolved(null);
            }

            return SchemaColumnDefault::unresolvable();
        }

        if ($expr instanceof PhpParser\Node\Expr\UnaryMinus) {
            if ($expr->expr instanceof PhpParser\Node\Scalar\LNumber) {
                return SchemaColumnDefault::resolved(-$expr->expr->value);
            }

            if ($expr->expr instanceof PhpParser\Node\Scalar\DNumber) {
                return SchemaColumnDefault::resolved(-$expr->expr->value);
            }
        }

        // new Expression('...'), variables, function calls → not statically resolvable
        return SchemaColumnDefault::unresolvable();
    }

    /**
     * Resolve a SchemaColumn for foreignIdFor() when called with a class reference.
     *
     * foreignIdFor(User::class) resolves to 'user_id' (based on model's foreign key convention).
     * The column type is determined by looking up the referenced model's primary key in the
     * already-parsed schema: if the PK is a string (e.g., uuid/ulid), the FK column is also
     * a string; otherwise it defaults to unsigned int.
     */
    private function resolveForeignIdForColumn(
        ?PhpParser\Node\Expr $first_arg,
        ?PhpParser\Node\Expr $second_arg,
        bool $nullable,
        ?SchemaColumnDefault $default,
    ): ?SchemaColumn {
        if (!$first_arg instanceof PhpParser\Node\Expr\ClassConstFetch) {
            return null;
        }

        if (
            !$first_arg->class instanceof PhpParser\Node\Name
            || !$first_arg->name instanceof PhpParser\Node\Identifier
            || $first_arg->name->name !== 'class'
        ) {
            return null;
        }

        $class_name = $first_arg->class->getAttribute('resolvedName');

        if (!\is_string($class_name) || !\is_a($class_name, Model::class, true)) {
            return null;
        }

        try {
            $reflection = new \ReflectionClass($class_name);
            $instance = $reflection->newInstanceWithoutConstructor();
        } catch (\ReflectionException) {
            return null;
        }

        // Resolve column name: custom override from second arg, or model's foreign key convention
        $column_name = $second_arg instanceof PhpParser\Node\Scalar\String_
            ? $second_arg->value
            : $instance->getForeignKey();

        // Resolve type from the referenced model's primary key in the parsed schema.
        // If the PK column is a string (uuid, ulid, etc.), the FK should also be string.
        $referenced_table = $this->tables[$instance->getTable()] ?? null;
        $pk_column = $referenced_table?->columns[$instance->getKeyName()] ?? null;

        if ($pk_column !== null && $pk_column->type === 'string') {
            return new SchemaColumn($column_name, 'string', $nullable, default: $default);
        }

        // Default: unsigned int — either the PK is actually an int, or the referenced
        // table hasn't been parsed yet (migration ordering). We can't safely call
        // getKeyType() because newInstanceWithoutConstructor() skips trait initialization.
        return new SchemaColumn($column_name, 'int', $nullable, default: $default, unsigned: true);
    }
}
