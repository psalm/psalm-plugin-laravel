--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\ViewErrorBag;

/**
 * `ViewErrorBag::__call()` forwards everything not declared on the class itself to the
 * default `MessageBag` via `getBag('default')`, mixed in through the CONTRACT
 * (`@mixin \Illuminate\Contracts\Support\MessageBag`). The contract omits several methods
 * the concrete bag actually has, so those are declared as explicit `@method` tags on the
 * stub instead of widening the mixin to the concrete class, which would suppress
 * UndefinedMagicMethod entirely.
 */
function view_error_bag_has_any_variadic(ViewErrorBag $errors): void
{
    $_single = $errors->hasAny('a');
    /** @psalm-check-type-exact $_single = bool */

    $_variadic = $errors->hasAny('a', 'b');
    /** @psalm-check-type-exact $_variadic = bool */

    $_array = $errors->hasAny(['a', 'b']);
    /** @psalm-check-type-exact $_array = bool */
}

function view_error_bag_missing(ViewErrorBag $errors): void
{
    $_ = $errors->missing('a');
    /** @psalm-check-type-exact $_ = bool */
}

// Negative: a genuinely undefined method must still report UndefinedMagicMethod, proving the
// mixin was kept at the contract rather than widened to the concrete MessageBag.
function view_error_bag_unknown_method_still_reports(ViewErrorBag $errors): void
{
    $errors->nopeNotAMethod();
}
?>
--EXPECTF--
UndefinedMagicMethod on line %d: Magic method Illuminate\Support\ViewErrorBag::nopenotamethod does not exist
