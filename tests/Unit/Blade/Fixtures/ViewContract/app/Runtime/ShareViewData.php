<?php

declare(strict_types=1);

namespace ViewContractFixture\Runtime;

use Illuminate\Contracts\View\Factory;
use Illuminate\Support\Facades\View;

// Composers registered while a request runs, never at boot: the plugin cannot read their keys, so
// each view they reach is exempt as a whole.
final class ShareViewData
{
    public function __construct(private readonly Factory $views) {}

    public function handle(\Illuminate\View\Factory $factory, UserlandRegistry $registry): void
    {
        View::composer('runtime-facade', static fn(): null => null);
        view()->composer(['runtime-helper'], static fn(): null => null);
        $factory->creator('runtime-injected', static fn(): null => null);
        $this->views->composer('runtime-property', static fn(): null => null);
        View::creator('runtime-wild.*', static fn(): null => null);
        app('view')->composer('runtime-container', static fn(): null => null);
        View::composers([LateComposer::class => ['runtime-map']]);

        $assigned = view();
        $assigned->composer('runtime-assigned', static fn(): null => null);

        // Not a view factory: `runtime-userland` stays checked.
        $registry->composer('runtime-userland', static fn(): null => null);
    }
}
