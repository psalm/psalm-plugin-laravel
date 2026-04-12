--FILE--
<?php declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable as BusDispatchable;
use Illuminate\Foundation\Events\Dispatchable as EventsDispatchable;

class User extends Model {}

/** Bus job: two required constructor params */
class SendEmail
{
    use BusDispatchable;

    public function __construct(private User $user, private string $subject) {}
    public function handle(): void {}
}

/** Bus job: no constructor args */
class NoArgJob
{
    use BusDispatchable;
    public function handle(): void {}
}

/** Bus job: optional arg with default */
class OptionalArgJob
{
    use BusDispatchable;
    public function __construct(private string $locale = 'en') {}
    public function handle(): void {}
}

/** Bus job that overrides dispatch() — our handler must NOT interfere */
class CustomDispatchJob
{
    use BusDispatchable;

    public function __construct(private User $user) {}

    /** @return \Illuminate\Foundation\Bus\PendingDispatch */
    public static function dispatch(User $user): \Illuminate\Foundation\Bus\PendingDispatch
    {
        return static::newPendingDispatch(new self($user));
    }

    public function handle(): void {}
}

/** Event using Events\Dispatchable — also validated */
class OrderShipped
{
    use EventsDispatchable;

    public function __construct(public readonly User $user, public readonly int $orderId) {}
}

function test_dispatch_validation(User $user): void
{
    // OK — correct args; dispatch() returns PendingDispatch
    $_pending = SendEmail::dispatch($user, 'Hello');
    /** @psalm-check-type-exact $_pending = \Illuminate\Foundation\Bus\PendingDispatch */

    // Error — missing $subject
    SendEmail::dispatch($user);

    // Error — too many args
    SendEmail::dispatch($user, 'Hello', 'extra');

    // Error — wrong type for $user param (string instead of User)
    SendEmail::dispatch('not-a-user', 'Hello');

    // No-arg job: OK
    NoArgJob::dispatch();

    // No-arg job: Error — too many args
    NoArgJob::dispatch('extra');

    // Optional arg job: both valid
    OptionalArgJob::dispatch();
    OptionalArgJob::dispatch('fr');

    // Custom dispatch() override — handler skips, no errors from us
    CustomDispatchJob::dispatch($user);
}

function test_dispatchif_validation(User $user): void
{
    // OK — condition + correct constructor args
    SendEmail::dispatchIf(true, $user, 'Hello');

    // Error — missing $subject (condition arg excluded)
    SendEmail::dispatchIf(true, $user);

    // Error — too many constructor args
    SendEmail::dispatchIf(true, $user, 'Hello', 'extra');
}

function test_dispatchunless_validation(User $user): void
{
    // OK — condition + correct constructor args
    SendEmail::dispatchUnless(false, $user, 'Hello');

    // Error — missing $subject
    SendEmail::dispatchUnless(false, $user);
}

function test_dispatchsync_validation(User $user): void
{
    // OK
    SendEmail::dispatchSync($user, 'Hello');

    // Error — missing $subject
    SendEmail::dispatchSync($user);
}

function test_dispatchafterresponse_validation(User $user): void
{
    // OK
    SendEmail::dispatchAfterResponse($user, 'Hello');

    // Error — missing $subject
    SendEmail::dispatchAfterResponse($user);
}

function test_events_dispatchable(User $user): void
{
    // OK — correct args
    OrderShipped::dispatch($user, 1);

    // Error — missing $orderId
    OrderShipped::dispatch($user);

    // OK — condition + correct args
    OrderShipped::dispatchIf(true, $user, 1);

    // Error — missing $orderId (condition excluded)
    OrderShipped::dispatchIf(true, $user);

    // OK — broadcast all args
    OrderShipped::broadcast($user, 1);

    // Error — missing $orderId
    OrderShipped::broadcast($user);
}
?>
--EXPECTF--
TooFewArguments on line %d: Too few arguments for App\Jobs\SendEmail::__construct - expecting subject to be passed
TooManyArguments on line %d: Too many arguments for App\Jobs\SendEmail::__construct - expecting 2 but saw 3
InvalidArgument on line %d: Argument 1 of App\Jobs\SendEmail::__construct expects App\Jobs\User, but 'not-a-user' provided
TooManyArguments on line %d: Class App\Jobs\NoArgJob has no constructor, but arguments were passed to NoArgJob::dispatch()
TooFewArguments on line %d: Too few arguments for App\Jobs\SendEmail::__construct - expecting subject to be passed
TooManyArguments on line %d: Too many arguments for App\Jobs\SendEmail::__construct - expecting 2 but saw 3
TooFewArguments on line %d: Too few arguments for App\Jobs\SendEmail::__construct - expecting subject to be passed
TooFewArguments on line %d: Too few arguments for App\Jobs\SendEmail::__construct - expecting subject to be passed
TooFewArguments on line %d: Too few arguments for App\Jobs\SendEmail::__construct - expecting subject to be passed
TooFewArguments on line %d: Too few arguments for App\Jobs\OrderShipped::__construct - expecting orderId to be passed
TooFewArguments on line %d: Too few arguments for App\Jobs\OrderShipped::__construct - expecting orderId to be passed
TooFewArguments on line %d: Too few arguments for App\Jobs\OrderShipped::__construct - expecting orderId to be passed
