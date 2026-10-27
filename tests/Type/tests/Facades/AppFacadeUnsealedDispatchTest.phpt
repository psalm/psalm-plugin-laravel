--FILE--
<?php declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Sandbox;

use App\Facades\AliasedMacroableFacade;
use App\Facades\MacroableDispatchFacade;
use App\Facades\OwnCallMacroableFacade;
use App\Facades\OwnDispatchFacade;
use App\Facades\PrecedenceMacroableFacade;
use App\Facades\ReversedForwardingFacade;

/**
 * Runtime dispatch decides whether an unknown facade call forwards to the root's `__call`
 * (#2138), so the unsealing needs the effective dispatchers, not Psalm's storage provenance.
 */

/**
 * Negative control: `Macroable::__call insteadof ForwardsUnknownCalls` makes the throwing Macroable
 * `__call` win at runtime. Psalm's storage still names the forwarding trait.
 */
function test_insteadof_macroable_root_stays_reported(): void
{
    PrecedenceMacroableFacade::bogus();
}

/** Negative control: the facade's `use Macroable` replaces `Facade::__callStatic` and throws first. */
function test_macroable_facade_dispatcher_stays_reported(): void
{
    MacroableDispatchFacade::bogus();
}

/** Negative control: the facade's own `__callStatic` replaces `Facade::__callStatic` and throws first. */
function test_own_dispatcher_facade_stays_reported(): void
{
    OwnDispatchFacade::bogus();
}

/** Positive control: the class's own `__call` wins over the one Macroable provides. */
function test_own_call_beats_macroable_root_is_unsealed(): void
{
    OwnCallMacroableFacade::anything();
}

/** Positive control: the forwarding trait wins `__call`, Macroable's is only aliased. */
function test_aliased_macroable_root_is_unsealed(): void
{
    AliasedMacroableFacade::anything();
}

/** Positive control: Macroable is listed first, so Psalm names it, but `insteadof` makes the forwarder win. */
function test_reversed_order_forwarding_root_is_unsealed(): void
{
    ReversedForwardingFacade::anything();
}
?>
--EXPECTF--
UndefinedMagicMethod on line %d: Magic method App\Facades\PrecedenceMacroableFacade::bogus does not exist
UndefinedMagicMethod on line %d: Magic method App\Facades\MacroableDispatchFacade::bogus does not exist
UndefinedMagicMethod on line %d: Magic method App\Facades\OwnDispatchFacade::bogus does not exist
