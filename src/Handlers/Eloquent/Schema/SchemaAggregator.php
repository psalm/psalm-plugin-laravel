<?php

namespace Psalm\LaravelPlugin\Handlers\Eloquent\Schema;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PhpParser;
use function count;
use function is_string;
use function strtolower;

class SchemaAggregator
{
    /** @var array<string, SchemaTable> */
    public $tables = [];

    /**
     * @param array<int, PhpParser\Node\Stmt> $stmts
     */
    public function addStatements(array $stmts) : void
    {
        foreach ($stmts as $stmt) {
            if ($stmt instanceof PhpParser\Node\Stmt\Class_) {
                $this->addClassStatements($stmt->stmts);
            }
        }
    }

    /**
     * @param array<int, PhpParser\Node\Stmt> $stmts
     */
    private function addClassStatements(array $stmts) : void
    {
        foreach ($stmts as $stmt) {
            if ($stmt instanceof PhpParser\Node\Stmt\ClassMethod
                && $stmt->name->name === 'up'
                && $stmt->stmts
            ) {
                $this->addUpMethodStatements($stmt->stmts);
            }
        }
    }

    /**
     * @param array<int, PhpParser\Node\Stmt> $stmts
     */
    private function addUpMethodStatements(array $stmts) : void
    {
        foreach ($stmts as $stmt) {
            if ($stmt instanceof PhpParser\Node\Stmt\Expression
                && $stmt->expr instanceof PhpParser\Node\Expr\StaticCall
                && $stmt->expr->class instanceof PhpParser\Node\Name
                && $stmt->expr->name instanceof PhpParser\Node\Identifier
                && $stmt->expr->class->getAttribute('resolvedName') === Schema::class
            ) {
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
    }

    private function alterTable(PhpParser\Node\Expr\StaticCall $call, bool $creating) : void
    {
        if (!isset($call->args[0])
            || !$call->args[0]->value instanceof PhpParser\Node\Scalar\String_
        ) {
            return;
        }

        $table_name = $call->args[0]->value->value;

        if ($creating) {
            $this->tables[$table_name] = new SchemaTable($table_name);
        }

        if (!isset($call->args[1])
            || !$call->args[1]->value instanceof PhpParser\Node\Expr\Closure
            || count($call->args[1]->value->params) < 1
            || ($call->args[1]->value->params[0]->type instanceof PhpParser\Node\Name
                && $call->args[1]->value->params[0]->type->getAttribute('resolvedName')
                    !== Blueprint::class)
        ) {
            return;
        }

        $update_closure = $call->args[1]->value;

        if ($call->args[1]->value->params[0]->var instanceof PhpParser\Node\Expr\Variable
            && is_string($call->args[1]->value->params[0]->var->name)
        ) {
            $call_arg_name = $call->args[1]->value->params[0]->var->name;

            $this->processColumnUpdates($table_name, $call_arg_name, $update_closure->stmts);
        }
    }

    private function dropTable(PhpParser\Node\Expr\StaticCall $call) : void
    {
        if (!isset($call->args[0])
            || !$call->args[0]->value instanceof PhpParser\Node\Scalar\String_
        ) {
            return;
        }

        $table_name = $call->args[0]->value->value;

        unset($this->tables[$table_name]);
    }

    private function renameTable(PhpParser\Node\Expr\StaticCall $call) : void
    {
        if (!isset($call->args[0])
            || !$call->args[0]->value instanceof PhpParser\Node\Scalar\String_
            || !isset($call->args[1])
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

        $table->name = $new_table_name;

        $this->tables[$new_table_name] = $table;
    }

    private function processColumnUpdates(string $table_name, string $call_arg_name, array $stmts) : void
    {
        if (!isset($this->tables[$table_name])) {
            return;
        }

        $table = $this->tables[$table_name];

        foreach ($stmts as $stmt) {
            if ($stmt instanceof PhpParser\Node\Stmt\Expression
                && $stmt->expr instanceof PhpParser\Node\Expr\MethodCall
                && $stmt->expr->name instanceof PhpParser\Node\Identifier
            ) {
                $root_var = $stmt->expr;

                $first_method_call = $root_var;

                $additional_method_calls = [];

                $nullable = false;

                while ($root_var instanceof PhpParser\Node\Expr\MethodCall) {
                    if ($root_var->name instanceof PhpParser\Node\Identifier
                        && $root_var->name->name === 'nullable'
                    ) {
                        $nullable = true;
                    }

                    $first_method_call = $root_var;
                    $root_var = $root_var->var;
                }

                if ($root_var instanceof PhpParser\Node\Expr\Variable
                    && $root_var->name === $call_arg_name
                    && $first_method_call->name instanceof PhpParser\Node\Identifier
                ) {
                    $first_arg = $first_method_call->args[0]->value ?? null;
                    $second_arg = $first_method_call->args[1]->value ?? null;

                    if (!$first_arg instanceof PhpParser\Node\Scalar\String_) {
                        if ($first_method_call->name->name === 'timestamps'
                            || $first_method_call->name->name === 'timestampsTz'
                            || $first_method_call->name->name === 'nullableTimestamps'
                            || $first_method_call->name->name === 'nullableTimestampsTz'
                            || $first_method_call->name->name === 'rememberToken'
                        ) {
                            switch (strtolower($first_method_call->name->name)) {
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

                                case 'nullabletimestamps':
                                    $table->setColumn(new SchemaColumn('created_at', 'string', true));
                                    $table->setColumn(new SchemaColumn('updated_at', 'string', true));
                                    break;

                                case 'timestamps':
                                    $table->setColumn(new SchemaColumn('created_at', 'string', true));
                                    $table->setColumn(new SchemaColumn('updated_at', 'string', true));
                                    break;

                                case 'timestampstz':
                                    $table->setColumn(new SchemaColumn('created_at', 'string', true));
                                    $table->setColumn(new SchemaColumn('updated_at', 'string', true));
                                    break;
                            }

                            continue;
                        } elseif ($first_method_call->name->name === 'softDeletes'
                            || $first_method_call->name->name === 'softDeletesTz'
                            || $first_method_call->name->name === 'dropSoftDeletes'
                            || $first_method_call->name->name === 'dropSoftDeletesTz'
                        ) {
                            $column_name = 'deleted_at';
                        } else {
                            continue;
                        }
                    } else {
                        $column_name = $first_arg->value;
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

                    switch (strtolower($first_method_call->name->name)) {
                        case 'bigincrements':
                            $table->setColumn(new SchemaColumn($column_name, 'int', $nullable));
                            break;

                        case 'biginteger':
                            $table->setColumn(new SchemaColumn($column_name, 'int', $nullable));
                            break;

                        case 'binary':
                            $table->setColumn(new SchemaColumn($column_name, 'string', $nullable));
                            break;

                        case 'boolean':
                            $table->setColumn(new SchemaColumn($column_name, 'bool', $nullable));
                            break;

                        case 'char':
                            $table->setColumn(new SchemaColumn($column_name, 'string', $nullable));
                            break;

                        case 'computed':
                            $table->setColumn(new SchemaColumn($column_name, 'mixed', $nullable));
                            break;

                        case 'date':
                            $table->setColumn(new SchemaColumn($column_name, 'string', $nullable));
                            break;

                        case 'datetime':
                            $table->setColumn(new SchemaColumn($column_name, 'string', $nullable));
                            break;

                        case 'datetimetz':
                            $table->setColumn(new SchemaColumn($column_name, 'string', $nullable));
                            break;

                        case 'decimal':
                            $table->setColumn(new SchemaColumn($column_name, 'float', $nullable));
                            break;

                        case 'double':
                            $table->setColumn(new SchemaColumn($column_name, 'float', $nullable));
                            break;

                        case 'drop':
                            $table->dropColumn($column_name);
                            break;

                        case 'dropcolumn':
                            $table->dropColumn($column_name);
                            break;

                        case 'dropforeign':
                        case 'dropindex':
                        case 'dropprimary':
                        case 'dropunique':
                        case 'dropspatialindex':
                            break;

                        case 'dropifexists':
                            $table->dropColumn($column_name);
                            break;

                        case 'dropmorphs':
                            $table->dropColumn($column_name . '_type');
                            $table->dropColumn($column_name . '_id');
                            break;

                        case 'dropsoftdeletes':
                            $table->dropColumn($column_name);
                            break;

                        case 'dropsoftdeletestz':
                            $table->dropColumn($column_name);
                            break;



                        case 'enum':
                            $table->setColumn(new SchemaColumn($column_name, 'enum', $nullable, $second_arg_array));
                            break;

                        case 'float':
                            $table->setColumn(new SchemaColumn($column_name, 'float', $nullable));
                            break;

                        case 'foreign':
                            break;

                        case 'foreignid':
                            $table->setColumn(new SchemaColumn($column_name, 'int', $nullable));
                            break;

                        case 'foreignuuid':
                            $table->setColumn(new SchemaColumn($column_name, 'string', $nullable));
                            break;

                        case 'geometry':
                            $table->setColumn(new SchemaColumn($column_name, 'mixed', $nullable));
                            break;

                        case 'geometrycollection':
                            $table->setColumn(new SchemaColumn($column_name, 'mixed', $nullable));
                            break;

                        case 'id':
                            $table->setColumn(new SchemaColumn('id', 'int', $nullable));
                            break;

                        case 'increments':
                            $table->setColumn(new SchemaColumn($column_name, 'int', $nullable));
                            break;

                        case 'index':
                            break;

                        case 'integer':
                            $table->setColumn(new SchemaColumn($column_name, 'int', $nullable));
                            break;

                        case 'integerincrements':
                            $table->setColumn(new SchemaColumn($column_name, 'int', $nullable));
                            break;

                        case 'ipaddress':
                            $table->setColumn(new SchemaColumn($column_name, 'string', $nullable));
                            break;

                        case 'json':
                            $table->setColumn(new SchemaColumn($column_name, 'string', $nullable));
                            break;

                        case 'jsonb':
                            $table->setColumn(new SchemaColumn($column_name, 'string', $nullable));
                            break;

                        case 'linestring':
                            $table->setColumn(new SchemaColumn($column_name, 'string', $nullable));
                            break;

                        case 'longtext':
                            $table->setColumn(new SchemaColumn($column_name, 'string', $nullable));
                            break;

                        case 'macaddress':
                            $table->setColumn(new SchemaColumn($column_name, 'string', $nullable));
                            break;

                        case 'mediumincrements':
                            $table->setColumn(new SchemaColumn($column_name, 'int', $nullable));
                            break;

                        case 'mediuminteger':
                            $table->setColumn(new SchemaColumn($column_name, 'int', $nullable));
                            break;

                        case 'mediumtext':
                            $table->setColumn(new SchemaColumn($column_name, 'string', $nullable));
                            break;

                        case 'morphs':
                            $table->setColumn(new SchemaColumn($column_name . '_type', 'string', $nullable));
                            $table->setColumn(new SchemaColumn($column_name . '_id', 'int', $nullable));
                            break;

                        case 'multilinestring':
                            $table->setColumn(new SchemaColumn($column_name, 'string', $nullable));
                            break;

                        case 'multipoint':
                            $table->setColumn(new SchemaColumn($column_name, 'mixed', $nullable));
                            break;

                        case 'multipolygon':
                            $table->setColumn(new SchemaColumn($column_name, 'mixed', $nullable));
                            break;

                        case 'multipolygonz':
                            $table->setColumn(new SchemaColumn($column_name, 'mixed', $nullable));
                            break;

                        case 'numericmorphs':
                            $table->setColumn(new SchemaColumn($column_name . '_type', 'string', $nullable));
                            $table->setColumn(new SchemaColumn($column_name . '_id', 'int', $nullable));
                            break;

                        case 'nullablemorphs':
                            $table->setColumn(new SchemaColumn($column_name . '_type', 'string', true));
                            $table->setColumn(new SchemaColumn($column_name . '_id', 'int', true));
                            break;

                        case 'nullablenumericmorphs':
                            $table->setColumn(new SchemaColumn($column_name . '_type', 'string', true));
                            $table->setColumn(new SchemaColumn($column_name . '_id', 'int', true));
                            break;

                        case 'nullableuuidmorphs':
                            $table->setColumn(new SchemaColumn($column_name . '_type', 'string', true));
                            $table->setColumn(new SchemaColumn($column_name . '_id', 'string', true));
                            break;

                        case 'point':
                            $table->setColumn(new SchemaColumn($column_name, 'mixed', $nullable));
                            break;

                        case 'polygon':
                            $table->setColumn(new SchemaColumn($column_name, 'mixed', $nullable));
                            break;

                        case 'primary':
                            break;

                        case 'removecolumn':
                            $table->dropColumn($column_name);
                            break;

                        case 'rename':
                        case 'renamecolumn':
                            if ($second_arg instanceof PhpParser\Node\Scalar\String_) {
                                $table->renameColumn($column_name, $second_arg->value);
                            }
                            break;

                        case 'renameindex':
                            break;

                        case 'set':
                            $table->setColumn(new SchemaColumn($column_name, 'set', $nullable, $second_arg_array));
                            break;

                        case 'smallincrements':
                            $table->setColumn(new SchemaColumn($column_name, 'int', $nullable));
                            break;

                        case 'smallinteger':
                            $table->setColumn(new SchemaColumn($column_name, 'int', $nullable));
                            break;

                        case 'softdeletes':
                            $table->setColumn(new SchemaColumn($column_name, 'string', true));
                            break;

                        case 'softdeletestz':
                            $table->setColumn(new SchemaColumn($column_name, 'string', true));
                            break;

                        case 'spatialindex':
                            break;

                        case 'string':
                            $table->setColumn(new SchemaColumn($column_name, 'string', $nullable));
                            break;

                        case 'text':
                            $table->setColumn(new SchemaColumn($column_name, 'string', $nullable));
                            break;

                        case 'tinytext':
                            $table->setColumn(new SchemaColumn($column_name, 'string', $nullable));
                            break;

                        case 'time':
                            $table->setColumn(new SchemaColumn($column_name, 'string', $nullable));
                            break;

                        case 'timestamp':
                            $table->setColumn(new SchemaColumn($column_name, 'string', $nullable));
                            break;

                        case 'timestamptz':
                            $table->setColumn(new SchemaColumn($column_name, 'string', true));
                            break;

                        case 'timetz':
                            $table->setColumn(new SchemaColumn($column_name, 'string', true));
                            break;

                        case 'tinyincrements':
                            $table->setColumn(new SchemaColumn($column_name, 'int', $nullable));
                            break;

                        case 'tinyinteger':
                            $table->setColumn(new SchemaColumn($column_name, 'int', $nullable));
                            break;

                        case 'unique':
                            break;

                        case 'unsignedbiginteger':
                            $table->setColumn(new SchemaColumn($column_name, 'int', $nullable));
                            break;

                        case 'unsigneddecimal':
                            $table->setColumn(new SchemaColumn($column_name, 'float', $nullable));
                            break;

                        case 'unsignedinteger':
                            $table->setColumn(new SchemaColumn($column_name, 'int', $nullable));
                            break;

                        case 'unsignedmediuminteger':
                            $table->setColumn(new SchemaColumn($column_name, 'int', $nullable));
                            break;

                        case 'unsignedsmallinteger':
                            $table->setColumn(new SchemaColumn($column_name, 'int', $nullable));
                            break;

                        case 'unsignedtinyinteger':
                            $table->setColumn(new SchemaColumn($column_name, 'int', $nullable));
                            break;

                        case 'uuid':
                            $table->setColumn(new SchemaColumn($column_name, 'string', $nullable));
                            break;

                        case 'uuidmorphs':
                            $table->setColumn(new SchemaColumn($column_name . '_type', 'string', $nullable));
                            $table->setColumn(new SchemaColumn($column_name . '_id', 'string', $nullable));
                            break;

                        case 'year':
                            $table->setColumn(new SchemaColumn($column_name, 'string', true));
                            break;
                    }
                }
            }
        }
    }
}
