<?php
namespace Psalm\LaravelPlugin;

use function spl_autoload_register;

class FakeMetaCommand extends \Barryvdh\LaravelIdeHelper\Console\MetaCommand
{
    /**
     * @return void
     */
    protected function registerClassAutoloadExceptions()
    {
        spl_autoload_register(function (string $class) {
            throw new \ReflectionException("Class '$class' not found.");
        });
    }
}
