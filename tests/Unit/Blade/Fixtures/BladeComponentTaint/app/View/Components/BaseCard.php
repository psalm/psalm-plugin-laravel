<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/** The property is declared here; the subclass's view reads it. */
abstract class BaseCard extends Component
{
    public function __construct(public string $message) {}
}
