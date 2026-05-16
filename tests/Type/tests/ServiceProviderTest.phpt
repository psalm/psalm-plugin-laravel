--FILE--
<?php declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class ExampleServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     * @return void
     */
    public function boot()
    {
        //
    }
}

/**
 * Smoke fixture for psalm/psalm-plugin-laravel#943 — exercises every binding shape
 * `ContainerBoundConstructorHandler` covers. The injected `__construct` body is the
 * documented Laravel pattern used by packages that subclass EventServiceProvider /
 * AuthServiceProvider / RouteServiceProvider; it also exercises the Item-4
 * `Illuminate\Support\ServiceProvider::__construct` parent-class suppression in
 * SuppressHandler.
 *
 * Becomes a hard regression guard once #869 enables findUnusedCode in type tests.
 */
class BindingServiceProvider extends ServiceProvider
{
    public function __construct(\Illuminate\Contracts\Foundation\Application $app)
    {
        parent::__construct($app);
    }

    #[\Override]
    public function register(): void
    {
        // Two-arg form: ContainerBoundConstructorHandler records a synthetic ref
        // to the concrete `__construct` from this method.
        $this->app->bind(BoundContract::class, BoundConcreteService::class);
        $this->app->singleton(SingletonContract::class, SingletonConcreteService::class);
        $this->app->scoped(ScopedContract::class, ScopedConcreteService::class);

        // Single-arg form: bind(Concrete::class) — Concrete is both abstract and concrete.
        $this->app->bind(StandaloneService::class);
        $this->app->bindIf(StandaloneServiceIf::class);

        // Closure-as-second-arg: the body holds its own `new` expression, so Psalm's
        // natural reference tracking handles the constructor without our help. The
        // handler is intentionally a no-op for this shape.
        $this->app->singleton(ClosureBoundContract::class, function (): ClosureBoundService {
            return new ClosureBoundService();
        });

        // commands(): registered classes extend Console\Command, which is covered
        // by METHOD_LEVEL_BY_PARENT_CLASS for `__construct` + `handle`.
        $this->commands([
            \App\Console\Commands\ExampleRegisteredCommand::class,
        ]);

        // register(): the nested provider extends ServiceProvider, so its
        // `__construct` is suppressed by the parent-class rule that protects
        // every ServiceProvider subclass.
        $this->app->register(NestedServiceProvider::class);

        // Contextual binding: ContextualBindingBuilder::give(Concrete::class) reaches
        // Concrete's `__construct` through Container::build() reflection, same as
        // the direct bind() family.
        $this->app->when(BoundContract::class)
            ->needs(StandaloneService::class)
            ->give(ContextualConcreteService::class);

        // Variadic contextual binding: give([C1::class, C2::class]) — Laravel's
        // documented pattern for typed-variadic injection. Each concrete reaches
        // `Container::build()` via `resolveVariadicClass()`.
        $this->app->when(BoundContract::class)
            ->needs(SingletonContract::class)
            ->give([VariadicFilterA::class, VariadicFilterB::class]);
    }
}

class NestedServiceProvider extends ServiceProvider
{
    #[\Override]
    public function register(): void
    {
    }
}

interface BoundContract {}
interface SingletonContract {}
interface ScopedContract {}
interface ClosureBoundContract {}

class BoundConcreteService implements BoundContract
{
    public function __construct(public readonly \Illuminate\Http\Request $request)
    {
    }
}

class SingletonConcreteService implements SingletonContract
{
    public function __construct(public readonly \Illuminate\Contracts\Config\Repository $config)
    {
    }
}

class ScopedConcreteService implements ScopedContract
{
    public function __construct()
    {
    }
}

class StandaloneService
{
    public function __construct(public readonly \Illuminate\Contracts\Config\Repository $config)
    {
    }
}

class StandaloneServiceIf
{
    public function __construct()
    {
    }
}

class ClosureBoundService implements ClosureBoundContract
{
    public function __construct()
    {
    }
}

class ContextualConcreteService
{
    public function __construct(public readonly \Illuminate\Contracts\Config\Repository $config)
    {
    }
}

class VariadicFilterA
{
    public function __construct(public readonly \Illuminate\Http\Request $request)
    {
    }
}

class VariadicFilterB
{
    public function __construct()
    {
    }
}

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Registered through `ServiceProvider::commands([...])`. Suppressed under
 * METHOD_LEVEL_BY_PARENT_CLASS for `__construct` because Console\Kernel resolves
 * the FQCN through the container, never as a literal `new` in user code.
 */
class ExampleRegisteredCommand extends Command
{
    /** @var string */
    protected $signature = 'app:registered-via-commands';

    public function __construct(public readonly \Illuminate\Contracts\Config\Repository $config)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        return 0;
    }
}
?>
--EXPECTF--
