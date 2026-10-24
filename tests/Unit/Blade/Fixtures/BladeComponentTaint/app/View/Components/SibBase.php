<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\View\Component;

/** The property is declared here, so both subclasses share one property node. */
abstract class SibBase extends Component
{
    public function __construct(public string $message) {}
}
