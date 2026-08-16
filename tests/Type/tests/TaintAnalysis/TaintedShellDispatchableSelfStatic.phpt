--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace TaintedShellDispatchableSelfStatic;

use Illuminate\Foundation\Bus\Dispatchable as BusDispatchable;
use Illuminate\Foundation\Events\Dispatchable as EventsDispatchable;
use Illuminate\Http\Request;

final class SelfRequeueJob
{
    use BusDispatchable;

    /** @psalm-taint-sink shell $command */
    public function __construct(mixed $command) {}

    public static function requeue(Request $request): void
    {
        self::dispatch($request->input('bus-command'));
    }

    public static function requeueClean(): void
    {
        self::dispatch('echo clean-self');
    }
}

final class StaticConditionalEvent
{
    use EventsDispatchable;

    /** @psalm-taint-sink shell $command */
    public function __construct(mixed $command) {}

    public static function fire(Request $request): void
    {
        static::dispatchIf(true, $request->input('event-command'));
    }

    public static function fireClean(): void
    {
        static::dispatchIf(true, 'echo clean-static');
    }
}

class BaseParentJob
{
    use BusDispatchable;

    /** @psalm-taint-sink shell $command */
    public function __construct(mixed $command) {}
}

final class ChildParentJob extends BaseParentJob
{
    public static function requeue(Request $request): void
    {
        parent::dispatch($request->input('parent-command'));
    }
}
?>
--EXPECTF--
TaintedShell on line %d: Detected tainted shell code
TaintedShell on line %d: Detected tainted shell code
TaintedShell on line %d: Detected tainted shell code
