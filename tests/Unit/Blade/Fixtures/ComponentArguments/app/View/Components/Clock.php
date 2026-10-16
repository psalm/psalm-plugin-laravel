<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\Contracts\Config\Repository;
use Illuminate\View\Component;

/** `$config` is never passed by a tag: the container builds it. */
final class Clock extends Component
{
    public function __construct(public Repository $config) {}

    public function render(): string
    {
        return '<time></time>';
    }
}
