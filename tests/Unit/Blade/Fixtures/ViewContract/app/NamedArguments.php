<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Contracts\View\View;

final class NamedArguments
{
    public function onTheBinder(): View
    {
        return view(view: 'greeting', data: ['name' => 123]);
    }

    public function onTheChain(): View
    {
        return view('greeting')->with(key: 'name', value: 123);
    }
}
