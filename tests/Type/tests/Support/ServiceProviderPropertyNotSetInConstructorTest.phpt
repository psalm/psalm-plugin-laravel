--FILE--
<?php declare(strict_types=1);

namespace App\Providers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as BaseAuthServiceProvider;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as BaseEventServiceProvider;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as BaseRouteServiceProvider;
use Illuminate\Support\ServiceProvider;

/**
 * Regression guard for psalm/psalm-plugin-laravel#945.
 *
 * Subclasses of `Illuminate\Support\ServiceProvider` (directly or via the framework's
 * EventServiceProvider / AuthServiceProvider / RouteServiceProvider intermediates) that declare
 * their own `__construct` and call `parent::__construct($app)` should NOT trigger
 * `PropertyNotSetInConstructor`. The `$app` property is declared on the parent and assigned in
 * its constructor; Psalm just does not trace `parent::__construct` to parent-property
 * assignments when checking the child.
 *
 * The fix marks `$app` as initialized on its declaring class storage
 * (`Illuminate\Support\ServiceProvider`) so the un-init check skips it for every subclass
 * without hiding any other un-initialised properties the user might declare.
 */
final class DirectSubclassWithConstructor extends ServiceProvider
{
    public function __construct(Application $app)
    {
        parent::__construct($app);
    }
}

final class EventServiceProviderWithConstructor extends BaseEventServiceProvider
{
    public function __construct(Application $app)
    {
        parent::__construct($app);

        $this->listen = [];
    }
}

final class AuthServiceProviderWithConstructor extends BaseAuthServiceProvider
{
    public function __construct(Application $app)
    {
        parent::__construct($app);
    }
}

final class RouteServiceProviderWithConstructor extends BaseRouteServiceProvider
{
    public function __construct(Application $app)
    {
        parent::__construct($app);
    }
}

/**
 * Multi-level inheritance check. The intermediate abstract base sits between
 * `Illuminate\Support\ServiceProvider` and the leaf provider, so a regression that
 * walks only one level of the parent chain instead of writing to the declaring storage
 * would fail this case.
 */
abstract class IntermediateServiceProvider extends ServiceProvider
{
    public function __construct(Application $app)
    {
        parent::__construct($app);
    }
}

final class DeeplyNestedServiceProvider extends IntermediateServiceProvider
{
    public function __construct(Application $app)
    {
        parent::__construct($app);
    }
}
?>
--EXPECTF--
