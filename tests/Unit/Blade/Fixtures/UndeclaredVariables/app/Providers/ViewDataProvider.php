<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

/** Boot-time view data the opt-in rule must see: a share, a composer, a creator, anonymous component roots. */
final class ViewDataProvider extends ServiceProvider
{
    public function boot(): void
    {
        View::share('sharedKey', 'shared');
        View::composer('composed', static fn(\Illuminate\View\View $view): \Illuminate\View\View => $view->with('fromComposer', 1));
        Blade::anonymousComponentPath(\dirname(__DIR__, 2) . '/resources/views/ui', 'ui');
        Blade::anonymousComponentNamespace('widgets', 'w');
        View::creator('created', static fn(\Illuminate\View\View $view): \Illuminate\View\View => $view->with('fromCreator', 1));
    }
}
