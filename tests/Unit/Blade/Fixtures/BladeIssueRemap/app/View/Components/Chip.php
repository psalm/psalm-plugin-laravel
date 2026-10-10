<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\View\Component;

/**
 * A class component for `<x-chip>`: Laravel resolves the tag to `App\View\Components\Chip` by
 * convention (the fixture's composer.json maps `App\` to `app/`). Its one real method is what a
 * slot reaches through `$component` (#1701).
 */
final class Chip extends Component
{
    public function label(): string
    {
        return 'chip';
    }

    public function render(): string
    {
        return '<span>{{ $slot }}</span>';
    }
}
