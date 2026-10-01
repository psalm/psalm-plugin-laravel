--FILE--
<?php declare(strict_types=1);

use Illuminate\Contracts\Events\Dispatcher as DispatcherContract;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Event;

// With halting (until(), or dispatch() with $halt = true) the dispatcher returns the first
// non-null listener response as is, so the result is mixed rather than Laravel's array|null.
// Without halting it returns the list of responses, or null for deferred/after-commit events.

function halting(Dispatcher $dispatcher, DispatcherContract $contract): void
{
    /** @psalm-check-type-exact $until = mixed */
    $until = $dispatcher->until('event');

    /** @psalm-check-type-exact $halted = mixed */
    $halted = $dispatcher->dispatch('event', [], true);

    /** @psalm-check-type-exact $contractHalted = mixed */
    $contractHalted = $contract->dispatch('event', [], true);

    /** @psalm-check-type-exact $facadeUntil = mixed */
    $facadeUntil = Event::until('event');

    /** @psalm-check-type-exact $facadeHalted = mixed */
    $facadeHalted = Event::dispatch('event', [], true);

    echo \count([$until, $halted, $contractHalted, $facadeUntil, $facadeHalted]);
}

function collecting(Dispatcher $dispatcher, DispatcherContract $contract): void
{
    /** @psalm-check-type-exact $responses = list<mixed>|null */
    $responses = $dispatcher->dispatch('event');

    /** @psalm-check-type-exact $contractResponses = list<mixed>|null */
    $contractResponses = $contract->dispatch('event');

    /** @psalm-check-type-exact $facadeResponses = list<mixed>|null */
    $facadeResponses = Event::dispatch('event');

    echo \count([$responses, $contractResponses, $facadeResponses]);
}
?>
--EXPECTF--
MixedAssignment on line %d: Unable to determine the type that $until is being assigned to
MixedAssignment on line %d: Unable to determine the type that $halted is being assigned to
MixedAssignment on line %d: Unable to determine the type that $contractHalted is being assigned to
MixedAssignment on line %d: Unable to determine the type that $facadeUntil is being assigned to
MixedAssignment on line %d: Unable to determine the type that $facadeHalted is being assigned to
