<?php
namespace Psalm\LaravelPlugin;

use PhpParser;

class SchemaAggregator
{
    /** @var array<string, SchemaTable> */
	public $tables = [];

    /**
     * @param array<int, PhpParser\Node\Stmt> $statements
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
     * @param array<int, PhpParser\Node\Stmt> $statements
     */
    private function addClassStatements(array $stmts) : void
    {
        foreach ($stmts as $stmt) {
            if ($stmt instanceof PhpParser\Node\Stmt\ClassMethod
                && $stmt->name->name === 'up'
            ) {
                $this->addUpMethodStatements($stmt->stmts);
            }
        }
    }

    /**
     * @param array<int, PhpParser\Node\Stmt> $statements
     */
    private function addUpMethodStatements(array $stmts) : void
    {
        foreach ($stmts as $stmt) {
            if ($stmt instanceof PhpParser\Node\Stmt\Expression
                && $stmt->expr instanceof PhpParser\Node\Expr\StaticCall
                && $stmt->expr->class instanceof PhpParser\Node\Name
                && $stmt->expr->name instanceof PhpParser\Node\Identifier
                && $stmt->expr->class->getAttribute('resolvedName') === \Illuminate\Support\Facades\Schema::class
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
                    !== \Illuminate\Database\Schema\Blueprint::class)
        ) {
            return;
        }

        $update_closure = $call->args[1]->value;

        $call_arg_name = $call->args[1]->value->params[0]->var->name;

        $this->processColumnUpdates($table_name, $call_arg_name, $update_closure->stmts);
    }

    private function dropTable(PhpParser\Node\Expr\StaticCall $call)
    {
        if (!isset($call->args[0])
            || !$call->args[0]->value instanceof PhpParser\Node\Scalar\String_
        ) {
            return;
        }

        $table_name = $call->args[0]->value->value;

        unset($this->tables[$table_name]);
    }

