--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm-find-octane-incompatible-binding.xml
--FILE--
<?php declare(strict_types=1);

namespace App\Providers;

use App\Services\MyService;
use Illuminate\Auth\AuthManager;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Session\Session as SessionContract;
use Illuminate\Cookie\CookieJar;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Session\SessionManager;
use Illuminate\Session\Store as SessionStore;
use Illuminate\Support\Facades\App;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

class AppServiceProvider extends ServiceProvider
{
    #[\Override]
    public function register(): void
    {
        // Bad. $app->make() of a request-scoped class inside singleton.
        $this->app->singleton(MyService::class, function (Application $app): MyService {
            return new MyService($app->make(Request::class));
        });

        // Bad. Array access with class-string.
        $this->app->singleton('svc.1', function (Application $app): MyService {
            return new MyService($app[Request::class]);
        });

        // Bad. Array access with string alias.
        $this->app->singleton('svc.2', function (Application $app): MyService {
            return new MyService($app['request']);
        });

        // Bad. $this->app inside closure (closure bound to ServiceProvider).
        $this->app->singleton('svc.3', function (): MyService {
            return new MyService($this->app->make(SessionStore::class));
        });

        // Bad. app() helper.
        $this->app->singleton('svc.4', function (): MyService {
            return new MyService(app(Authenticatable::class));
        });

        // Bad. resolve() helper.
        $this->app->singleton('svc.5', function (): MyService {
            return new MyService(resolve(AuthFactory::class));
        });

        // Bad. App facade.
        $this->app->singleton('svc.6', function (): MyService {
            return new MyService(App::make(Request::class));
        });

        // Bad. scoped() has the same caveat as singleton().
        $this->app->scoped('svc.7', function (Application $app): MyService {
            return new MyService($app->make(Request::class));
        });

        // Bad. singletonIf() (case-insensitive method name).
        $this->app->singletonIf('svc.8', function (Application $app): MyService {
            return new MyService($app->make(Request::class));
        });

        // Bad. scopedIf() (the fourth UNSAFE_METHOD_IDS entry).
        $this->app->scopedIf('svc.9', function (Application $app): MyService {
            return new MyService($app->make(Request::class));
        });

        // Bad. Arrow function form.
        $this->app->singleton(
            'svc.10',
            fn(Application $app): MyService => new MyService($app->make(Request::class)),
        );

        // Bad. makeWith resolver method (defined on the concrete Container, not on the
        // Application contract, so type the closure param accordingly).
        $this->app->singleton('svc.11', function (\Illuminate\Foundation\Application $app): MyService {
            return new MyService($app->makeWith(Request::class, []));
        });

        // Bad. PSR-11 get() resolver.
        $this->app->singleton('svc.12', function (Application $app): MyService {
            return new MyService($app->get(Request::class));
        });

        // Bad. Symfony Request (alias target of 'request').
        $this->app->singleton('svc.13', function (Application $app): MyService {
            return new MyService($app->make(SymfonyRequest::class));
        });

        // Bad. Chained app()->make(...) inside singleton (looksLikeContainer FuncCall branch).
        $this->app->singleton('svc.14', function (): MyService {
            return new MyService(app()->make(Request::class));
        });

        // Bad. Remaining request-scoped classes (coverage for REQUEST_SCOPED_CLASSES).
        $this->app->singleton('svc.15', function (Application $app): MyService {
            return new MyService($app->make(CookieJar::class));
        });
        $this->app->singleton('svc.16', function (Application $app): MyService {
            return new MyService($app->make(AuthManager::class));
        });
        $this->app->singleton('svc.17', function (Application $app): MyService {
            return new MyService($app->make(Guard::class));
        });
        $this->app->singleton('svc.18', function (Application $app): MyService {
            return new MyService($app->make(SessionManager::class));
        });
        $this->app->singleton('svc.19', function (Application $app): MyService {
            return new MyService($app->make(SessionContract::class));
        });
        $this->app->singleton('svc.20', function (Application $app): MyService {
            return new MyService($app->make(ConfigRepository::class));
        });
        $this->app->singleton('svc.21', function (Application $app): MyService {
            return new MyService($app->make(UrlGenerator::class));
        });
        $this->app->singleton('svc.22', function (Application $app): MyService {
            return new MyService($app->make(Redirector::class));
        });

        // Bad. Remaining aliases (coverage for REQUEST_SCOPED_ALIASES).
        $this->app->singleton('svc.23', function (Application $app): MyService {
            return new MyService($app['auth']);
        });
        $this->app->singleton('svc.24', function (Application $app): MyService {
            return new MyService($app['auth.driver']);
        });
        $this->app->singleton('svc.25', function (Application $app): MyService {
            return new MyService($app['session']);
        });
        $this->app->singleton('svc.26', function (Application $app): MyService {
            return new MyService($app['session.store']);
        });
        $this->app->singleton('svc.27', function (Application $app): MyService {
            return new MyService($app['cookie']);
        });
        $this->app->singleton('svc.28', function (Application $app): MyService {
            return new MyService($app['config']);
        });
        $this->app->singleton('svc.29', function (Application $app): MyService {
            return new MyService($app['url']);
        });
        $this->app->singleton('svc.30', function (Application $app): MyService {
            return new MyService($app['redirect']);
        });

        // Good. bind() re-executes per resolution, so request-scoped resolution is fine.
        $this->app->bind('svc.ok1', function (Application $app): MyService {
            return new MyService($app->make(Request::class));
        });

        // Good. bindIf() has the same per-resolution semantics as bind().
        $this->app->bindIf('svc.ok2', function (Application $app): MyService {
            return new MyService($app->make(Request::class));
        });

        // Good. singleton with no request-scoped resolution.
        $this->app->singleton('svc.ok3', function (): MyService {
            return new MyService(null);
        });

        // Good. singleton resolving a non-request-scoped class (regression guard
        // for REQUEST_SCOPED_CLASSES whitelist being consulted).
        $this->app->singleton('svc.ok4', function (Application $app): MyService {
            return new MyService($app->make(\Illuminate\Log\LogManager::class));
        });

        // Good. Nested closure body does NOT belong to the outer singleton's scope.
        // The outer singleton's closure returns another closure; the inner one runs
        // later on each invocation, not during the singleton resolve.
        $this->app->singleton('svc.ok5', function (Application $app): \Closure {
            return function () use ($app): Request {
                return $app->make(Request::class);
            };
        });

        // Good. Non-literal abstract is not detected (we only match literals).
        $class = Request::class;
        $this->app->singleton('svc.ok6', function (Application $app) use ($class): MyService {
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
OctaneIncompatibleBinding on line %d: Request-scoped 'Illuminate\Http\Request' resolved inside singleton() closure. State leaks across Octane requests. Use bind() or resolve at call site.
OctaneIncompatibleBinding on line %d: Request-scoped 'Illuminate\Http\Request' resolved inside singleton() closure. State leaks across Octane requests. Use bind() or resolve at call site.
OctaneIncompatibleBinding on line %d: Request-scoped 'request' resolved inside singleton() closure. State leaks across Octane requests. Use bind() or resolve at call site.
OctaneIncompatibleBinding on line %d: Request-scoped 'Illuminate\Session\Store' resolved inside singleton() closure. State leaks across Octane requests. Use bind() or resolve at call site.
OctaneIncompatibleBinding on line %d: Request-scoped 'Illuminate\Contracts\Auth\Authenticatable' resolved inside singleton() closure. State leaks across Octane requests. Use bind() or resolve at call site.
OctaneIncompatibleBinding on line %d: Request-scoped 'Illuminate\Contracts\Auth\Factory' resolved inside singleton() closure. State leaks across Octane requests. Use bind() or resolve at call site.
OctaneIncompatibleBinding on line %d: Request-scoped 'Illuminate\Http\Request' resolved inside singleton() closure. State leaks across Octane requests. Use bind() or resolve at call site.
OctaneIncompatibleBinding on line %d: Request-scoped 'Illuminate\Http\Request' resolved inside scoped() closure. State leaks across Octane requests. Use bind() or resolve at call site.
OctaneIncompatibleBinding on line %d: Request-scoped 'Illuminate\Http\Request' resolved inside singletonIf() closure. State leaks across Octane requests. Use bind() or resolve at call site.
OctaneIncompatibleBinding on line %d: Request-scoped 'Illuminate\Http\Request' resolved inside scopedIf() closure. State leaks across Octane requests. Use bind() or resolve at call site.
OctaneIncompatibleBinding on line %d: Request-scoped 'Illuminate\Http\Request' resolved inside singleton() closure. State leaks across Octane requests. Use bind() or resolve at call site.
OctaneIncompatibleBinding on line %d: Request-scoped 'Illuminate\Http\Request' resolved inside singleton() closure. State leaks across Octane requests. Use bind() or resolve at call site.
OctaneIncompatibleBinding on line %d: Request-scoped 'Illuminate\Http\Request' resolved inside singleton() closure. State leaks across Octane requests. Use bind() or resolve at call site.
OctaneIncompatibleBinding on line %d: Request-scoped 'Symfony\Component\HttpFoundation\Request' resolved inside singleton() closure. State leaks across Octane requests. Use bind() or resolve at call site.
OctaneIncompatibleBinding on line %d: Request-scoped 'Illuminate\Http\Request' resolved inside singleton() closure. State leaks across Octane requests. Use bind() or resolve at call site.
OctaneIncompatibleBinding on line %d: Request-scoped 'Illuminate\Cookie\CookieJar' resolved inside singleton() closure. State leaks across Octane requests. Use bind() or resolve at call site.
OctaneIncompatibleBinding on line %d: Request-scoped 'Illuminate\Auth\AuthManager' resolved inside singleton() closure. State leaks across Octane requests. Use bind() or resolve at call site.
OctaneIncompatibleBinding on line %d: Request-scoped 'Illuminate\Contracts\Auth\Guard' resolved inside singleton() closure. State leaks across Octane requests. Use bind() or resolve at call site.
OctaneIncompatibleBinding on line %d: Request-scoped 'Illuminate\Session\SessionManager' resolved inside singleton() closure. State leaks across Octane requests. Use bind() or resolve at call site.
OctaneIncompatibleBinding on line %d: Request-scoped 'Illuminate\Contracts\Session\Session' resolved inside singleton() closure. State leaks across Octane requests. Use bind() or resolve at call site.
OctaneIncompatibleBinding on line %d: Request-scoped 'Illuminate\Config\Repository' resolved inside singleton() closure. State leaks across Octane requests. Use bind() or resolve at call site.
OctaneIncompatibleBinding on line %d: Request-scoped 'Illuminate\Routing\UrlGenerator' resolved inside singleton() closure. State leaks across Octane requests. Use bind() or resolve at call site.
OctaneIncompatibleBinding on line %d: Request-scoped 'Illuminate\Routing\Redirector' resolved inside singleton() closure. State leaks across Octane requests. Use bind() or resolve at call site.
OctaneIncompatibleBinding on line %d: Request-scoped 'auth' resolved inside singleton() closure. State leaks across Octane requests. Use bind() or resolve at call site.
OctaneIncompatibleBinding on line %d: Request-scoped 'auth.driver' resolved inside singleton() closure. State leaks across Octane requests. Use bind() or resolve at call site.
OctaneIncompatibleBinding on line %d: Request-scoped 'session' resolved inside singleton() closure. State leaks across Octane requests. Use bind() or resolve at call site.
OctaneIncompatibleBinding on line %d: Request-scoped 'session.store' resolved inside singleton() closure. State leaks across Octane requests. Use bind() or resolve at call site.
OctaneIncompatibleBinding on line %d: Request-scoped 'cookie' resolved inside singleton() closure. State leaks across Octane requests. Use bind() or resolve at call site.
OctaneIncompatibleBinding on line %d: Request-scoped 'config' resolved inside singleton() closure. State leaks across Octane requests. Use bind() or resolve at call site.
OctaneIncompatibleBinding on line %d: Request-scoped 'url' resolved inside singleton() closure. State leaks across Octane requests. Use bind() or resolve at call site.
OctaneIncompatibleBinding on line %d: Request-scoped 'redirect' resolved inside singleton() closure. State leaks across Octane requests. Use bind() or resolve at call site.
