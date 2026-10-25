<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/** A render() outside the recognised shape that names `components.slash-gallery` with a slash. */
final class SlashGalleryAlt extends Component
{
    public function __construct(public bool $wide = false) {}

    public function render(): View
    {
        if ($this->wide) {
            return view('components/slash-gallery', ['caption' => 1]);
        }

        return view('components/slash-gallery');
    }
}
