--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function runHardcodedScript(): void {
    // The plugin infers `app(Foo::class)` as Foo even when the booted app cannot
    // build it (PhpRedisConnection needs a $client ctor arg, unresolvable here),
    // so the previously-required `@var` hint is now redundant (#757).
    $redis = app(\Illuminate\Redis\Connections\PhpRedisConnection::class);
    $redis->eval('return 1', 0);
    $redis->evalsha('e0e1f9fabfc9d4800c877a703b823ac0578ff8db', 1, 'mykey');
    $redis->executeRaw(['PING']);
}
?>
--EXPECTF--
