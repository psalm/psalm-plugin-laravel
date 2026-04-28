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

// Issue #801: chained property access on $request->route('name') must not
// trigger InvalidPropertyFetch. The stub fallback adds Model|null to the
// return union (and BackedEnum, for Laravel's implicit enum binding) so
// callers that rely on route-model binding don't trip over the
// unresolved-binding case.
function it_returns_route_param_with_model_fallback(
    \Illuminate\Http\Request $request,
): null|string|\Illuminate\Database\Eloquent\Model|\BackedEnum {
    /** @psalm-check-type-exact $value = string|\Illuminate\Database\Eloquent\Model|\BackedEnum|null */
    $value = $request->route('station');
    return $value;
};
?>
--EXPECTF--
