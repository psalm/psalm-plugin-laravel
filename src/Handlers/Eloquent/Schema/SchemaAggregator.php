<?php

namespace Psalm\LaravelPlugin\Handlers\Eloquent\Schema;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PhpParser\NodeFinder;
use PhpParser;

use function array_key_exists;
use function count;
use function is_string;
use function strtolower;
use function in_array;
use function is_array;

class SchemaAggregator
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
        'dropsoftdeletestz' => 'deleted_at',
        'softdeletes' => 'deleted_at',
        'softdeletestz' => 'deleted_at',
        'uuid' => 'uuid',
        'ulid' => 'uuid',
        'ipaddress' => 'ip_address',
        'macaddress' => 'mac_address',
    ];

    /** @var array<string, SchemaTable> */
    public $tables = [];

    /**
     * @param array<int, PhpParser\Node\Stmt> $stmts
     */
    public function addStatements(array $stmts): void
    {
        $nodeFinder = new NodeFinder();

        /** @var PhpParser\Node\Stmt\Class_[] $classes */
        $classes = $nodeFinder->findInstanceOf($stmts, PhpParser\Node\Stmt\Class_::class);

        foreach ($classes as $stmt) {
            $this->addClassStatements($stmt->stmts);
        }
    }

    /**
     * @param array<int, PhpParser\Node\Stmt> $stmts
     */
    private function addClassStatements(array $stmts): void
    {
        foreach ($stmts as $stmt) {
            if (
                $stmt instanceof PhpParser\Node\Stmt\ClassMethod
                && $stmt->name->name === 'up'
                && is_array($stmt->stmts)
            ) {
                $this->addUpMethodStatements($stmt->stmts);
            }
        }
    }

    /**
     * @param array<array-key, \PhpParser\Node\Stmt> $stmts
     */
    private function addUpMethodStatements(array $stmts): void
    {
        foreach ($stmts as $stmt) {
            $is_schema_method_call = $stmt instanceof PhpParser\Node\Stmt\Expression
                && $stmt->expr instanceof PhpParser\Node\Expr\StaticCall
                && $stmt->expr->class instanceof PhpParser\Node\Name
                && $stmt->expr->name instanceof PhpParser\Node\Identifier
                && in_array($stmt->expr->class->getAttribute('resolvedName'), [Schema::class, 'Schema'], true);

            if (! $is_schema_method_call) {
                continue;
            }

            switch ($stmt->expr->name->name) {
                case 'create':
                    $this->alterTable($stmt->expr, true);
                    break;

                case 'table':
                    $this->alterTable($stmt->expr, false);
                    break;

                case 'drop':
                case 'dropIfExists':
                    $this->dropTable($stmt->expr);
                    break;

                case 'rename':
                    $this->renameTable($stmt->expr);
            }
        }
    }

    private function alterTable(PhpParser\Node\Expr\StaticCall $call, bool $creating): void
    {
        if (
            !isset($call->args[0])
            || !$call->args[0] instanceof PhpParser\Node\Arg
            || !$call->args[0]->value instanceof PhpParser\Node\Scalar\String_
        ) {
            return;
        }

        $table_name = $call->args[0]->value->value;

        if ($creating) {
            $this->tables[$table_name] = new SchemaTable();
        }

        if (
            !isset($call->args[1])
            || !$call->args[1] instanceof PhpParser\Node\Arg
            || !$call->args[1]->value instanceof PhpParser\Node\Expr\Closure
            || count($call->args[1]->value->params) < 1
            || ($call->args[1]->value->params[0]->type instanceof PhpParser\Node\Name
                && $call->args[1]->value->params[0]->type->getAttribute('resolvedName')
                !== Blueprint::class)
        ) {
            return;
        }

        $update_closure = $call->args[1]->value;

        if (
            $call->args[1]->value->params[0]->var instanceof PhpParser\Node\Expr\Variable
            && is_string($call->args[1]->value->params[0]->var->name)
        ) {
            $call_arg_name = $call->args[1]->value->params[0]->var->name;

            $this->processColumnUpdates($table_name, $call_arg_name, $update_closure->stmts);
        }
    }

    private function dropTable(PhpParser\Node\Expr\StaticCall $call): void
    {
        if (
            !isset($call->args[0])
            || !$call->args[0] instanceof PhpParser\Node\Arg
            || !$call->args[0]->value instanceof PhpParser\Node\Scalar\String_
        ) {
            return;
        }

        $table_name = $call->args[0]->value->value;

        unset($this->tables[$table_name]);
    }

    private function renameTable(PhpParser\Node\Expr\StaticCall $call): void
    {
        if (
            !isset($call->args[0], $call->args[1])
            || !$call->args[0] instanceof PhpParser\Node\Arg
            || !$call->args[0]->value instanceof PhpParser\Node\Scalar\String_
            || !$call->args[1] instanceof PhpParser\Node\Arg
            || !$call->args[1]->value instanceof PhpParser\Node\Scalar\String_
        ) {
            return;
        }

        $old_table_name = $call->args[0]->value->value;
        $new_table_name = $call->args[1]->value->value;

        if (!isset($this->tables[$old_table_name])) {
            return;
        }

        $table = $this->tables[$old_table_name];

        unset($this->tables[$old_table_name]);

        $this->tables[$new_table_name] = $table;
    }

    private function processColumnUpdates(string $table_name, string $call_arg_name, array $stmts): void
    {
        if (!isset($this->tables[$table_name])) {
            return;
        }

        $table = $this->tables[$table_name];

        foreach ($stmts as $stmt) {
            $is_named_method_call = $stmt instanceof PhpParser\Node\Stmt\Expression
                && $stmt->expr instanceof PhpParser\Node\Expr\MethodCall
                && $stmt->expr->name instanceof PhpParser\Node\Identifier;

            if (!$is_named_method_call) {
                return;
            }

            $root_var = $stmt->expr;

            $first_method_call = $root_var;

            $nullable = false;

            while ($root_var instanceof PhpParser\Node\Expr\MethodCall) {
                if (
                    $root_var->name instanceof PhpParser\Node\Identifier
                    && $root_var->name->name === 'nullable'
                ) {
                    $nullable = true;

                    /**
                     * Possible cases:
                     * ->nullable()
                     * ->nullable(false)
                     * ->nullable(true)
                     *
                     * Process this ->nullable(false)
                     */
                    if (count($root_var->args) > 0) {
                        $first_argument_of_nullable = $root_var->args[0];
                        if (
                            $first_argument_of_nullable instanceof PhpParser\Node\Arg
                            && $first_argument_of_nullable->value instanceof PhpParser\Node\Expr\ConstFetch
                            && $first_argument_of_nullable->value->name->parts === ['false']
                        ) {
                            $nullable = false;
                        }
                    }
                }

                $first_method_call = $root_var;
                $root_var = $root_var->var;
            } // while

            $is_first_method_named_method = $root_var instanceof PhpParser\Node\Expr\Variable
                && $root_var->name === $call_arg_name
                && $first_method_call->name instanceof PhpParser\Node\Identifier;

            if (!$is_first_method_named_method) {
                return;
            }

            $first_arg = $first_method_call->args[0]->value ?? null;
            $second_arg = $first_method_call->args[1]->value ?? null;

            $first_method_name_lc = strtolower($first_method_call->name->name);

            if ($first_method_call->args === []) {
                if (in_array($first_method_name_lc, self::METHODS_USE_HARDCODED_COLUMN_NAME, true)) {
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
                            $table->setColumn(new SchemaColumn('created_at', 'string', true));
                            $table->setColumn(new SchemaColumn('updated_at', 'string', true));
                            break;
                    }

                    continue; // foreach
                }

                if (array_key_exists($first_method_name_lc, self::METHODS_HAVE_DEFAULT_COLUMN_NAME)) {
                    $column_name = self::METHODS_HAVE_DEFAULT_COLUMN_NAME[$first_method_name_lc];
                } else {
                    continue; // unknown type [method call without args] :/
                }
            } elseif ($first_arg instanceof PhpParser\Node\Scalar\String_) {
                $column_name = $first_arg->value;
            } else {
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
                    $table->setColumn(new SchemaColumn($column_name, 'int', $nullable));
                    break;

                /**
                 * @todo use type and column name based on model's PK.
                 * Pairs are [id, int] and [uuid, string]
                 */
                case 'foreignidfor':
                    $table->setColumn(new SchemaColumn('id', 'int', $nullable));
                    break;

                case 'binary':
                case 'foreignulid':
                    $table->setColumn(new SchemaColumn($column_name, 'string', $nullable));
                    break;

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
                    $table->setColumn(new SchemaColumn($column_name, 'string', $nullable));
                    break;

                case 'boolean':
                    $table->setColumn(new SchemaColumn($column_name, 'bool', $nullable));
                    break;

                case 'polygon':
                case 'point':
                case 'multipolygonz':
                case 'multipolygon':
                case 'multipoint':
                case 'geometrycollection':
                case 'geometry':
                case 'computed':
                    $table->setColumn(new SchemaColumn($column_name, 'mixed', $nullable));
                    break;

                case 'double':
                case 'unsigneddecimal':
                case 'float':
                case 'unsignedfloat':
                case 'unsigneddouble':
                case 'decimal':
                    $table->setColumn(new SchemaColumn($column_name, 'float', $nullable));
                    break;

                case 'dropcolumn':
                case 'dropifexists':
                case 'dropsoftdeletes':
                case 'removecolumn':
                case 'dropsoftdeletestz':
                case 'drop':
                    $table->dropColumn($column_name);
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
                    $table->setColumn(new SchemaColumn($column_name, 'enum', $nullable, $second_arg_array));
                    break;

                case 'numericmorphs':
                case 'morphs': // @todo support UUID and ULID types
                    $table->setColumn(new SchemaColumn($column_name . '_type', 'string', $nullable));
                    $table->setColumn(new SchemaColumn($column_name . '_id', 'int', $nullable));
                    break;

                case 'nullablenumericmorphs':
                case 'nullablemorphs': // @todo support UUID and ULID types
                    $table->setColumn(new SchemaColumn($column_name . '_type', 'string', true));
                    $table->setColumn(new SchemaColumn($column_name . '_id', 'int', true));
                    break;

                case 'uuidmorphs':
                case 'ulidmorphs':
                    $table->setColumn(new SchemaColumn($column_name . '_type', 'string', $nullable));
                    $table->setColumn(new SchemaColumn($column_name . '_id', 'string', $nullable));
                    break;

                case 'nullableuuidmorphs':
                case 'nullableUlidMorphs':
                    $table->setColumn(new SchemaColumn($column_name . '_type', 'string', true));
                    $table->setColumn(new SchemaColumn($column_name . '_id', 'string', true));
                    break;

                case 'rename':
                case 'renamecolumn':
                    if ($second_arg instanceof PhpParser\Node\Scalar\String_) {
                        $table->renameColumn($column_name, $second_arg->value);
                    }
                    break;

                case 'set':
                    $table->setColumn(new SchemaColumn($column_name, 'set', $nullable, $second_arg_array));
                    break;

                case 'year':
                case 'timetz':
                case 'timestamptz':
                case 'softdeletestz':
                case 'softdeletes':
                    $table->setColumn(new SchemaColumn($column_name, 'string', true));
                    break;

                case 'addcolumn':
                    if ($second_arg instanceof PhpParser\Node\Scalar\String_) {
                        $_column_type = $column_name;
                        $column_name = $second_arg->value;
                        // @todo extract nullable value from 3rd arg
                        $table->renameColumn($_column_type, $column_name);
                    }
                    break;
            }
        }
    }
}
