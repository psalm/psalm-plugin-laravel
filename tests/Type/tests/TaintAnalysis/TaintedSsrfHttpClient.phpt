--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function fetchEmbed(\Illuminate\Http\Request $request) {
    $embedUrl = $request->input('embed_url');
    $http = new \Illuminate\Http\Client\PendingRequest();
    $http->get($embedUrl);
}
?>
--EXPECTF--
%ATaintedSSRF on line %d: Detected tainted network request
