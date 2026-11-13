--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Foundation\Console\Kernel;

// $this is the bound ClosureCommand inside routes/console.php callbacks. This file is not the app's
// routes/console.php, so its CLI-only `purpose()` calls are reported (tests/Application covers that file).
Artisan::command('inspire', function (): void {
    /** @psalm-check-type-exact $this = \Illuminate\Foundation\Console\ClosureCommand */
    $this->comment('Display an inspiring quote');
    $this->info('done');
    // unknown signature (non-global names) → no false InvalidConsole*Name
    $this->argument('target');
    $this->option('frequency');
})->purpose('Display an inspiring quote');

// arrow fn: declared return proves $this is the bound ClosureCommand (a wrong
// or mixed $this would trip InvalidReturnStatement here).
Artisan::command('inspire-arrow', fn (): \Illuminate\Foundation\Console\ClosureCommand => $this);

// \Artisan global alias
\Artisan::command('inspire-alias', function (): void {
    /** @psalm-check-type-exact $this = \Illuminate\Foundation\Console\ClosureCommand */
    $this->comment('x');
});

// named-argument form: callback identified by name, not position
Artisan::command(signature: 'inspire-named', callback: function (): void {
    /** @psalm-check-type-exact $this = \Illuminate\Foundation\Console\ClosureCommand */
    $this->comment('x');
});

// first-class callable: no callback argument to bind, the signature stays intact
$factory = Artisan::command(...);
/** @psalm-check-type-exact $factory = \Closure(string, \Closure):\Illuminate\Foundation\Console\ClosureCommand */
$factory('first-class', static function (): void {});

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

// static closures (function + fn) can't be rebound → $this still errors
// (@param-closure-this does not apply to static closures, matching runtime).
Artisan::command('inspire', static function (): void {
    $this->comment('x');
});

Artisan::command('inspire-fn', static fn (): mixed => $this->comment('x'));
?>
--EXPECTF--
CliOnlyCall on line %d: The method Illuminate\Foundation\Console\ClosureCommand::purpose() only runs from the command line (@psalm-cli-only), but this code may run outside of it: mark it @psalm-cli-only too, or check that PHP_SAPI === 'cli' first
CliOnlyCall on line %d: The method Illuminate\Foundation\Console\ClosureCommand::purpose() only runs from the command line (@psalm-cli-only), but this code may run outside of it: mark it @psalm-cli-only too, or check that PHP_SAPI === 'cli' first
InvalidScope on line %d: Invalid reference to $this in a static context
InvalidScope on line %d: Invalid reference to $this in a static context
InvalidScope on line %d: Invalid reference to $this in a static context
