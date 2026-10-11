<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/** Named arguments are not read: the whole view declines. */
final class Named extends Component
{
    public string $title = '';

    #[\Override]
    public function render(): View
    {
        return view(view: 'components.named', data: ['other' => 1]);
    }
}
