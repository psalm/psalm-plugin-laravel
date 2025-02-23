<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Fakes;

use Barryvdh\LaravelIdeHelper\Console\ModelsCommand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaAggregator;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaColumn;

use function config;
use function is_a;
use function in_array;
use function implode;

/** @psalm-suppress PropertyNotSetInConstructor */
final class FakeModelsCommand extends ModelsCommand
{
    /** @var list<class-string<\Illuminate\Database\Eloquent\Model>> */
    private array $model_classes = [];

    private SchemaAggregator $schema;

    /**
     * While the setter of a required property is an anti-pattern,
     * this is the only way to be less independent of changes in the parent ModelsCommand constructor.
     */
    public function setSchemaAggregator(SchemaAggregator $schemaAggregator): void
    {
        $this->schema = $schemaAggregator;
    }

    /** @return list<class-string<\Illuminate\Database\Eloquent\Model>> */
    public function getModels(): array
    {
        if ($this->dirs === []) {
            throw new \LogicException('Directories to scan models are not set.');
        }

        $models = [];

        // Bypass an issue https://github.com/barryvdh/laravel-ide-helper/issues/1414
        /** @var list<class-string> $classlike_fq_names */
        $classlike_fq_names = $this->loadModels();
        foreach ($classlike_fq_names as $probably_model_fqcn) {
            if (is_a($probably_model_fqcn, Model::class, true)) {
                $models[] = $probably_model_fqcn;
            }
        }

        return [...$this->model_classes, ...$models];
    }

    /**
     * Load Model's properties.
     * Overrides {@see \Barryvdh\LaravelIdeHelper\Console\ModelsCommand::getPropertiesFromTable}
     * in order to avoid using DB connection and use SchemaAggregator instead.
     *
     * @param Model $model
     */
    #[\Override]
    public function getPropertiesFromTable($model): void
    {
        $table_name = $model->getTable();

        if (!isset($this->schema->tables[$table_name])) {
            return;
        }

        $this->model_classes[] = $model::class;

        $columns = $this->schema->tables[$table_name]->columns;

        foreach ($columns as $column) {
            $column_name = $column->name;

            if (in_array($column_name, $model->getDates(), true)) {
                $get_type = $set_type = \Illuminate\Support\Carbon::class;
            } else {
                switch ($column->type) {
                    case SchemaColumn::TYPE_STRING:
                    case SchemaColumn::TYPE_INT:
                    case SchemaColumn::TYPE_FLOAT:
                        $get_type = $set_type = $column->type;
                        break;

                    case SchemaColumn::TYPE_BOOL:
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

                    case SchemaColumn::TYPE_ENUM:
                        $get_type = $column->options
                            ? $set_type = "'" . implode("'|'", $column->options) . "'"
                            : $set_type = 'string';

                        break;

                    default:
                        $get_type = $set_type = SchemaColumn::TYPE_MIXED;
                        break;
                }
            }

            if ($column->nullable) {
                $this->nullableColumns[$column_name] = true;
            }

            if ($get_type === $set_type) {
                $this->setProperty($column_name, $get_type, true, true, '', $column->nullable);
            } else {
                $this->setProperty($column_name, $get_type, true, null, '', $column->nullable);
                $this->setProperty($column_name, $set_type, null, true, '', $column->nullable);
            }

            if ($this->write_model_magic_where) {
                $this->setMethod(
                    Str::camel("where_" . $column_name),
                    '\Illuminate\Database\Eloquent\Builder<static>', // @todo support custom EloquentBuilders
                    ['$value']
                );
            }
        }
    }
}
