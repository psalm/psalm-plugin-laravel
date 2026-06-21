--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

// $this is the bound ClosureCommand inside routes/console.php callbacks.
Artisan::command('inspire', function (): void {
    /** @psalm-check-type-exact $this = Illuminate\Foundation\Console\ClosureCommand&static */
    $this->comment('Display an inspiring quote');
    $this->info('done');
    // unknown signature → no false InvalidConsole*Name
    $this->argument('name');
    $this->option('verbose');
})->purpose('Display an inspiring quote');

// arrow fn
Artisan::command('inspire-arrow', fn () => $this->comment('x'));

// \Artisan global alias
\Artisan::command('inspire-alias', function (): void {
    /** @psalm-check-type-exact $this = Illuminate\Foundation\Console\ClosureCommand&static */
    $this->comment('x');
});
?>
--EXPECTF--
