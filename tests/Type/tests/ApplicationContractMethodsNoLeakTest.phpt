--FILE--
<?php declare(strict_types=1);

namespace App;

use Illuminate\Contracts\Foundation\Application;

/**
 * Guard: the concrete-only-method injection stays scoped to its allow-list. A
 * method that is neither on the contract nor in ApplicationContractMethodHandler's
 * list must STILL raise UndefinedInterfaceMethod on a contract-typed receiver —
 * otherwise the contract would silently accept arbitrary undefined methods.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1108
 */
function no_leak(Application $app): void
{
    $app->thisMethodDoesNotExist();
}
?>
--EXPECTF--
UndefinedInterfaceMethod on line %d: Method Illuminate\Contracts\Foundation\Application::thisMethodDoesNotExist does not exist
