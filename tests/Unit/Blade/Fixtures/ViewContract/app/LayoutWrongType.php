<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Contracts\View\View;

final class LayoutWrongType
{
    public function render(): View
    {
        return view('extends.profile', ['siteName' => 1]);
    }
}
