--FILE--
<?php declare(strict_types=1);

namespace App;

use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Facades\Pipeline as PipelineFacade;

final class TrimPayload
{
    public function handle(mixed $passable, \Closure $next): mixed
    {
        return $next($passable);
    }
}

/** Non-final on purpose: Psalm treats an open class differently from a final one when checking `callable`. */
class AuthenticatePayload
{
    public function handle(mixed $passable, \Closure $next): mixed
    {
        return $next($passable);
    }
}

final class InvokablePayloadPipe
{
    public function __invoke(mixed $passable, \Closure $next): mixed
    {
        return $next($passable);
    }
}

function pipeline_payload_length(mixed $passable): int
{
    return \is_string($passable) ? \mb_strlen($passable) : 0;
}

/**
 * Pipeline::carry() accepts every pipe shape: a callable is called directly, a string is
 * resolved from the container (`name:param1,param2`), and any other object gets its via()
 * method (default `handle`) called. The plain-object pipes are the regression cases: an
 * object that is not callable used to be rejected with InvalidArgument.
 */
function pipeline_through_accepts_every_pipe_shape(Pipeline $pipeline): void
{
    $pipeline->through(TrimPayload::class);
    $pipeline->through('throttle:60,1');
    $pipeline->through(static fn (mixed $passable, \Closure $next): mixed => $next($passable));
    $pipeline->through(new InvokablePayloadPipe());
    $pipeline->through(new TrimPayload());
    $pipeline->through(new AuthenticatePayload());
    $pipeline->through(new TrimPayload(), new AuthenticatePayload());
    $pipeline->through([TrimPayload::class, 'throttle:60,1', new TrimPayload(), new AuthenticatePayload()]);
}

function pipeline_pipe_accepts_every_pipe_shape(Pipeline $pipeline): void
{
    $pipeline->pipe(TrimPayload::class);
    $pipeline->pipe(static fn (mixed $passable, \Closure $next): mixed => $next($passable));
    $pipeline->pipe(new TrimPayload());
    $pipeline->pipe(new AuthenticatePayload());
    $pipeline->pipe([new TrimPayload(), 'throttle:60,1']);
}

/** Widening to `object` must not degrade to `mixed`: a scalar is neither callable nor a container key. */
function pipeline_rejects_a_non_pipe_scalar(Pipeline $pipeline): void
{
    $pipeline->through(42);
    $pipeline->pipe([42]);
}

/**
 * Pipeline::then() returns the value produced by the destination closure, so the return
 * type follows that closure rather than Laravel's declared `mixed`.
 */
function pipeline_then_follows_the_destination_closure(Pipeline $pipeline): void
{
    $_string = $pipeline->send('payload')->through([])->then(static fn (mixed $passable): string => (string) $passable);
    /** @psalm-check-type-exact $_string = string */

    $_int = $pipeline->send('payload')->through([])->then(static fn (mixed $passable): int => pipeline_payload_length($passable));
    /** @psalm-check-type-exact $_int = int */
}

function pipeline_then_follows_the_destination_closure_through_the_facade(): void
{
    $_string = PipelineFacade::send('payload')->through([])->then(static fn (mixed $passable): string => (string) $passable);
    /** @psalm-check-type-exact $_string = string */
}
?>
--EXPECTF--
InvalidArgument on line %d: Argument 1 of Illuminate\Pipeline\Pipeline::through expects %s, but 42 provided
InvalidArgument on line %d: Argument 1 of Illuminate\Pipeline\Pipeline::pipe expects %s, but list{42} provided
