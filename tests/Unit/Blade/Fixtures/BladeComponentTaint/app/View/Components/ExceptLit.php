<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/** A literal $except hides the property from the view. */
final class ExceptLit extends Component
{
    protected $except = ['message'];

    public function __construct(public string $message) {}

    #[\Override]
    public function render(): View
    {
        return view('components.except-lit');
    }
}
