<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/** The default `$except` hides the property, but the constructor replaces it: the view does receive it. */
final class ExceptCtor extends Component
{
    protected $except = ['message'];

    public function __construct(public string $message)
    {
        $this->except = [];
    }

    #[\Override]
    public function render(): View
    {
        return view('components.except-ctor');
    }
}
