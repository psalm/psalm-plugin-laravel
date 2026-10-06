<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Contracts\View\View;

final class DynamicName
{
    public function render(string $template): View
    {
        return view($template, []);
    }
}
