--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

// static closures (function + fn) can't be rebound → $this still errors
// (@param-closure-this does not apply to static closures, matching runtime).
Artisan::command('inspire', static function (): void {
    $this->comment('x');
});

Artisan::command('inspire-fn', static fn (): mixed => $this->comment('x'));
?>
--EXPECTF--
InvalidScope on line %d: Invalid reference to $this in a static context
InvalidScope on line %d: Invalid reference to $this in a static context
