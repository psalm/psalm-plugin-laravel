--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Command\Command;

// Kernel::all() forwards Symfony's Application::all(), which keys commands by name
// (a numeric name becomes an int key).
// Laravel documents a bare `array` on the contract, the concrete kernel, and the facade.

/** @return array<array-key, Command> */
function contract_all(\Illuminate\Contracts\Console\Kernel $kernel): array
{
    /** @psalm-check-type-exact $commands = array<array-key, Command> */
    $commands = $kernel->all();

    return $commands;
}

/** @return array<array-key, Command> */
function concrete_all(\Illuminate\Foundation\Console\Kernel $kernel): array
{
    /** @psalm-check-type-exact $commands = array<array-key, Command> */
    $commands = $kernel->all();

    return $commands;
}

/** @return array<array-key, Command> */
function facade_all(): array
{
    /** @psalm-check-type-exact $commands = array<array-key, Command> */
    $commands = Artisan::all();

    return $commands;
}
?>
--EXPECTF--
