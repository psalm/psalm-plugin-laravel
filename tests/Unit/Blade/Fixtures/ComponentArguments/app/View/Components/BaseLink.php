<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\View\Component;

abstract class BaseLink extends Component
{
    /** `self` is the declaring class, not the tag's. */
    public function __construct(public ?self $previous = null) {}
}
