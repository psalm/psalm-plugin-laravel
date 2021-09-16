<?php

namespace Psalm\LaravelPlugin\Fakes;

use Barryvdh\LaravelIdeHelper\Console\ModelsCommand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaAggregator;
use function config;
use function get_class;
use function implode;
use function in_array;

class FakeModelsCommand210 extends ModelsCommand
{
    use FakeModelsCommandLogic;

    /** @var SchemaAggregator */
    private $schema;

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
    public function getPropertiesFromTable($model) : void
    {
        $this->getProperties($model);
    }
}
