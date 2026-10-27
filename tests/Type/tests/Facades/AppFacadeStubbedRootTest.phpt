--FILE--
<?php declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Sandbox;

use App\Facades\StubbedMacroableFacade;

/**
 * Negative control: a class-level Psalm stub gives the root its own `__call`, but PHP still runs
 * the `Macroable::__call` of the real class, which throws for unknown methods (#2138). The runtime
 * check must therefore run even when Psalm names the root itself as the declaring class.
 */
function test_stubbed_call_on_macroable_root_stays_reported(): void
{
    StubbedMacroableFacade::bogus();
}
?>
--EXPECTF--
UndefinedMagicMethod on line %d: Magic method App\Facades\StubbedMacroableFacade::bogus does not exist
