--FILE--
<?php declare(strict_types=1);

namespace App;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Queue\Queue;

/**
 * Guard: Filesystem/Cloud/Queue are in ContractMethodBridgeHandler::EXCLUDED_CONTRACTS
 * (concrete class varies by driver), so concrete-only methods must still raise
 * UndefinedInterfaceMethod on contract-typed receivers.
 */
function filesystem_contract_excluded(Filesystem $filesystem): void
{
    $filesystem->temporaryUrl('path.txt', new \DateTime());
}

// allPendingJobs() deliberately picked: unlike bulk()/push()/pop() it is NOT on
// the Queue contract, only on some concrete drivers.
function queue_contract_excluded(Queue $queue): void
{
    $queue->allPendingJobs();
}
?>
--EXPECTF--
UndefinedInterfaceMethod on line %d: Method Illuminate\Contracts\Filesystem\Filesystem::temporaryUrl does not exist
UndefinedInterfaceMethod on line %d: Method Illuminate\Contracts\Queue\Queue::allPendingJobs does not exist
