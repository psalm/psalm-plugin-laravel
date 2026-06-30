--FILE--
<?php declare(strict_types=1);

namespace App;

use Illuminate\Console\Command;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Concrete-only Application methods resolve on contract-typed receivers without
 * UndefinedInterfaceMethod (181), with return types and params preserved.
 * Command::$laravel / ServiceProvider::$app / a contract param are all typed on
 * the Contracts\Foundation\Application interface, which declares none of these.
 * #1108.
 */
class DatabasePrune extends Command
{
    /** @var string */
    protected $signature = 'db:prune';

    public function handle(): int
    {
        // Command::$laravel is the contract.
        $_isProduction = $this->laravel->isProduction();
        /** @psalm-check-type-exact $_isProduction = bool */

        $_isLocal = $this->laravel->isLocal();
        /** @psalm-check-type-exact $_isLocal = bool */

        $_path = $this->laravel->environmentPath();
        /** @psalm-check-type-exact $_path = string */

        return 0;
    }
}

class PruneServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // ServiceProvider::$app is the contract.
        $_isProduction = $this->app->isProduction();
        /** @psalm-check-type-exact $_isProduction = bool */

        $_file = $this->app->environmentFile();
        /** @psalm-check-type-exact $_file = string */

        // Param-carrying methods keep their signature (no arg-count false positives).
        $this->app->useEnvironmentPath('/var/www');
        $this->app->detectEnvironment(static fn(): string => 'production');
    }
}

function on_contract_receiver(Application $app): string
{
    $env = $app->detectEnvironment(static fn(): string => 'local');
    /** @psalm-check-type-exact $env = string */

    $filePath = $app->environmentFilePath();
    /** @psalm-check-type-exact $filePath = string */

    // Fluent `@return $this` survives the bridge: chain instead of pinning the
    // version-dependent `&static` intersection.
    $chained = $app->loadEnvironmentFrom('.env.testing')->isProduction();
    /** @psalm-check-type-exact $chained = bool */

    $app->useEnvironmentPath('/srv/app');
    $app->afterLoadingEnvironment(static function (): void {});

    // Generality: every public concrete-only method is bridged, not a fixed list.
    // path() is concrete-only too (#1130's out-of-scope sibling) and resolves here;
    // not pinned because the plugin's path helpers narrow it to the resolved path.
    $appPath = $app->path('routes');

    return $env . $filePath . $appPath . ($chained ? '' : '');
}
?>
--EXPECTF--
