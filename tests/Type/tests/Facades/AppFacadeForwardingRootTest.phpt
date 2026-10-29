--FILE--
<?php declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Sandbox;

use App\Facades\ForwardingFacade;
use App\Facades\MacroableDispatchFacade;
use App\Facades\ReversedForwardingFacade;
use App\Facades\StubbedMacroableFacade;

/** A facade without `@method` tags accepts calls its root forwards through `__call` (#2138). */
function test_forwarding_root(): int
{
    ForwardingFacade::anything();

    /** @psalm-check-type-exact $known = int */
    $known = ForwardingFacade::known();

    return $known;
}

/** Psalm stores Macroable's `__call` (first-listed trait), PHP runs the forwarder (`insteadof`). */
function test_insteadof_forwarder_wins(): void
{
    ReversedForwardingFacade::anything();
}

/** A class stub declares `__call`, but PHP runs Macroable's, which throws for unknown methods. */
function test_stubbed_call_over_macroable_root(): void
{
    StubbedMacroableFacade::bogus();
}

/** `use Macroable` on the facade replaces `Facade::__callStatic`, so the root is never reached. */
function test_facade_dispatcher_replaced(): void
{
    MacroableDispatchFacade::bogus();
}
?>
--EXPECTF--
UndefinedMagicMethod on line %d: Magic method App\Facades\StubbedMacroableFacade::bogus does not exist
UndefinedMagicMethod on line %d: Magic method App\Facades\MacroableDispatchFacade::bogus does not exist
