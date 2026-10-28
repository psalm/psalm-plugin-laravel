<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * No `<x-sidebar>` anywhere: its view is rendered some other way, so `data()` is not proven to reach it,
 * and the view's `@props` default is still reset to `mixed`.
 */
final class Sidebar extends Component
{
    public string $heading = 'Sidebar';

    public int $size = 3;

    public function render(): View
    {
        return view('components.sidebar');
    }
}
