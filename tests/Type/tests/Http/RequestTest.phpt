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
?>
--EXPECTF--
