<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Contracts\View\View;

final class ComponentInvokable extends InvokableBase
{
    use InvokableSelf;

    /** @return array{int, string} */
    public function columns(): array
    {
        return [1, 'a'];
    }

    public function label(): string
    {
        return 'label';
    }

    /** @psalm-suppress MissingReturnType */
    public function untyped()
    {
        return 1;
    }

    /** @psalm-suppress MissingReturnType */
    public function untypedAsString()
    {
        return 1;
    }

    /** @psalm-suppress MissingReturnType */
    public function untypedAsClosure()
    {
        return 1;
    }

    public function clear(): void {}

    /**
     * @template T
     *
     * @return list<T>
     */
    public function items(): array
    {
        return [];
    }

    public function plain(): string
    {
        return 'plain';
    }

    public function render(): View
    {
        return view('component-invokable', ['component' => $this]);
    }
}
