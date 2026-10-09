<?php

declare(strict_types=1);

namespace App;

use Illuminate\Support\Facades\View;
use Illuminate\View\Factory;

final class MergeData
{
    /** @param array<string, mixed> $unknown */
    public function render(Factory $factory, array $unknown): void
    {
        view('greeting', [], ['name' => 'valid']);
        View::make('greeting', [], ['name' => 'valid']);
        $factory->make('greeting', [], ['name' => 'valid']);
        View::make(view: 'greeting', mergeData: ['name' => 'valid']);
        $factory->make(view: 'greeting', mergeData: $unknown);
        view('greeting', [], $unknown);
        View::make('greeting', ['name' => 'valid'], ['name' => 1]);
        $factory->make('greeting', [], ['name' => 1])->with('name', 'valid');
    }
}
