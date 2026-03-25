--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * SaaS webhook configuration — user sets the API endpoint
 * for outbound integrations, poisoning all subsequent requests.
 */
function configureWebhook(\Illuminate\Http\Request $request): void {
    $apiEndpoint = (string) $request->input('api_endpoint');
    $http = new \Illuminate\Http\Client\PendingRequest();
    $http->baseUrl($apiEndpoint);
}
?>
--EXPECTF--
%ATaintedSSRF on line %d: Detected tainted network request
