<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\View\Component;

final class Tone extends Component
{
    /** @param 'info'|'error' $tone a docblock narrower than the native type is checked too */
    public function __construct(public string $tone) {}

    public function render(): string
    {
        return '<div></div>';
    }
}
