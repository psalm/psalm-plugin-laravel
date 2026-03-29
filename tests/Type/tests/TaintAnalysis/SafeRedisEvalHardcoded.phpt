--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function runHardcodedScript(): void {
    /** @var \Illuminate\Redis\Connections\PhpRedisConnection $redis */
    $redis = app(\Illuminate\Redis\Connections\PhpRedisConnection::class);
    $redis->eval('return 1', 0);
    $redis->evalsha('return redis.call("get", KEYS[1])', 1, 'mykey');
    $redis->executeRaw(['PING']);
}
?>
--EXPECTF--
