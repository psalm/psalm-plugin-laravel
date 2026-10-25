<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/** Non-literal render() data: the public properties still reach the view. */
final class Extra extends Component
{
    /** @param array<string, string> $extra */
    public function __construct(public string $message, public array $extra = []) {}

    #[\Override]
    public function render(): View
    {
        return view('components.extra', $this->extra);
    }
}
