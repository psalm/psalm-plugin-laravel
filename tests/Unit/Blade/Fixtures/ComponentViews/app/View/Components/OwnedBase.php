<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/** `self` and `parent` in a property type name the class that declares it (or, for a trait, uses it). */
abstract class OwnedBase extends Component
{
    use OwnedProperties;

    public parent $owner;

    public function render(): View
    {
        return view('components.owned');
    }
}
