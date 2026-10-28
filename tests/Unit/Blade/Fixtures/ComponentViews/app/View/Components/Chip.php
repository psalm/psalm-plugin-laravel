<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\Contracts\View\View;

/** Inherits `$this->view()` from BaseChip, but its own view() override is what that call runs. */
final class Chip extends BaseChip
{
    public string $tone = '';

    #[\Override]
    public function view($view, $data = [], $mergeData = []): View
    {
        return parent::view($view, ['tone' => 1] + $data, $mergeData);
    }
}
