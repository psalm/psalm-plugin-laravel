--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function staticHeaders(): \Illuminate\Http\Response {
    return response('OK')
        ->header('X-Custom', 'static-value')
        ->withHeaders(['Content-Type' => 'application/json']);
}
?>
--EXPECTF--
