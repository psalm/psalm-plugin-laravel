<?php

declare(strict_types=1);

namespace App\View\Components;

trait OwnedProperties
{
    /** @var list<self> */
    public array $siblings = [];
}
