--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace TaintedShellDispatchableTraitIsolation;

use Illuminate\Foundation\Bus\Dispatchable as BusDispatchable;
use Illuminate\Foundation\Events\Dispatchable as EventsDispatchable;
use Illuminate\Http\Request;

final class TaintedBusJob
{
    use BusDispatchable;

    /** @psalm-taint-sink shell $command */
    public function __construct(mixed $command) {}
}

final class CleanBusJob
{
    use BusDispatchable;

    /** @psalm-taint-sink shell $command */
    public function __construct(mixed $command) {}
}

final class TaintedEvent
{
    use EventsDispatchable;

    /** @psalm-taint-sink shell $command */
    public function __construct(mixed $command) {}
}

final class CleanEvent
{
    use EventsDispatchable;

    /** @psalm-taint-sink shell $command */
    public function __construct(mixed $command) {}
}

function dispatchesRemainIsolated(Request $request): void
{
    TaintedBusJob::dispatch($request->input('bus-command'));
    CleanBusJob::dispatch('echo clean-bus');
    TaintedEvent::dispatch($request->input('event-command'));
    CleanEvent::dispatch('echo clean-event');
}
?>
--EXPECTF--
TaintedShell on line %d: Detected tainted shell code
TaintedShell on line %d: Detected tainted shell code
