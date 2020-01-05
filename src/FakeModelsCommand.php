<?php
namespace Psalm\LaravelPlugin;

use Composer\Autoload\ClassMapGenerator;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;
use Illuminate\Filesystem\Filesystem;
use ReflectionClass;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Barryvdh\Reflection\DocBlock;
use Barryvdh\Reflection\DocBlock\Context;
use Barryvdh\Reflection\DocBlock\Tag;
use Barryvdh\Reflection\DocBlock\Serializer as DocBlockSerializer;

class FakeModelsCommand extends \Barryvdh\LaravelIdeHelper\Console\ModelsCommand
{
	/** @var SchemaAggregator */
	private $schema;

    /**
     * @param Filesystem $files
     */
    public function __construct(Filesystem $files, SchemaAggregator $schema)
    {
        parent::__construct($files);
        $this->schema = $schema;
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

        $columns = $this->schema->tables[$table_name]->columns;

        foreach ($columns as $column) {
            $name = $column->name;
            
            if (in_array($name, $model->getDates())) {
                $type = '\Illuminate\Support\Carbon';
            } else {
                switch ($column->type) {
                    case 'string':
                    case 'int':
                    case 'float':
                        $type = $column->type;
                        break;

                    case 'bool':
                        switch (config('database.default')) {
                            case 'sqlite':
                            case 'mysql':
                                $type = 'int';
                                break;
                            default:
                                $type = 'bool';
                                break;
                        }

                        break;

                    case 'enum':
                    	if (!$column->options) {
                    		$type = 'string';
                    	} else {
                    		$type = '\'' . implode('\'|\'', $column->options) . '\'';
                    	}

                        break;

                    default:
                        $type = 'mixed';
                        break;
                }
            }

            if ($column->nullable) {
                $this->nullableColumns[$name] = true;
            }

            $this->setProperty($name, $type, true, true, '', $column->nullable);
            if ($this->write_model_magic_where) {
            	$this->setMethod(
                    Str::camel("where_" . $name),
                    '\Illuminate\Database\Eloquent\Builder|\\' . get_class($model),
                    array('$value')
                );
            }
        }
    }

    /**
     * @param \Illuminate\Database\Eloquent\Model $model
     */
    protected function getPropertiesFromMethods($model)
    {
    	// do nothing here
    }
}
