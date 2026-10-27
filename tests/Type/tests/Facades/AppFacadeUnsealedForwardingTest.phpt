--FILE--
<?php declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Sandbox;

use App\Facades\ForwardingFacade;
use App\Facades\MacroableOnlyFacade;
use App\Facades\PlainFacade;
use App\Facades\ThrowingRootFacade;

/**
 * An app facade without its own `@method` tags, whose resolved root forwards unknown calls through
 * a non-Macroable `__call`, must not report UndefinedMagicMethod (#2138). Real root methods keep
 * their exact types.
 */
function test_forwarding_root_keeps_real_method_type_and_accepts_forwarded_calls(): int
{
    ForwardingFacade::anything();

    /** @psalm-check-type-exact $known = int */
    $known = ForwardingFacade::known();

    return $known;
}

/** Negative control: the root has no `__call`, so an unknown method really fails at runtime. */
function test_root_without_call_stays_reported(): void
{
    PlainFacade::bogus();
}

/** Negative control: the root's only `__call` is Macroable's, which throws for unknown methods. */
function test_macroable_only_root_stays_reported(): void
{
    MacroableOnlyFacade::bogus();
}

/**
 * Negative control: the root's constructor throws, so the root never resolves and the facade is
 * not unsealed even though the root class declares a forwarding `__call`.
 */
function test_unresolvable_root_stays_reported(): void
{
    ThrowingRootFacade::bogus();
}
?>
--EXPECTF--
UndefinedMagicMethod on line %d: Magic method App\Facades\PlainFacade::bogus does not exist
UndefinedMagicMethod on line %d: Magic method App\Facades\MacroableOnlyFacade::bogus does not exist
UndefinedMagicMethod on line %d: Magic method App\Facades\ThrowingRootFacade::bogus does not exist
