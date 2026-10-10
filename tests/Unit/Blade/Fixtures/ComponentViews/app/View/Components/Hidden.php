<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

final class Hidden extends Component
{
    /** @var array<array-key, mixed> */
    protected $except = ['secret'];

    public string $secret = '';

    public function render(): View
    {
        return view('components.hidden');
    }
}
