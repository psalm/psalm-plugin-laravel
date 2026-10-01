--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Command\Command;

// Kernel::all() forwards Symfony's Application::all(), which keys commands by name.
// Laravel documents a bare `array` on the contract, the concrete kernel, and the facade.

/** @return array<string, Command> */
function contract_all(\Illuminate\Contracts\Console\Kernel $kernel): array
{
    /** @psalm-check-type-exact $commands = array<string, Command> */
    $commands = $kernel->all();

    return $commands;
}

/** @return array<string, Command> */
function concrete_all(\Illuminate\Foundation\Console\Kernel $kernel): array
{
    /** @psalm-check-type-exact $commands = array<string, Command> */
    $commands = $kernel->all();

    return $commands;
}

/** @return array<string, Command> */
function facade_all(): array
{
    /** @psalm-check-type-exact $commands = array<string, Command> */
    $commands = Artisan::all();

    return $commands;
}
?>
--EXPECTF--
