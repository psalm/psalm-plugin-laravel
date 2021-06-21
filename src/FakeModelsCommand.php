<?php
namespace Psalm\LaravelPlugin;

use Composer\Autoload\ClassMapGenerator;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;
use Illuminate\Filesystem\Filesystem;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaAggregator;
use ReflectionClass;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Barryvdh\Reflection\DocBlock;
use Barryvdh\Reflection\DocBlock\Context;
use Barryvdh\Reflection\DocBlock\Tag;
use Barryvdh\Reflection\DocBlock\Serializer as DocBlockSerializer;
use function get_class;
use function in_array;
use function config;
use function implode;

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
