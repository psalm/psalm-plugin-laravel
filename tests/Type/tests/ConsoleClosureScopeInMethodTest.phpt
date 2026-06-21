--FILE--
<?php declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\ServiceProvider;

// Called inside a method: $this is ClosureCommand in the callback, restored after.
class ConsoleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Artisan::command('inspire', function (): void {
            /** @psalm-check-type-exact $this = \Illuminate\Foundation\Console\ClosureCommand&static */
            $this->comment('x');
        });

        // restored
        /** @psalm-check-type-exact $this = \App\Providers\ConsoleServiceProvider&static */
        $this->commands([]);
    }
}
?>
--EXPECTF--
