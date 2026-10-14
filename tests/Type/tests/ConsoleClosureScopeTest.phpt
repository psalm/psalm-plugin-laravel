--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

// $this is the bound ClosureCommand inside routes/console.php callbacks.
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
?>
--EXPECTF--
