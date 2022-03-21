<?php

namespace Psalm\LaravelPlugin\Fakes;

use Barryvdh\LaravelIdeHelper\Console\ModelsCommand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\Filesystem;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaAggregator;

class FakeModelsCommand291 extends ModelsCommand
{
    use FakeModelsCommandLogic;

    /** @var SchemaAggregator */
    private $schema;

    /** @var array<class-string> */
    private $model_classes = [];

    public function __construct(Filesystem $files, SchemaAggregator $schema)
    {
        parent::__construct($files);
        $this->schema = $schema;
    }

    /**
     * Load the properties from the database table.
     *
     * @param Model $model
     */
    protected function getPropertiesFromTable($model): void
    {
        $this->getProperties($model);
    }
}
