--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function runRawCommand(\Illuminate\Http\Request $request): void {
    /** @var \Illuminate\Redis\Connections\PhpRedisConnection $redis */
    $redis = app(\Illuminate\Redis\Connections\PhpRedisConnection::class);
    $command = $request->input('command');
    $redis->executeRaw([$command]);
}
?>
--EXPECTF--
UnnecessaryVarAnnotation on line %d: The @var Illuminate\Redis\Connections\PhpRedisConnection annotation for $redis is unnecessary
MixedAssignment on line %d: Unable to determine the type that $command is being assigned to
TaintedEval on line %d: Detected tainted code passed to eval or similar