    private function renameTable(PhpParser\Node\Expr\StaticCall $call)
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
                            $column_name = null;
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
                            if ($array_item->value instanceof PhpParser\Node\Scalar\String_) {
                                $second_arg_array[] = $array_item->value->value;
                            }
                        }
                    }

                    switch ($first_method_call->name->name) {
                        case 'bigIncrements':
                            $table->setColumn(new SchemaColumn($column_name, 'int', $nullable));
                            break;

                        case 'bigInteger':
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

                        case 'dateTime':
                            $table->setColumn(new SchemaColumn($column_name, 'string', $nullable));
                            break;

                        case 'dateTimeTz':
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

                        case 'dropColumn':
                            $table->dropColumn($column_name);
                            break;

                        case 'dropForeign':
                        case 'dropIndex':
                        case 'dropPrimary':
                        case 'dropUnique':
                        case 'dropSpatialIndex':
                            break;

                        case 'dropIfExists':
                            $table->dropColumn($column_name);
                            break;

                        case 'dropMorphs':
                            $table->dropColumn($column_name . '_type');
                            $table->dropColumn($column_name . '_id');
                            break;

                        case 'dropRememberToken':
                            $table->dropColumn('remember_token');
                            break;

                        case 'dropSoftDeletes':
                            $table->dropColumn($column_name);
                            break;

                        case 'dropSoftDeletesTz':
                            $table->dropColumn($column_name);
                            break;

                        case 'dropTimestamps':
                        case 'dropTimestampsTz':
                            $table->dropColumn('created_at');
                            $table->dropColumn('updated_at');
                            break;

                        case 'enum':
                            $table->setColumn(new SchemaColumn($column_name, 'enum', $nullable, $second_arg_array));
                            break;

                        case 'float':
                            $table->setColumn(new SchemaColumn($column_name, 'float', $nullable));
                            break;

                        case 'foreign':
                            break;

                        case 'geometry':
                            $table->setColumn(new SchemaColumn($column_name, 'mixed', $nullable));
                            break;

                        case 'geometryCollection':
                            $table->setColumn(new SchemaColumn($column_name, 'mixed', $nullable));
                            break;

                        case 'increments':
                            break;

                        case 'index':
                            break;

                        case 'integer':
                            $table->setColumn(new SchemaColumn($column_name, 'int', $nullable));
                            break;

                        case 'integerIncrements':
                            $table->setColumn(new SchemaColumn($column_name, 'int', $nullable));
                            break;

                        case 'ipAddress':
                            $table->setColumn(new SchemaColumn($column_name, 'string', $nullable));
                            break;

                        case 'json':
                            $table->setColumn(new SchemaColumn($column_name, 'string', $nullable));
                            break;

                        case 'jsonb':
                            $table->setColumn(new SchemaColumn($column_name, 'string', $nullable));
                            break;

                        case 'lineString':
                            $table->setColumn(new SchemaColumn($column_name, 'string', $nullable));
                            break;

                        case 'longText':
                            $table->setColumn(new SchemaColumn($column_name, 'string', $nullable));
                            break;

                        case 'macAddress':
                            $table->setColumn(new SchemaColumn($column_name, 'string', $nullable));
                            break;

                        case 'mediumIncrements':
                            $table->setColumn(new SchemaColumn($column_name, 'int', $nullable));
                            break;

                        case 'mediumInteger':
                            $table->setColumn(new SchemaColumn($column_name, 'int', $nullable));
                            break;

                        case 'mediumText':
                            $table->setColumn(new SchemaColumn($column_name, 'string', $nullable));
                            break;

                        case 'morphs':
                            $table->setColumn(new SchemaColumn($column_name . '_type', 'string', $nullable));
                            $table->setColumn(new SchemaColumn($column_name . '_id', 'int', $nullable));
                            break;

                        case 'multiLineString':
                            $table->setColumn(new SchemaColumn($column_name, 'string', $nullable));
                            break;

                        case 'multiPoint':
                            $table->setColumn(new SchemaColumn($column_name, 'mixed', $nullable));
                            break;

                        case 'multiPolygon':
                            $table->setColumn(new SchemaColumn($column_name, 'mixed', $nullable));
                            break;

                        case 'multiPolygonZ':
                            $table->setColumn(new SchemaColumn($column_name, 'mixed', $nullable));
                            break;

                        case 'nullableMorphs':
                            $table->setColumn(new SchemaColumn($column_name . '_type', 'string', true));
                            $table->setColumn(new SchemaColumn($column_name . '_id', 'int', true));
                            break;

                        case 'nullableTimestamps':
                            $table->setColumn(new SchemaColumn('created_at', 'string', true));
                            $table->setColumn(new SchemaColumn('updated_at', 'string', true));
                            break;

                        case 'nullableUuidMorphs':
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

                        case 'rememberToken':
                            $table->setColumn(new SchemaColumn('remember_token', 'string', $nullable));
                            break;

                        case 'removeColumn':
                            $table->dropColumn($column_name);
                            break;

                        case 'rename':
                            if ($second_arg instanceof PhpParser\Node\Scalar\String_) {
                                $table->renameColumn($column_name, $second_arg->value);
                            }
                            break;

                        case 'renameColumn':
                            break;

                        case 'renameIndex':
                            break;

                        case 'set':
                            $table->setColumn(new SchemaColumn($column_name, 'set', $nullable, $second_arg_array));
                            break;

                        case 'smallIncrements':
                            $table->setColumn(new SchemaColumn($column_name, 'int', $nullable));
                            break;

                        case 'smallInteger':
                            $table->setColumn(new SchemaColumn($column_name, 'int', $nullable));
                            break;

                        case 'softDeletes':
                            $table->setColumn(new SchemaColumn($column_name, 'string', true));
                            break;

                        case 'softDeletesTz':
                            $table->setColumn(new SchemaColumn($column_name, 'string', true));
                            break;

                        case 'spatialIndex':
                            break;

                        case 'string':
                            $table->setColumn(new SchemaColumn($column_name, 'string', $nullable));
                            break;

                        case 'text':
                            $table->setColumn(new SchemaColumn($column_name, 'string', $nullable));
                            break;

                        case 'time':
                            $table->setColumn(new SchemaColumn($column_name, 'string', $nullable));
                            break;

                        case 'timestamp':
                            $table->setColumn(new SchemaColumn($column_name, 'string', $nullable));
                            break;

                        case 'timestamps':
                            $table->setColumn(new SchemaColumn('created_at', 'string', true));
                            $table->setColumn(new SchemaColumn('updated_at', 'string', true));
                            break;

                        case 'timestampsTz':
                            $table->setColumn(new SchemaColumn('created_at', 'string', true));
                            $table->setColumn(new SchemaColumn('updated_at', 'string', true));
                            break;

                        case 'timestampTz':
                            $table->setColumn(new SchemaColumn($column_name, 'string', true));
                            break;

                        case 'timeTz':
                            $table->setColumn(new SchemaColumn($column_name, 'string', true));
                            break;

                        case 'tinyIncrements':
                            $table->setColumn(new SchemaColumn($column_name, 'int', $nullable));
                            break;

                        case 'tinyInteger':
                            $table->setColumn(new SchemaColumn($column_name, 'int', $nullable));
                            break;

                        case 'unique':
                            break;

                        case 'unsignedBigInteger':
                            $table->setColumn(new SchemaColumn($column_name, 'int', $nullable));
                            break;

                        case 'unsignedDecimal':
                            $table->setColumn(new SchemaColumn($column_name, 'float', $nullable));
                            break;

                        case 'unsignedInteger':
                            $table->setColumn(new SchemaColumn($column_name, 'int', $nullable));
                            break;

                        case 'unsignedMediumInteger':
                            $table->setColumn(new SchemaColumn($column_name, 'int', $nullable));
                            break;

                        case 'unsignedSmallInteger':
                            $table->setColumn(new SchemaColumn($column_name, 'int', $nullable));
                            break;

                        case 'unsignedTinyInteger':
                            $table->setColumn(new SchemaColumn($column_name, 'int', $nullable));
                            break;

                        case 'uuid':
                            $table->setColumn(new SchemaColumn($column_name, 'string', $nullable));
                            break;

                        case 'uuidMorphs':
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
