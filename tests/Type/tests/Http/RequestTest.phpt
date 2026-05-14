--FILE--
<?php declare(strict_types=1);

function it_returns_all_headers(\Illuminate\Http\Request $request): array {
    return $request->header();
};

function it_returns_one_header(\Illuminate\Http\Request $request): ?string {
    return $request->header('Accept');
};

function it_returns_route(\Illuminate\Http\Request $request): void {
    $_result = $request->route();
    /** @psalm-check-type-exact $_result = \Illuminate\Routing\Route|null */
};

function it_supports_null_safe_route_chain(\Illuminate\Http\Request $request): ?string {
    return $request->route()?->getName();
};

function it_supports_null_safe_route_chain_via_helper(): ?string {
    return request()->route()?->getName();
};

function it_returns_route_parameter(\Illuminate\Http\Request $request): void {
    $_result = $request->route('user');
    /** @psalm-check-type-exact $_result = string|null */
};

function it_returns_route_parameter_with_default(\Illuminate\Http\Request $request): void {
    $_result = $request->route('user', new \stdClass());
    /** @psalm-check-type-exact $_result = stdClass|string|null */
};
?>
--EXPECTF--
