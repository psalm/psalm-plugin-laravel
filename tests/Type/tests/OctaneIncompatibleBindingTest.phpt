--FILE--
<?php declare(strict_types=1);

namespace App\Providers;

use App\Services\MyService;
use Illuminate\Auth\AuthManager;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Session\Session as SessionContract;
use Illuminate\Cookie\CookieJar;
use Illuminate\Http\Request;
use Illuminate\Session\SessionManager;
use Illuminate\Session\Store as SessionStore;
use Illuminate\Support\Facades\App;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    #[\Override]
    public function register(): void
    {
        // Bad — $app->make() of a request-scoped class inside singleton
        $this->app->singleton(MyService::class, function (Application $app): MyService {
            return new MyService($app->make(Request::class));
        });

        // Bad — array access with class-string
        $this->app->singleton('svc.1', function (Application $app): MyService {
            return new MyService($app[Request::class]);
        });

        // Bad — array access with string alias
        $this->app->singleton('svc.2', function (Application $app): MyService {
            return new MyService($app['request']);
        });

        // Bad — $this->app inside closure (closure bound to ServiceProvider)
        $this->app->singleton('svc.3', function (): MyService {
            return new MyService($this->app->make(SessionStore::class));
        });

        // Bad — app() helper
        $this->app->singleton('svc.4', function (): MyService {
            return new MyService(app(Authenticatable::class));
        });

        // Bad — resolve() helper
        $this->app->singleton('svc.5', function (): MyService {
            return new MyService(resolve(AuthFactory::class));
        });

        // Bad — App facade
        $this->app->singleton('svc.6', function (): MyService {
            return new MyService(App::make(Request::class));
        });

        // Bad — scoped() has the same caveat as singleton()
        $this->app->scoped('svc.7', function (Application $app): MyService {
            return new MyService($app->make(Request::class));
        });

        // Bad — singletonIf() (case-insensitive method name)
        $this->app->singletonIf('svc.8', function (Application $app): MyService {
            return new MyService($app->make(Request::class));
        });

        // Bad — scopedIf() (the fourth UNSAFE_METHODS entry)
        $this->app->scopedIf('svc.9', function (Application $app): MyService {
            return new MyService($app->make(Request::class));
        });

        // Bad — arrow function form
        $this->app->singleton(
            'svc.10',
            fn(Application $app): MyService => new MyService($app->make(Request::class)),
        );

        // Bad — makeWith resolver method (defined on the concrete Container, not the
        // Application contract, so type the closure param accordingly).
        $this->app->singleton('svc.11', function (\Illuminate\Foundation\Application $app): MyService {
            return new MyService($app->makeWith(Request::class, []));
        });

        // Bad — PSR-11 get() resolver
        $this->app->singleton('svc.12', function (Application $app): MyService {
            return new MyService($app->get(Request::class));
        });

        // Bad — remaining request-scoped classes (coverage for REQUEST_SCOPED_CLASSES)
        $this->app->singleton('svc.13', function (Application $app): MyService {
            return new MyService($app->make(CookieJar::class));
        });
        $this->app->singleton('svc.14', function (Application $app): MyService {
            return new MyService($app->make(AuthManager::class));
        });
        $this->app->singleton('svc.15', function (Application $app): MyService {
            return new MyService($app->make(Guard::class));
        });
        $this->app->singleton('svc.16', function (Application $app): MyService {
            return new MyService($app->make(SessionManager::class));
        });
        $this->app->singleton('svc.17', function (Application $app): MyService {
            return new MyService($app->make(SessionContract::class));
        });

        // Bad — remaining aliases (coverage for REQUEST_SCOPED_ALIASES)
        $this->app->singleton('svc.18', function (Application $app): MyService {
            return new MyService($app['auth']);
        });
        $this->app->singleton('svc.19', function (Application $app): MyService {
            return new MyService($app['auth.driver']);
        });
        $this->app->singleton('svc.20', function (Application $app): MyService {
            return new MyService($app['session']);
        });
        $this->app->singleton('svc.21', function (Application $app): MyService {
            return new MyService($app['session.store']);
        });
        $this->app->singleton('svc.22', function (Application $app): MyService {
            return new MyService($app['cookie']);
        });

        // Good — bind() re-executes per resolution, so request-scoped resolution is fine
        $this->app->bind('svc.ok1', function (Application $app): MyService {
            return new MyService($app->make(Request::class));
        });

        // Good — singleton with no request-scoped resolution
        $this->app->singleton('svc.ok2', function (): MyService {
            return new MyService(null);
        });

        // Good — singleton resolving a non-request-scoped class (regression guard for
        // REQUEST_SCOPED_CLASSES whitelist being consulted).
        $this->app->singleton('svc.ok3', function (Application $app): MyService {
            return new MyService($app->make(\Illuminate\Log\LogManager::class));
        });

        // Good — nested closure body does NOT belong to the outer singleton's scope.
        // The outer singleton's closure returns another closure; the inner one runs later
        // on each invocation, not during the singleton resolve. We intentionally do not
        // descend into nested Closure/ArrowFunction bodies.
        $this->app->singleton('svc.ok4', function (Application $app): \Closure {
            return function () use ($app): Request {
                return $app->make(Request::class);
            };
        });

        // Good — non-literal abstract is not detected (we only match literals)
        $class = Request::class;
        $this->app->singleton('svc.ok5', function (Application $app) use ($class): MyService {
            return new MyService($app->make($class));
        });
    }
}

