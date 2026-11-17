--FILE--
<?php declare(strict_types=1);

use Illuminate\Routing\Redirector;

/** The service is `$abstract`, not the first written argument. */
function make_with_reordered_named_arguments(): void
{
    $_make = app()->make(parameters: [], abstract: Redirector::class);
    /** @psalm-check-type-exact $_make = Redirector */

    $_helper = app(parameters: [], abstract: Redirector::class);
    /** @psalm-check-type-exact $_helper = Redirector */
}
?>
--EXPECTF--
