--FILE--
<?php declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\ServiceProvider;

// Artisan::command() called from inside a class method (a common place to
// register closure commands). $this is rebound to ClosureCommand inside the
// callback, then restored to the enclosing class once the call returns.
class ConsoleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Artisan::command('inspire', function (): void {
            /** @psalm-check-type-exact $this = \Illuminate\Foundation\Console\ClosureCommand&static */
            $this->comment('x');
        });

        // self restored: $this is the provider again, not ClosureCommand.
        /** @psalm-check-type-exact $this = \App\Providers\ConsoleServiceProvider&static */
        $this->commands([]);
    }
}
?>
--EXPECTF--
