<?php

namespace Psalm\LaravelPlugin\Providers;

use Barryvdh\LaravelIdeHelper\Console\ModelsCommand;
use Composer\InstalledVersions;
use Illuminate\Filesystem\Filesystem;
use Psalm\LaravelPlugin\Fakes\FakeModelsCommand210;
use Psalm\LaravelPlugin\Fakes\FakeModelsCommand291;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaAggregator;
use function \version_compare;
use function \assert;
use function \is_string;

class FakeModelsCommandProvider
{
    public static function getCommand(Filesystem $filesystem, SchemaAggregator $schemaAggregator): ModelsCommand
    {
        $ideHelperVersion = InstalledVersions::getVersion('barryvdh/laravel-ide-helper');

        assert(is_string($ideHelperVersion));

        // ide-helper released a breaking change in a non-major version. As a result, we need to monkey patch our code
        if (version_compare($ideHelperVersion, '2.9.2', '<')) {
            return new FakeModelsCommand291(
                $filesystem,
                $schemaAggregator
            );
        }

        return new FakeModelsCommand210(
            $filesystem,
            $schemaAggregator
        );
    }
}
