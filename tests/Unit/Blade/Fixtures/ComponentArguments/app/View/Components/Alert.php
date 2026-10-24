<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\View\Component;

final class Alert extends Component
{
    /** `$icon` has no default, but the container resolves a nullable parameter to null. */
    public function __construct(public int $count, public ?string $icon, public string $type = 'info') {}

    public function render(): string
    {
        return '<div></div>';
    }
}
