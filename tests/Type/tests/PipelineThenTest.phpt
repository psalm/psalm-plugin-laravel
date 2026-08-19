--FILE--
<?php declare(strict_types=1);

namespace App;

use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Facades\Pipeline as PipelineFacade;

/**
 * Pipeline::then() returns the value produced by the destination closure, so the return
 * type follows that closure rather than Laravel's declared `mixed`.
 */
function pipeline_then_follows_the_destination_closure(Pipeline $pipeline): void
{
    $_string = $pipeline->send('payload')->through([])->then(static fn (mixed $passable): string => (string) $passable);
    /** @psalm-check-type-exact $_string = string */

    $_int = $pipeline->send('payload')->through([])->then(static fn (mixed $passable): int => 42);
    /** @psalm-check-type-exact $_int = int */
}

function pipeline_then_follows_the_destination_closure_through_the_facade(): void
{
    $_string = PipelineFacade::send('payload')->through([])->then(static fn (mixed $passable): string => (string) $passable);
    /** @psalm-check-type-exact $_string = string */
}
?>
--EXPECTF--
