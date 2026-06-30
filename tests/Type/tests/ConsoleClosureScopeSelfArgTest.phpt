--FILE--
<?php declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\ServiceProvider;

// Regression: the $self override is scoped to the callback closure, NOT the whole
// Artisan::command() call — so self::/static:: in the *signature* argument resolve
// against the enclosing class, not ClosureCommand (would be UndefinedMethod).
class ConsoleSelfArgProvider extends ServiceProvider
{
    public function boot(): void
    {
        Artisan::command(self::sig(), function (): void {
            /** @psalm-check-type-exact $this = \Illuminate\Foundation\Console\ClosureCommand&static */
            $this->comment('x');
        });

        Artisan::command(static::sig(), function (): void {
            $this->comment('y');
        });
    }

    private static function sig(): string
    {
        return 'do:thing';
    }
}
?>
--EXPECTF--
