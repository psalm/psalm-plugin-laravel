<?php

declare(strict_types=1);

namespace BladeViewDataTaintFixture;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * A class component claims its view, so the prelude types that view's `@props` entries from the
 * component rather than from their literal defaults: the seed replaces those variables' types, and the
 * bridge has to attach its taint node AFTER it.
 *
 * @psalm-suppress UnusedClass
 */
final class Banner extends Component
{
    #[\Override]
    public function render(): View
    {
        return view('components.banner');
    }
}
