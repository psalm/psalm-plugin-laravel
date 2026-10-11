<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

final class ComponentRender extends Component
{
    public function __construct(
        public readonly string $title,
        public readonly int $count,
    ) {}

    public function render(): View
    {
        // Laravel merges Component::data() — every public property — into the view it renders, so
        // 'title' and 'count' reach the template even though nothing passes them here.
        return view()->make('component-box', ['component' => $this]);
    }
}