namespace App\Services;

class MyService
{
    public function __construct(mixed $dep) {}
}
?>
--EXPECTF--
OctaneIncompatibleBinding on line %d: Resolving request-scoped 'Illuminate\Http\Request' inside singleton() closure leaks state across requests under Octane. Use bind() for per-resolution instances, or resolve the dependency inside the consuming method.
OctaneIncompatibleBinding on line %d: Resolving request-scoped 'Illuminate\Http\Request' inside singleton() closure leaks state across requests under Octane. Use bind() for per-resolution instances, or resolve the dependency inside the consuming method.
OctaneIncompatibleBinding on line %d: Resolving request-scoped 'request' inside singleton() closure leaks state across requests under Octane. Use bind() for per-resolution instances, or resolve the dependency inside the consuming method.
OctaneIncompatibleBinding on line %d: Resolving request-scoped 'Illuminate\Session\Store' inside singleton() closure leaks state across requests under Octane. Use bind() for per-resolution instances, or resolve the dependency inside the consuming method.
OctaneIncompatibleBinding on line %d: Resolving request-scoped 'Illuminate\Contracts\Auth\Authenticatable' inside singleton() closure leaks state across requests under Octane. Use bind() for per-resolution instances, or resolve the dependency inside the consuming method.
OctaneIncompatibleBinding on line %d: Resolving request-scoped 'Illuminate\Contracts\Auth\Factory' inside singleton() closure leaks state across requests under Octane. Use bind() for per-resolution instances, or resolve the dependency inside the consuming method.
OctaneIncompatibleBinding on line %d: Resolving request-scoped 'Illuminate\Http\Request' inside singleton() closure leaks state across requests under Octane. Use bind() for per-resolution instances, or resolve the dependency inside the consuming method.
OctaneIncompatibleBinding on line %d: Resolving request-scoped 'Illuminate\Http\Request' inside scoped() closure leaks state across requests under Octane. Use bind() for per-resolution instances, or resolve the dependency inside the consuming method.
OctaneIncompatibleBinding on line %d: Resolving request-scoped 'Illuminate\Http\Request' inside singletonIf() closure leaks state across requests under Octane. Use bind() for per-resolution instances, or resolve the dependency inside the consuming method.
OctaneIncompatibleBinding on line %d: Resolving request-scoped 'Illuminate\Http\Request' inside scopedIf() closure leaks state across requests under Octane. Use bind() for per-resolution instances, or resolve the dependency inside the consuming method.
OctaneIncompatibleBinding on line %d: Resolving request-scoped 'Illuminate\Http\Request' inside singleton() closure leaks state across requests under Octane. Use bind() for per-resolution instances, or resolve the dependency inside the consuming method.
OctaneIncompatibleBinding on line %d: Resolving request-scoped 'Illuminate\Http\Request' inside singleton() closure leaks state across requests under Octane. Use bind() for per-resolution instances, or resolve the dependency inside the consuming method.
OctaneIncompatibleBinding on line %d: Resolving request-scoped 'Illuminate\Http\Request' inside singleton() closure leaks state across requests under Octane. Use bind() for per-resolution instances, or resolve the dependency inside the consuming method.
OctaneIncompatibleBinding on line %d: Resolving request-scoped 'Illuminate\Cookie\CookieJar' inside singleton() closure leaks state across requests under Octane. Use bind() for per-resolution instances, or resolve the dependency inside the consuming method.
OctaneIncompatibleBinding on line %d: Resolving request-scoped 'Illuminate\Auth\AuthManager' inside singleton() closure leaks state across requests under Octane. Use bind() for per-resolution instances, or resolve the dependency inside the consuming method.
OctaneIncompatibleBinding on line %d: Resolving request-scoped 'Illuminate\Contracts\Auth\Guard' inside singleton() closure leaks state across requests under Octane. Use bind() for per-resolution instances, or resolve the dependency inside the consuming method.
OctaneIncompatibleBinding on line %d: Resolving request-scoped 'Illuminate\Session\SessionManager' inside singleton() closure leaks state across requests under Octane. Use bind() for per-resolution instances, or resolve the dependency inside the consuming method.
OctaneIncompatibleBinding on line %d: Resolving request-scoped 'Illuminate\Contracts\Session\Session' inside singleton() closure leaks state across requests under Octane. Use bind() for per-resolution instances, or resolve the dependency inside the consuming method.
OctaneIncompatibleBinding on line %d: Resolving request-scoped 'auth' inside singleton() closure leaks state across requests under Octane. Use bind() for per-resolution instances, or resolve the dependency inside the consuming method.
OctaneIncompatibleBinding on line %d: Resolving request-scoped 'auth.driver' inside singleton() closure leaks state across requests under Octane. Use bind() for per-resolution instances, or resolve the dependency inside the consuming method.
OctaneIncompatibleBinding on line %d: Resolving request-scoped 'session' inside singleton() closure leaks state across requests under Octane. Use bind() for per-resolution instances, or resolve the dependency inside the consuming method.
OctaneIncompatibleBinding on line %d: Resolving request-scoped 'session.store' inside singleton() closure leaks state across requests under Octane. Use bind() for per-resolution instances, or resolve the dependency inside the consuming method.
OctaneIncompatibleBinding on line %d: Resolving request-scoped 'cookie' inside singleton() closure leaks state across requests under Octane. Use bind() for per-resolution instances, or resolve the dependency inside the consuming method.
