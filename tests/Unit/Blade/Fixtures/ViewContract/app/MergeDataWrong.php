<?php

declare(strict_types=1);

namespace App;

use Illuminate\Support\Facades\View;
use Illuminate\View\Factory;

final class MergeDataWrong
{
    public function render(Factory $factory): void
    {
        view('greeting', ['name' => 1], ['name' => 'valid']);
        View::make('greeting', ['name' => 1], ['name' => 'valid']);
        $factory->make('greeting', ['name' => 1], ['name' => 'valid']);
    }
}
