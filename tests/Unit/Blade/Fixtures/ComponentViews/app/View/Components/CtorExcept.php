<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

final class CtorExcept extends Component
{
    public string $heading = '';

    public function __construct()
    {
        // Not redeclared: the constructor alone decides which names `data()` drops.
        $this->except = ['heading'];
    }

    public function render(): View
    {
        return view('components.ctor-except');
    }
}
