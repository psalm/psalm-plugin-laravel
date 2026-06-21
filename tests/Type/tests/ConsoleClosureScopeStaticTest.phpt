--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

// static closure can't be rebound → $this still errors (handler stays out).
Artisan::command('inspire', static function (): void {
    $this->comment('x');
});
?>
--EXPECTF--
InvalidScope on line %d: Invalid reference to $this in a static context
