--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm-with-optin-custom-issues.xml
--FILE--
<?php declare(strict_types=1);

namespace App\Providers;

use App\Models\Customer;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\ServiceProvider;

/**
 * A first-class callable (`foo(...)`) has no argument list: php-parser's CallLike::getArgs()
 * asserts against it, so with zend.assertions=1 every handler reading call args must decline
 * on it first. https://github.com/psalm/psalm-plugin-laravel/issues/1657
 */

// UndefinedModelRelationHandler: `has` is a relation-name method, on a non-model receiver
// (the issue's shape) and on a real Eloquent builder / static model call.
final class SkuStorage
{
    public function has(string $sku): bool
    {
        return $sku !== '';
    }
}

final class Importer
{
    public function __construct(private SkuStorage $skuStorage) {}

    public function skuChecker(): \Closure
    {
        return $this->skuStorage->has(...);
    }
}

function builder_has(): \Closure
{
    return Customer::query()->has(...);
}

function static_with(): \Closure
{
    return Customer::with(...);
}

// Artisan::command() has no callback argument to bind `$this` to when taken as a first-class callable.
function artisan_command(): \Closure
{
    return Artisan::command(...);
}

// DispatchableHandler: constructor-args check on Job::dispatch().
final class FirstClassCallableJob
{
    use Dispatchable;

    public function __construct(public string $subject) {}

    public function handle(): void {}
}

function job_dispatch(): \Closure
{
    return FirstClassCallableJob::dispatch(...);
}

// InlineValidateRulesCollector: validate() rules and the keyed-accessor assignment RHS.
function request_validate(Request $request): \Closure
{
    return $request->validate(...);
}

function request_input_after_validate(Request $request): \Closure
{
    $request->validate(['name' => 'required|string']);
    $input = $request->input(...);

    return $input;
}

// OctaneIncompatibleBindingHandler: resolutions scanned inside a singleton closure.
final class FirstClassCallableServiceProvider extends ServiceProvider
{
    #[\Override]
    public function register(): void
    {
        $this->app->singleton('fcc.make', static fn(Application $app): \Closure => $app->make(...));
        $this->app->singleton('fcc.helper', static fn(): \Closure => app(...));
        $this->app->singleton('fcc.facade', static fn(): \Closure => App::make(...));
        // The container check on a `make()` receiver also reads `app(...)` args. The chain is
        // ill-typed (Closure has no make()), but the rule walks the closure AST regardless.
        $this->app->singleton('fcc.chain', static fn(): mixed => app(...)->make('fcc.chain'));
    }
}
?>
--EXPECTF--
UndefinedMethod on line %d: Method Closure::make does not exist
