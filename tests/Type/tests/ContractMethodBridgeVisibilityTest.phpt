--FILE--
<?php declare(strict_types=1);

namespace App;

use Illuminate\Contracts\Foundation\Application;

/**
 * Guard: public surface only. markAsRegistered() is protected on the concrete and
 * absent from the contract, so it must still raise UndefinedInterfaceMethod —
 * the visibility filter must not expose protected/private methods (which would
 * surface as a misleading InaccessibleMethod). #1108.
 */
function visibility_guard(Application $app): void
{
    $app->markAsRegistered(new \stdClass());
}
?>
--EXPECTF--
UndefinedInterfaceMethod on line %d: Method Illuminate\Contracts\Foundation\Application::markAsRegistered does not exist
