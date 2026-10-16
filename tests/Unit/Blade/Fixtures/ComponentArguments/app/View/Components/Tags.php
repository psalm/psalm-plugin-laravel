<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\View\Component;

final class Tags extends Component
{
    /** @var list<string> */
    public array $tags;

    public function __construct(string ...$tags)
    {
        $this->tags = \array_values($tags);
    }

    public function render(): string
    {
        return '<ul></ul>';
    }
}
