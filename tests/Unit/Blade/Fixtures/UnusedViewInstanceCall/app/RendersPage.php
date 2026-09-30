<?php

declare(strict_types=1);

namespace UnusedViewInstanceCallFixture;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Response;

final class RendersPage
{
    public function renderMake(Factory $factory): View
    {
        return $factory->make('used');
    }

    public function renderView(): Response
    {
        return response()->view('viewed');
    }
}
