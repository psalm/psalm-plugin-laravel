--FILE--
<?php declare(strict_types=1);

namespace App;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Queue\Queue;

/**
 * Guard: Filesystem/Cloud/Queue are excluded from the dynamic contract walk
 * (see ContractMethodBridgeHandler::EXCLUDED_CONTRACTS) because the resolved
 * concrete CLASS varies by driver — bridging the default driver's surface would
 * lie at the class-hierarchy level for every other driver. temporaryUrl() is
 * declared on no contract at all, only on the concrete FilesystemAdapter/
 * AwsS3V3Adapter, so it must still raise UndefinedInterfaceMethod on the
 * contract-typed receiver.
 */
function filesystem_contract_excluded(Filesystem $filesystem): void
{
    $filesystem->temporaryUrl('path.txt', new \DateTime());
}

/**
 * Guard: Queue is excluded for the same reason — sync/database/redis/sqs
 * resolve driver-specific concrete classes with driver-only publics.
 * allPendingJobs() is declared on no contract (bulk()/push()/pop()/... are —
 * this deliberately picks a method that ISN'T), only on some concrete queue
 * drivers (e.g. DatabaseQueue, RedisQueue).
 */
function queue_contract_excluded(Queue $queue): void
{
    $queue->allPendingJobs();
}
?>
--EXPECTF--
UndefinedInterfaceMethod on line %d: Method Illuminate\Contracts\Filesystem\Filesystem::temporaryUrl does not exist
UndefinedInterfaceMethod on line %d: Method Illuminate\Contracts\Queue\Queue::allPendingJobs does not exist
