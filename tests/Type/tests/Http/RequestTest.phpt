--FILE--
<?php declare(strict_types=1);

function it_returns_all_headers(\Illuminate\Http\Request $request): array {
    return $request->header();
};

function it_returns_one_header(\Illuminate\Http\Request $request): ?string {
    return $request->header('Accept');
};

function it_returns_route(\Illuminate\Http\Request $request): \Illuminate\Routing\Route {
    return $request->route();
};

/**
 * route()'s second parameter used to be constrained `@template TDefault of object`,
 * which false-positived on any ordinary scalar/null default (Argument 2 ... expects
 * object). Locks in that plain scalar and null defaults are accepted, and that the
 * with-args return includes the resolved default's own type plus the bare `object`
 * case for a route-model-bound parameter.
 */
function it_accepts_non_object_route_defaults(\Illuminate\Http\Request $request): void {
    $_stringDefault = $request->route('id', 'fallback-string');
    /** @psalm-check-type-exact $_stringDefault = object|string|null */

    $_intDefault = $request->route('id', 0);
    /** @psalm-check-type-exact $_intDefault = 0|null|object|string */

    $_noDefault = $request->route('id');
    /** @psalm-check-type-exact $_noDefault = null|object|string */
}
?>
--EXPECTF--
