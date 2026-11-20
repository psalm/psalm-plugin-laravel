--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --threads=1
--FILE--
<?php declare(strict_types=1);

function remember(string $token): \Illuminate\Http\JsonResponse {
    return response()->json(['ok' => 1])->cookie('session', $token, 0, '/', null, false, true, false, 'Strict');
}
?>
--EXPECTF--
InsecureCookie on line %d: The cookie is made without the secure flag, so it is also sent over plain HTTP
