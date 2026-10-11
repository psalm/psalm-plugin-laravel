<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

final class Profile extends Component
{
    /** @psalm-suppress MissingPropertyType an untyped property is still view data, just never seeded. */
    public $legacy = 'x';

    public function __construct(public string $name = 'Ann') {}

    public function render(): View
    {
        return view('components.profile', ['extra' => 1]);
    }
}
