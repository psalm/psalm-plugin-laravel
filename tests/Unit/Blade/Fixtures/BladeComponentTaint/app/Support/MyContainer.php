<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Container\Container;

/** A container whose make() ignores the parameters it is given. */
final class MyContainer extends Container
{
    #[\Override]
    public function make($abstract, array $parameters = [])
    {
        return parent::make($abstract);
    }
}
