<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

final class Snapshot extends Component
{
    public parent $owner;

    public function __construct()
    {
        $this->owner = $this;
    }

    /**
     * Psalm expands `public-properties-of` into an array whose property types keep a raw `parent`/`self`:
     * the method variable's type is not fully expanded when it reaches the seed.
     *
     * @return public-properties-of<self>
     */
    public function snapshot(): array
    {
        return \get_object_vars($this);
    }

    public function render(): View
    {
        return view('components.snapshot');
    }
}
