<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\View;
use Illuminate\View\InvokableComponentVariable;

final class InvokableIntersection
{
    public function render(\Closure $untyped): ?View
    {
        $value = new InvokableComponentVariable($untyped);

        if ($value instanceof Htmlable) {
            return view('invokable-htmlable', ['value' => $value]);
        }

        return null;
    }
}
