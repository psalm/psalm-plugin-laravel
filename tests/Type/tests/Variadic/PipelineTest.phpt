--FILE--
<?php declare(strict_types=1);

namespace App;

use Illuminate\Pipeline\Pipeline;

/**
 * Pipeline::through() and ::pipe() branch on is_array($pipes) and fall back to
 * func_get_args() — so variadic pipe arguments are valid.
 */
function pipeline_through_variadic(Pipeline $pipeline): void
{
    $_single = $pipeline->through('auth');
    /** @psalm-check-type-exact $_single = Pipeline&static */

    $_variadic = $pipeline->through('auth', 'throttle:60,1', 'verified');
    /** @psalm-check-type-exact $_variadic = Pipeline&static */

    $_array = $pipeline->through(['auth', 'throttle:60,1']);
    /** @psalm-check-type-exact $_array = Pipeline&static */

    // Closure form — the stub advertises Closure in the union.
    $_closure = $pipeline->through(fn (mixed $passable, \Closure $next): mixed => $next($passable));
    /** @psalm-check-type-exact $_closure = Pipeline&static */
}

function pipeline_pipe_variadic(Pipeline $pipeline): void
{
    $_single = $pipeline->pipe('middleware');
    /** @psalm-check-type-exact $_single = Pipeline&static */

    $_variadic = $pipeline->pipe('auth', 'throttle:60,1');
    /** @psalm-check-type-exact $_variadic = Pipeline&static */

    $_array = $pipeline->pipe(['auth', 'throttle:60,1']);
    /** @psalm-check-type-exact $_array = Pipeline&static */
}
?>
--EXPECTF--
