<?php

declare(strict_types=1);

namespace AnnotateFixture;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;

/** Not final, so `static` in a return type keeps Psalm's late-static marker at the call site. */
class Panel
{
    /** `&static` where dropping it would change the contract: an invariant template parameter, a callable parameter. */
    public function render(): View
    {
        return view('panel', ['family' => $this->family(), 'handler' => $this->handler()]);
    }

    /**
     * A template parameter that only `get_class()` carries.
     *
     * @template T of object
     *
     * @param Collection<int, T> $items
     */
    public function kind(Collection $items): View
    {
        return view('panel-kind', ['kind' => \get_class($items)]);
    }

    /** @return Collection<int, static> */
    public function family(): Collection
    {
        return new Collection([$this]);
    }

    /** @return \Closure(static): void */
    public function handler(): \Closure
    {
        return static function (self $panel): void {};
    }
}
