<?php

namespace Psalm\LaravelPlugin\Fakes;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaAggregator;
use function config;
use function get_class;
use function implode;
use function in_array;

class FakeModelsCommand extends \Barryvdh\LaravelIdeHelper\Console\ModelsCommand
{
    /** @var SchemaAggregator */
    private $schema;

    /** @var array<class-string> */
    private $model_classes = [];

    /**
     * @param Filesystem $files
     */
    public function __construct(Filesystem $files, SchemaAggregator $schema)
    {
        parent::__construct($files);
        $this->schema = $schema;
    }

    /** @return array<class-string> */
    public function getModels()
    {
        return $this->model_classes + $this->loadModels();
    }

    /**
     * Load the properties from the database table.
     *
     * @param \Illuminate\Database\Eloquent\Model $model
     */
    protected function getPropertiesFromTable($model) : void
    {
        $table_name = $model->getTable();

        if (!isset($this->schema->tables[$table_name])) {
            return;
        }

        $this->model_classes[] = get_class($model);

        $columns = $this->schema->tables[$table_name]->columns;

        foreach ($columns as $column) {
            $name = $column->name;

            if (in_array($name, $model->getDates())) {
                $get_type = $set_type = '\Illuminate\Support\Carbon';
            } else {
                switch ($column->type) {
                    case 'string':
                    case 'int':
                    case 'float':
                        $get_type = $set_type = $column->type;
                        break;

                    case 'bool':
                        switch (config('database.default')) {
                            case 'sqlite':
                            case 'mysql':
                                $set_type = '0|1|bool';
                                $get_type = '0|1';
                                break;
                            default:
                                $get_type = $set_type = 'bool';
                                break;
                        }

                        break;

                    case 'enum':
                        if (!$column->options) {
                            $get_type = $set_type = 'string';
                        } else {
                            $get_type = $set_type = '\'' . implode('\'|\'', $column->options) . '\'';
                        }

                        break;

                    default:
                        $get_type = $set_type = 'mixed';
                        break;
                }
            }

            if ($column->nullable) {
                $this->nullableColumns[$name] = true;
            }

            if ($get_type === $set_type) {
                $this->setProperty($name, $get_type, true, true, '', $column->nullable);
            } else {
                $this->setProperty($name, $get_type, true, null, '', $column->nullable);
                $this->setProperty($name, $set_type, null, true, '', $column->nullable);
            }

            if ($this->write_model_magic_where) {
                $this->setMethod(
                    Str::camel("where_" . $name),
                    '\Illuminate\Database\Eloquent\Builder|\\' . get_class($model),
                    array('$value')
                );
            }
        }
    }
}
