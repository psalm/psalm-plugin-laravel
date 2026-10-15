<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/** Not final: a `static` return must reach the view as the class itself. */
class ComponentMethods extends Component
{
    public function status(Thing $thing): string
    {
        return $thing::class;
    }

    public function pad(string $value, int $width = 0): string
    {
        return \str_pad($value, $width);
    }

    public function join(string ...$parts): string
    {
        return \implode(',', $parts);
    }

    public function withTone(string $tone): static
    {
        return $this;
    }

    public function label(string $topic): string
    {
        return $topic;
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

    public function render(): View
    {
        return view()->make('component-methods', ['component' => $this]);
    }
}
