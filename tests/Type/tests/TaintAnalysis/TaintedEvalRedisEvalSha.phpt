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
UnnecessaryVarAnnotation on line %d: The @var Illuminate\Redis\Connections\PhpRedisConnection annotation for $redis is unnecessary
MixedAssignment on line %d: Unable to determine the type that $scriptSha is being assigned to
MixedArgument on line %d: Argument 1 of Illuminate\Redis\Connections\PhpRedisConnection::evalsha cannot be mixed, expecting string
TaintedEval on line %d: Detected tainted code passed to eval or similar
