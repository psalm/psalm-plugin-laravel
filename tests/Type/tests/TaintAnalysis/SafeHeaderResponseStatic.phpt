--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --threads=1
--FILE--
<?php declare(strict_types=1);

function staticHeaders(): \Illuminate\Http\Response {
    return response('OK')
        ->header('X-Custom', 'static-value')
        ->withHeaders(['Content-Type' => 'application/json']);
}
?>
--EXPECTF--
