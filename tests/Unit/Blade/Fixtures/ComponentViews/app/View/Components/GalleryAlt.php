<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/** A render() outside the recognised shape that still names `components.gallery`. */
final class GalleryAlt extends Component
{
    public function __construct(public bool $wide = false) {}

    public function render(): View
    {
        if ($this->wide) {
            return view('components.gallery', ['caption' => 1]);
        }

        return view('components.gallery');
    }
}
