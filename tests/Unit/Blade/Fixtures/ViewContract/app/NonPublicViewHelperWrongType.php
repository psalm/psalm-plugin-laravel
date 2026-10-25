<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Contracts\View\View;

final class NonPublicViewHelperWrongType
{
    public function show(): View
    {
        return $this->helper();
    }

    private function helper(): View
    {
        return view('greeting', ['name' => 123]);
    }
}
