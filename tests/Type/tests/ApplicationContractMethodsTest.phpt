--FILE--
<?php declare(strict_types=1);

namespace App;

use Illuminate\Console\Command;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Concrete-only Application methods (isProduction/isLocal/environment*) must
 * resolve on contract-typed receivers without UndefinedInterfaceMethod (181).
 *
 * Command::$laravel and ServiceProvider::$app are both typed as the
 * Illuminate\Contracts\Foundation\Application contract, which does not declare
 * these methods — only the concrete Illuminate\Foundation\Application does.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1108
 */
class DatabasePrune extends Command
{
    /** @var string */
    protected $signature = 'db:prune';

    public function handle(): int
    {
        // Command::$laravel is the Foundation\Application contract.
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
        // ServiceProvider::$app is the Foundation\Application contract.
        $_isProduction = $this->app->isProduction();
        /** @psalm-check-type-exact $_isProduction = bool */

        $_file = $this->app->environmentFile();
        /** @psalm-check-type-exact $_file = string */

        // Methods carrying parameters resolve their signature too (no arg-count
        // false positives), proving the delegated signature keeps its params.
        $this->app->useEnvironmentPath('/var/www');
        $this->app->detectEnvironment(static fn(): string => 'production');
    }
}

/**
 * Same methods on a directly contract-typed receiver (e.g. app() widened to the
 * contract, or any property typed on the interface).
 */
function on_contract_receiver(Application $app): string
{
    $env = $app->detectEnvironment(static fn(): string => 'local');
    /** @psalm-check-type-exact $env = string */

    $filePath = $app->environmentFilePath();
    /** @psalm-check-type-exact $filePath = string */

    // Fluent setters carry their `@return $this` through the contract receiver.
    // Chaining off the result (rather than pinning the exact `&static`
    // intersection, which renders differently across Psalm versions) proves the
    // return is the receiver type and not `mixed`, engine-independently.
    $chained = $app->loadEnvironmentFrom('.env.testing')->isProduction();
    /** @psalm-check-type-exact $chained = bool */

    $app->useEnvironmentPath('/srv/app');
    $app->afterLoadingEnvironment(static function (): void {});

    return $env . $filePath . ($chained ? '' : '');
}
?>
--EXPECTF--
