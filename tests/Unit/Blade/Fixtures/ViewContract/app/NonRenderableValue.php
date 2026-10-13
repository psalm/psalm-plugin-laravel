<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Contracts\View\View;

final class NonRenderableValue
{
    public function unrelatedObject(): View
    {
        return view('greeting', ['name' => new \DateTimeImmutable()]);
    }

    public function renderableUnionedWithAnInt(View|int $value): View
    {
        return view('greeting', ['name' => $value]);
    }
}
