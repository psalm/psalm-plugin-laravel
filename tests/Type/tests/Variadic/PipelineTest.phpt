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

/**
 * Pre-instantiated pipe objects are valid: Pipeline::carry() calls the pipe's
 * method (default `handle`) on anything that is an object.
 */
function pipeline_through_object_pipes(Pipeline $pipeline, object $pipe, object $anotherPipe): void
{
    $_object = $pipeline->through($pipe);
    /** @psalm-check-type-exact $_object = Pipeline&static */

    $_list = $pipeline->through([$pipe, $anotherPipe]);
    /** @psalm-check-type-exact $_list = Pipeline&static */
}

function pipeline_pipe_variadic(Pipeline $pipeline, object $pipe): void
{
    $_single = $pipeline->pipe('middleware');
    /** @psalm-check-type-exact $_single = Pipeline&static */

    $_variadic = $pipeline->pipe('auth', 'throttle:60,1');
    /** @psalm-check-type-exact $_variadic = Pipeline&static */

    $_array = $pipeline->pipe(['auth', 'throttle:60,1']);
    /** @psalm-check-type-exact $_array = Pipeline&static */

    $_object = $pipeline->pipe($pipe);
    /** @psalm-check-type-exact $_object = Pipeline&static */
}
?>
--EXPECTF--
