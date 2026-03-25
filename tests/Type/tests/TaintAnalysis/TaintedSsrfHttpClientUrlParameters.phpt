--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * Multi-tenant service routing — user controls a URL
 * template parameter used in subsequent requests.
 */
function routeToService(\Illuminate\Http\Request $request): void {
    $serviceHost = (string) $request->input('service_host');
    $http = new \Illuminate\Http\Client\PendingRequest();
    $http->withUrlParameters(['endpoint' => $serviceHost, 'version' => 'v1']);
}
?>
--EXPECTF--
%ATaintedSSRF on line %d: Detected tainted network request
