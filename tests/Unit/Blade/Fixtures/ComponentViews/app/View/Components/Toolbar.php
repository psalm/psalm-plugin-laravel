<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/** Not final: a `static` return must reach the view as the class, not `Toolbar&static`. */
class Toolbar extends Component
{
    use ToolbarHelpers;

    public function fresh(): static
    {
        return $this;
    }

    public function withTone(string $tone): static
    {
        return $this;
    }

    public function pad(string $value, int ...$widths): string
    {
        return $value;
    }

    /**
     * @template T
     * @param T $value
     * @return T
     */
    public function echo(mixed $value): mixed
    {
        return $value;
    }

    /** @param-out self $target */
    public function outSelf(?self &$target): void
    {
        $target = $this;
    }

    public function nativeSelf(self $target): string
    {
        return $target::class;
    }

    public function render(): View
    {
        return view('components.toolbar');
    }
}
