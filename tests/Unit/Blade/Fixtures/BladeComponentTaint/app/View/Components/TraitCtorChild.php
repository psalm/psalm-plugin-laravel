<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\Contracts\View\View;

/**
 * Psalm 7.14+ reports the constructor of the parent's trait as a static call on the trait: its synthetic
 * constructor call for uninitialised properties (inherited from Component) names the trait. A Psalm bug
 * introduced by vimeo/psalm#14413 and reproducible without the plugin, not yet reported upstream: mark
 * this suppression with the issue link once it exists.
 *
 * @psalm-suppress InvalidStaticInvocation
 */
final class TraitCtorChild extends TraitCtorBase
{
    #[\Override]
    public function render(): View
    {
        return view('components.trait-ctor-child');
    }
}
