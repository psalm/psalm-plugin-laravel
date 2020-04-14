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
        // by default, the ide-helper throws exceptions when it cannot find a class. However it does not unregister that
        // autoloader when it is done, and we certainly do not want to throw exceptions when we are simply checking if 
        // a certain class exists. We are instead changing this to be a noop.
    }
}
