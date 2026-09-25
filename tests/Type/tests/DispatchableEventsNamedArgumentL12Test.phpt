--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelVersion::skipFrom('13.0.0');
--FILE--
<?php declare(strict_types=1);

namespace DispatchableEventsNamedArgumentL12;

use Illuminate\Foundation\Events\Dispatchable;

final class Event
{
    use Dispatchable;

    public function __construct(mixed $arguments) {}
}

function eventDispatchDoesNotAcceptNamedArguments(): void
{
    Event::dispatch(arguments: 'event');
    Event::broadcast(arguments: 'event');
}
?>
--EXPECTF--
InvalidNamedArgument on line %d: Parameter $arguments does not exist on function DispatchableEventsNamedArgumentL12\Event::dispatch
InvalidNamedArgument on line %d: Parameter $arguments does not exist on function DispatchableEventsNamedArgumentL12\Event::broadcast
