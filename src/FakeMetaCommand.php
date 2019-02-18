<?php

namespace Psalm\LaravelPlugin;

class FakeMetaCommand extends \Barryvdh\LaravelIdeHelper\Console\MetaCommand
{
	/**
     * @return void
     */
    protected function registerClassAutoloadExceptions() {
	}
}