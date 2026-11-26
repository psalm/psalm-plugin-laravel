<?php

declare(strict_types=1);

namespace ViewClassifyFixture;

use Illuminate\Contracts\View\View;

final class MixedSupplied
{
    public function explicitMixed(mixed $member): View
    {
        return view('member', ['member' => $member]);
    }

    /** `first()` returns `TValue|null`, which reads as `(TValue:Collection as mixed)|null`. */
    public function templateAsMixedOrNull(Plugins $plugins): View
    {
        return view('member', ['member' => $plugins->items->first()]);
    }

    /** A bare `TValue:Collection as mixed`. */
    public function templateAsMixed(Plugins $plugins): View
    {
        return view('member', ['member' => $plugins->items->firstOrFail()]);
    }
}
