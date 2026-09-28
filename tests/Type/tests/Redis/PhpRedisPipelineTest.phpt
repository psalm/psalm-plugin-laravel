--SKIPIF--
<?php
if (!class_exists(\Redis::class)) {
    echo 'skip ext-redis is not installed';
}
?>
--FILE--
<?php declare(strict_types=1);

use Illuminate\Redis\Connections\PhpRedisConnection;

function php_redis_pipeline_callback_results_are_lists(PhpRedisConnection $connection): void
{
    $_pipeline = $connection->pipeline(static function ($pipeline): void {
        $pipeline->get('key');
    });
    if (is_array($_pipeline)) {
        /** @psalm-check-type-exact $_pipeline = list<mixed> */
    }

    $_transaction = $connection->transaction(static function ($transaction): void {
        $transaction->set('key', 'value');
    });
    if (is_array($_transaction)) {
        /** @psalm-check-type-exact $_transaction = list<mixed> */
    }

    $_pipelineContext = $connection->pipeline();
    /** @psalm-check-type-exact $_pipelineContext = Redis|false */
}
?>
--EXPECTF--
