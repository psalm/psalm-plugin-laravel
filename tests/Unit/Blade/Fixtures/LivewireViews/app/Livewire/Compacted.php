<?php

declare(strict_types=1);

namespace App\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Component;

final class Compacted extends Component
{
    public string $title = '';

    public function render(): View
    {
        return view('livewire.compacted', $this->data())->with('title', 1);
    }

    /** @return array<string, mixed> */
    private function data(): array
    {
        return ['rows' => [1, 2]];
    }
}
