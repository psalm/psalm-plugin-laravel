<?php

declare(strict_types=1);

namespace App\View\Components;

use App\Support\HasMessage;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

final class TraitD extends Component
{
    use HasMessage;

    public function __construct(string $message)
    {
        $this->message = $message;
    }

    #[\Override]
    public function render(): View
    {
        return view('components.trait-d');
    }
}
