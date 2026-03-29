--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function runUserScript(\Illuminate\Http\Request $request): void {
    /** @var \Illuminate\Redis\Connections\PhpRedisConnection $redis */
    $redis = app(\Illuminate\Redis\Connections\PhpRedisConnection::class);
    $scriptSha = $request->input('script');
    $redis->evalsha($scriptSha, 0);
}
?>
--EXPECTF--
%ATaintedEval on line %d: Detected tainted code passed to eval or similar
