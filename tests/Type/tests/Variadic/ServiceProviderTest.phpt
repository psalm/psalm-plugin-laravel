--FILE--
<?php declare(strict_types=1);

namespace App;

use Illuminate\Console\Command;
use Illuminate\Support\ServiceProvider;

class InspectCommand extends Command {}
class ReportCommand extends Command {}

/**
 * ServiceProvider::commands() accepts an array of class-strings or variadic
 * class-string arguments via func_get_args(). Without @psalm-variadic the
 * multi-arg calls would be rejected as TooManyArguments.
 */
function service_provider_commands_variadic(ServiceProvider $provider): void
{
    $provider->commands(InspectCommand::class);

    $provider->commands(InspectCommand::class, ReportCommand::class);

    $provider->commands([InspectCommand::class, ReportCommand::class]);
}

/**
 * Concrete service providers are the typical call site: `$this->commands(...)`
 * inside `boot()` or `register()`. Verify the variadic annotation propagates
 * through subclassing — Psalm resolves the method on the declaring class.
 */
final class ExampleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->commands(InspectCommand::class, ReportCommand::class);
    }
}
?>
--EXPECTF--
