--FILE--
<?php declare(strict_types=1);

use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;

function redis_pipeline_callback_results_are_lists(Connection $connection): void
{
    $_facadePipeline = Redis::pipeline(static fn(mixed $pipe): mixed => $pipe);
    if (is_array($_facadePipeline)) {
        /** @psalm-check-type-exact $_facadePipeline = list<mixed> */
    }

    $_facadeTransaction = Redis::transaction(static fn(mixed $transaction): mixed => $transaction);
    if (is_array($_facadeTransaction)) {
        /** @psalm-check-type-exact $_facadeTransaction = list<mixed> */
    }

    $_connectionPipeline = $connection->pipeline(static fn(mixed $pipe): mixed => $pipe);
    if (is_array($_connectionPipeline)) {
        /** @psalm-check-type-exact $_connectionPipeline = list<mixed> */
    }

    $_connectionTransaction = $connection->transaction(
        static fn(mixed $transaction): mixed => $transaction,
    );
    if (is_array($_connectionTransaction)) {
        /** @psalm-check-type-exact $_connectionTransaction = list<mixed> */
    }

    $_facadePipelineContext = Redis::pipeline();
    /** @psalm-check-type-exact $_facadePipelineContext = false|object */

    $_connectionTransactionContext = $connection->transaction();
    /** @psalm-check-type-exact $_connectionTransactionContext = false|object */
}
?>
--EXPECTF--
