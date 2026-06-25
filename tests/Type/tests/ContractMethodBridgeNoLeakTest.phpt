--FILE--
<?php declare(strict_types=1);

namespace App;

use Illuminate\Contracts\Foundation\Application;

/**
 * Guard: a method on neither contract nor concrete still raises
 * UndefinedInterfaceMethod. Also pins the magic-method skip — if __call were
 * bridged (Foundation\Application has it via Macroable) this would route through
 * the magic path and the error would vanish. #1108.
 */
function no_leak(Application $app): void
{
    $app->thisMethodDoesNotExist();
}
?>
--EXPECTF--
UndefinedInterfaceMethod on line %d: Method Illuminate\Contracts\Foundation\Application::thisMethodDoesNotExist does not exist
