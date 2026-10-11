--FILE--
<?php declare(strict_types=1);

use Illuminate\Foundation\Console\Kernel;
use Illuminate\Support\Facades\Artisan;

// Instance form on the concrete Kernel: the callback is bound to a ClosureCommand too.
function kernel_command(Kernel $kernel): void
{
    $command = $kernel->command('inspire', function (): void {
        /** @psalm-check-type-exact $this = \Illuminate\Foundation\Console\ClosureCommand */
        $this->comment('x');
    });

    /** @psalm-check-type-exact $command = \Illuminate\Foundation\Console\ClosureCommand */
    $command->purpose('x');

    $kernel->command('inspire-arrow', fn (): \Illuminate\Foundation\Console\ClosureCommand => $this);
    $kernel->command(callback: function (): void {
        $this->info('x');
    }, signature: 'inspire-named');
}

// A static closure cannot be rebound at runtime, so $this stays an error.
function kernel_command_static(Kernel $kernel): void
{
    $kernel->command('inspire-static', static function (): void {
        $this->comment('x');
    });
}

// Named arguments in swapped order on the facade.
Artisan::command(callback: function (): void {
    /** @psalm-check-type-exact $this = \Illuminate\Foundation\Console\ClosureCommand */
    $this->comment('x');
}, signature: 'inspire-swapped');
?>
--EXPECTF--
InvalidScope on line %d: Invalid reference to $this in a static context
