--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

// Canonical routes/console.php pattern. Laravel rebinds the callback to a
// ClosureCommand instance at runtime, so $this->comment()/info()/etc.
// (mixed in from Illuminate\Console\Command) are valid — no InvalidScope.
Artisan::command('inspire', function (): void {
    /** @psalm-check-type-exact $this = Illuminate\Foundation\Console\ClosureCommand&static */
    $this->comment('Display an inspiring quote');
    $this->info('done');

    // argument()/option() resolve against ClosureCommand, whose signature is
    // not statically known here — no false InvalidConsoleArgumentName /
    // InvalidConsoleOptionName, just the declared fallback return type.
    $this->argument('name');
    $this->option('verbose');
})->purpose('Display an inspiring quote');

// Arrow-function form binds $this the same way.
Artisan::command('inspire-arrow', fn () => $this->comment('x'));

// The generated `\Artisan` global alias (extends the facade) is covered too.
\Artisan::command('inspire-alias', function (): void {
    /** @psalm-check-type-exact $this = Illuminate\Foundation\Console\ClosureCommand&static */
    $this->comment('x');
});
?>
--EXPECTF--
