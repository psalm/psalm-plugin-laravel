--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm-with-optin-custom-issues.xml
--FILE--
<?php declare(strict_types=1);

// Router::view($uri, $view, ...) — the view name is at position 1, not 0.
\Illuminate\Support\Facades\Route::view('/route-a', 'route-positional-missing');
\Illuminate\Support\Facades\Route::view('/route-b', 'welcome');

// Named arguments reordered — the positional-only extraction this replaces would
// misread '/route-c' (position 1) as the view name and falsely report it missing.
\Illuminate\Support\Facades\Route::view(view: 'route-named-missing', uri: '/route-c');

// Named arguments reordered, view exists — regression pin: reading the wrong slot
// would report the URI '/route-d' as a missing view.
\Illuminate\Support\Facades\Route::view(view: 'welcome', uri: '/route-d');

// Named arguments in declaration order — should behave like the positional form.
\Illuminate\Support\Facades\Route::view(uri: '/route-e', view: 'route-named-in-order-missing');

// Concrete Router (e.g. injected directly rather than via the Route facade).
function _diRouter(\Illuminate\Routing\Router $router): void {
    $router->view('/route-f', 'route-concrete-missing');
}
?>
--EXPECTF--
MissingView on line %d: View 'route-positional-missing' not found in any of the registered view paths
MissingView on line %d: View 'route-named-missing' not found in any of the registered view paths
MissingView on line %d: View 'route-named-in-order-missing' not found in any of the registered view paths
MissingView on line %d: View 'route-concrete-missing' not found in any of the registered view paths
