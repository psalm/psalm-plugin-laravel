<?php

namespace Psalm\LaravelPlugin\Fakes;

use Illuminate\Foundation\Application;
use RuntimeException;

trait FakeCreatesApplication
{
    /**
     * @psalm-return never
     */
    public function createApplication()
    {
        throw new RuntimeException("Please install orchestra/testbench to analyze packages.");
    }

    /**
     * Get package bootstrapper.
     *
     * @param  Application  $app
     *
     * @return array
     */
    protected function getPackageBootstrappers($app)
    {
        return [];
    }
}
