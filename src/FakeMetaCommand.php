<?php
namespace Psalm\LaravelPlugin;

class FakeMetaCommand extends \Barryvdh\LaravelIdeHelper\Console\MetaCommand
{
    /**
     * @return void
     */
    protected function registerClassAutoloadExceptions() {
        spl_autoload_register(function ($class) {
            throw new \ReflectionException("Class '$class' not found.");
        });
    }
}
