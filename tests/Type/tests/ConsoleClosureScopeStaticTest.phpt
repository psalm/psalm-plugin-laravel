--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

// The handler only rebinds non-static closures. A `static` closure cannot be
// rebound at runtime, so it is left untouched and Psalm still reports $this as
// out of scope — a regression guard that the fix does not over-reach to static
// closures.
Artisan::command('inspire', static function (): void {
    $this->comment('x');
});
?>
--EXPECTF--
InvalidScope on line %d: Invalid reference to $this in a static context
