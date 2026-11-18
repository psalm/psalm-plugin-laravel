--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --threads=1
--FILE--
<?php declare(strict_types=1);

function remember(string $token): \Illuminate\Http\JsonResponse {
    // secure: true, and the default (null), which config('session.secure') decides
    cookie('session', $token, 0, '/', null, true);
    cookie('session', $token);

    return response()->json(['ok' => 1])->cookie('session', $token, 0, '/', null, true, true, false, 'Strict');
}
?>
--EXPECTF--
