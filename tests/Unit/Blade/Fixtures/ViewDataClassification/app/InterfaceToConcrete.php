<?php

declare(strict_types=1);

namespace ViewClassifyFixture;

use Illuminate\Contracts\View\View;

final class InterfaceToConcrete
{
    public function render(Cart $cart): View
    {
        return view('cart', ['cart' => $cart]);
    }
}
