<?php

declare(strict_types=1);

namespace ComponentViewsFixture;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/** Declines: the view name is not a literal, so no template can be paired with this class. */
final class Panel extends Component
{
    public function __construct(private readonly string $variant = 'plain') {}

    public function render(): View
    {
        return view('components.panel-' . $this->variant, ['title' => 'Panel']);
    }
}
