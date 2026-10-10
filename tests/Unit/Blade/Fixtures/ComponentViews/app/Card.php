<?php

declare(strict_types=1);

namespace ComponentViewsFixture;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

final class Card extends Component
{
    public function render(): View
    {
        // PossiblyNullReference on `->format()` belongs to this line, never to the template.
        return view()->make('components.card', ['title' => 'Card', 'width' => 12, 'year' => $this->publishedAt()->format('Y')]);
    }

    private function publishedAt(): ?\DateTimeImmutable
    {
        return null;
    }
}
