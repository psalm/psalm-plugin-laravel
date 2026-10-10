<?php

declare(strict_types=1);

namespace ComponentViewsFixture;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

final class Alert extends Component
{
    /** Public, so `Component::data()` supplies it on a `<x-alert>` render and render()'s type must not win. */
    public string $type = 'info';

    public function __construct(protected readonly string $title = 'Heads up') {}

    /** Public, so `data()` exposes it as an invokable variable under the same name. */
    public function isActive(): bool
    {
        return true;
    }

    public function render(): View
    {
        return view('components.alert', [
            'title' => $this->title,
            'count' => $this->count(),
            'kind' => self::KIND,
            'type' => $this->type,
            'isActive' => $this->isActive(),
            'q' => request()->input('q'),
        ]);
    }

    protected function count(): int
    {
        return 3;
    }

    private const KIND = 'alert';
}
